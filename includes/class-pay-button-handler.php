<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Pay Button Handler
 *
 * Hides the WooCommerce "Pay" button for Briqpay orders that have already been
 * paid via the Briqpay iframe but are awaiting webhook confirmation. Without this,
 * customers see a misleading "Pay" button on the order-received (thank you) page
 * and in My Account > Orders while the order is in "pending payment" status.
 */
class Pay_Button_Handler
{

    /**
     * Init hooks.
     */
    public function init()
    {
        // Prevent WooCommerce from treating Briqpay orders as "needing payment"
        add_filter('woocommerce_order_needs_payment', array($this, 'filter_order_needs_payment'), 10, 3);

        // Remove the "Pay" action link from the My Account > Orders table
        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'filter_my_orders_actions'), 10, 2);

        // Block access to the order-pay endpoint for Briqpay orders
        add_action('before_woocommerce_pay', array($this, 'block_order_pay_page'));
    }

    /**
     * Filter whether an order needs payment.
     *
     * WooCommerce shows a "Pay" button when needs_payment() returns true (pending/failed status).
     * For Briqpay orders, the customer has already paid via the iframe — we are just waiting
     * for the webhook to confirm the payment. So we return false to hide the button.
     *
     * @param bool     $needs_payment Whether the order needs payment.
     * @param WC_Order $order         The order object.
     * @param array    $valid_statuses Statuses considered as needing payment.
     * @return bool
     */
    public function filter_order_needs_payment($needs_payment, $order, $valid_statuses)
    {
        if (!$needs_payment) {
            return false;
        }

        if ($this->is_briqpay_order_awaiting_webhook($order)) {
            return false;
        }

        return $needs_payment;
    }

    /**
     * Remove the "Pay" action from My Account > Orders for Briqpay orders awaiting webhooks.
     *
     * @param array    $actions The order actions.
     * @param WC_Order $order   The order object.
     * @return array
     */
    public function filter_my_orders_actions($actions, $order)
    {
        if (isset($actions['pay']) && $this->is_briqpay_order_awaiting_webhook($order)) {
            unset($actions['pay']);
        }

        return $actions;
    }

    /**
     * Block access to the order-pay page for Briqpay orders that are awaiting webhooks.
     *
     * If a customer directly navigates to the pay URL for a Briqpay order that has already
     * been paid, redirect them to the My Account > Orders page instead.
     */
    public function block_order_pay_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check, no state mutation.
        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ($this->is_briqpay_order_awaiting_webhook($order)) {
            wc_add_notice(
                __('This order has already been paid. We are processing your payment.', 'briqpay-for-woocommerce'),
                'notice'
            );
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }

    /**
     * Check if an order is a Briqpay order that is awaiting webhook confirmation.
     *
     * An order is considered "awaiting webhook" if:
     * 1. Its payment method is 'briqpay'
     * 2. It has a Briqpay session ID stored (meaning the iframe checkout was completed)
     * 3. It is in 'pending' status (webhook hasn't confirmed the payment yet)
     *
     * @param WC_Order $order The order to check.
     * @return bool
     */
    public function is_briqpay_order_awaiting_webhook($order)
    {
        if ('briqpay' !== $order->get_payment_method()) {
            return false;
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        if (empty($session_id)) {
            return false;
        }

        // Only hide the pay button for pending orders — these are the ones awaiting webhook
        if ($order->has_status('pending')) {
            return true;
        }

        return false;
    }
}
