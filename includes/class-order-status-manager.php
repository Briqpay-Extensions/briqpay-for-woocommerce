<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Order Status Manager
 */
class Order_Status_Manager
{

    /**
     * Init
     */
    public function init()
    {
        add_action('init', array($this, 'register_temp_status'));
        add_filter('wc_order_statuses', array($this, 'add_temp_status_to_list'));
        add_filter('woocommerce_reports_order_statuses', array($this, 'add_temp_status_to_reports'));

        // Hide from admin order list
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            add_filter('woocommerce_order_list_table_prepare_items_query_args', array($this, 'hpos_hide_temp_orders_from_admin'));
        } else {
            add_action('pre_get_posts', array($this, 'hide_temp_orders_from_admin'));
        }

        add_action('briqpay_v3_janitor_cleanup', array($this, 'janitor_cleanup_task'));
        add_action('briqpay_cleanup_temp_orders', array($this, 'cleanup_temp_orders'));
    }

    /**
     * Hide from admin order list (HPOS version)
     */
    public function hpos_hide_temp_orders_from_admin($query_args)
    {
        if (!is_admin()) {
            return $query_args;
        }

        $status = $query_args['status'] ?? '';
        if (empty($status) || 'all' === $status) {
            $all_statuses = array_keys(wc_get_order_statuses());
            $all_statuses = array_diff($all_statuses, array('wc-briqpay-temp'));
            $hpos_statuses = array_map(function($s) {
                return str_replace('wc-', '', $s);
            }, $all_statuses);
            $query_args['status'] = $hpos_statuses;
        }

        return $query_args;
    }

    /**
     * Schedule all background/cron events.
     * Called on plugin activation.
     */
    public static function schedule_events()
    {
        if (function_exists('as_next_scheduled_action') && !as_next_scheduled_action('briqpay_v3_janitor_cleanup')) {
            as_schedule_recurring_action(time(), 1800, 'briqpay_v3_janitor_cleanup', array(), 'briqpay');
        }

        if (!wp_next_scheduled('briqpay_cleanup_temp_orders')) {
            wp_schedule_event(time(), 'daily', 'briqpay_cleanup_temp_orders');
        }
    }

    /**
     * Unschedule all background/cron events.
     * Called on plugin deactivation.
     */
    public static function unschedule_events()
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('briqpay_v3_janitor_cleanup', array(), 'briqpay');
        }

        $timestamp = wp_next_scheduled('briqpay_cleanup_temp_orders');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'briqpay_cleanup_temp_orders');
        }
    }

    /**
     * Register Temporary Status
     */
    public function register_temp_status()
    {
        register_post_status('wc-briqpay-temp', array(
            'label' => _x('Briqpay Temporary', 'Order status', 'briqpay-for-woocommerce'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => false,
            'show_in_admin_status_list' => false,
            // translators: %s: number of orders
            'label_count' => _n_noop('Briqpay Temporary <span class="count">(%s)</span>', 'Briqpay Temporary <span class="count">(%s)</span>', 'briqpay-for-woocommerce'),
        ));
    }

    /**
     * Add to status list
     */
    public function add_temp_status_to_list($statuses)
    {
        $statuses['wc-briqpay-temp'] = _x('Briqpay Temporary', 'Order status', 'briqpay-for-woocommerce');
        return $statuses;
    }

    /**
     * Add to reports
     */
    public function add_temp_status_to_reports($statuses)
    {
        return $statuses;
    }

    /**
     * Hide from admin order list
     */
    public function hide_temp_orders_from_admin($query)
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
            return;
        }

        $status = $query->get('post_status');
        if (empty($status) || 'all' === $status) {
            $all_statuses = wc_get_order_statuses();
            unset($all_statuses['wc-briqpay-temp']);
            $query->set('post_status', array_keys($all_statuses));
        }
    }

    /**
     * Janitor Cleanup Task
     * Runs every 30 minutes to clean up pending orders older than 5 hours.
     */
    public function janitor_cleanup_task()
    {
        Logger::log('Janitor: Starting cleanup task...');

        $threshold_hours = 5;
        $threshold_time = time() - ($threshold_hours * HOUR_IN_SECONDS);

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $args = array(
            'status' => 'pending',
            'meta_key' => '_briqpay_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'date_created' => '<' . $threshold_time,
            'limit' => 50, // Process in batches
        );

        $orders = wc_get_orders($args);

        if (empty($orders)) {
            Logger::log('Janitor: No stagnant orders found.');
            return;
        }

        Logger::log(sprintf('Janitor: Found %d stagnant orders.', count($orders)));

        foreach ($orders as $order) {
            Logger::log('Janitor: Processing order ' . $order->get_id());

            // Double check session status via API before cancelling
            $session_id = $order->get_meta('_briqpay_session_id');
            $settings = get_option('woocommerce_briqpay_settings');
            $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
            $session = $api->get_session($session_id);

            if (!is_wp_error($session) && isset($session['status'])) {
                if ($session['status'] === 'completed') {
                    Logger::log('Janitor: Session actually completed. Moving to processing instead of cancelling.');
                    $order->update_status('processing', __('Briqpay Janitor: Recovered completed session.', 'briqpay-for-woocommerce'));
                    continue;
                }
            }

            $order->update_status('cancelled', __('Briqpay Janitor: Order cancelled due to inactivity (5h threshold).', 'briqpay-for-woocommerce'));
            Logger::log('Janitor: Order ' . $order->get_id() . ' cancelled.');
        }

        Logger::log('Janitor: Cleanup task finished.');
    }


    /**
     * Cleanup old temp orders
     */
    public function cleanup_temp_orders()
    {
        $args = array(
            'status' => 'wc-briqpay-temp',
            'date_created' => '<' . (time() - 2 * DAY_IN_SECONDS),
            'limit' => 50,
            'return' => 'ids',
        );
        $order_ids = wc_get_orders($args);
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->delete(true);
            }
        }
    }
}
