<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Admin Order Meta Box
 */
class Admin_Order_Meta_Box
{
    /**
     * Initialize the class
     */
    public function init()
    {
        add_action('add_meta_boxes', array($this, 'add_briqpay_meta_box'));
        add_action('wp_ajax_briqpay_capture_items', array($this, 'ajax_capture_items'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    /**
     * Admin Scripts
     */
    public function admin_scripts($hook)
    {
        if ('post.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }

        $order_id = 0;
        if ('post.php' === $hook) {
            if (isset($_GET['post']) && isset($_GET['action']) && 'edit' === $_GET['action']) {
                $order_id = (int) $_GET['post'];
            }
        } elseif ('woocommerce_page_wc-orders' === $hook) {
            if (isset($_GET['id'])) {
                $order_id = (int) $_GET['id'];
            }
        }

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || 'briqpay' !== $order->get_payment_method()) {
            return;
        }

        wp_enqueue_script('briqpay-admin', BRIQPAY_WC_URL . 'assets/js/admin.js', array('jquery'), BRIQPAY_WC_VERSION, true);
        wp_enqueue_style('briqpay-admin', BRIQPAY_WC_URL . 'assets/css/admin.css', array(), BRIQPAY_WC_VERSION);
        wp_localize_script('briqpay-admin', 'briqpayAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_admin_nonce'),
        ));
    }

    /**
     * Add Meta Box
     */
    public function add_briqpay_meta_box()
    {
        $screen = get_current_screen();
        if ($screen && ('shop_order' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id)) {
            add_meta_box(
                'briqpay_order_details',
                __('Briqpay Payment Details', 'briqpay-for-woocommerce'),
                array($this, 'render_meta_box'),
                $screen->id,
                'side',
                'high'
            );
        }
    }

    /**
     * Render Meta Box
     */
    public function render_meta_box($post_or_order)
    {
        $order = ($post_or_order instanceof \WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order || 'briqpay' !== $order->get_payment_method()) {
            echo '<p>' . esc_html__('Not a Briqpay order.', 'briqpay-for-woocommerce') . '</p>';
            return;
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        $psp_name = $order->get_meta('_briqpay_psp_name');
        $integration_name = $order->get_meta('_briqpay_psp_integration_name');
        $reservation_id = $order->get_meta('_briqpay_reservation_id');
        $client_token = $order->get_meta('_briqpay_client_token');

        $backoffice_url = $this->get_backoffice_url($session_id, $client_token);

        ?>
        <div class="briqpay-admin-meta-box">
            <p>
                <strong><?php esc_html_e('Session ID:', 'briqpay-for-woocommerce'); ?></strong><br>
                <?php if ($backoffice_url): ?>
                    <a href="<?php echo esc_url($backoffice_url); ?>" target="_blank"
                        title="<?php esc_attr_e('Open in Briqpay Backoffice', 'briqpay-for-woocommerce'); ?>">
                        <?php echo esc_html($session_id); ?> <span class="dashicons dashicons-external"></span>
                    </a>
                <?php else: ?>
                    <?php echo esc_html($session_id); ?>
                <?php endif; ?>
            </p>
            <p>
                <strong><?php esc_html_e('PSP Name:', 'briqpay-for-woocommerce'); ?></strong><br>
                <?php echo esc_html($psp_name ?: __('N/A', 'briqpay-for-woocommerce')); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Integration:', 'briqpay-for-woocommerce'); ?></strong><br>
                <?php echo esc_html($integration_name ?: __('N/A', 'briqpay-for-woocommerce')); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Reservation ID:', 'briqpay-for-woocommerce'); ?></strong><br>
                <?php echo esc_html($reservation_id ?: __('N/A', 'briqpay-for-woocommerce')); ?>
            </p>

            <hr>

            <!-- Captures Section -->
            <div class="briqpay-captures-section">
                <h4><?php esc_html_e('Captures', 'briqpay-for-woocommerce'); ?></h4>
                <?php $this->render_captures_list($order); ?>
                <?php $this->render_capture_form($order); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Captures List
     */
    private function render_captures_list($order)
    {
        $history = $order->get_meta('_briqpay_capture_history') ?: array();
        if (empty($history)) {
            echo '<p><i>' . esc_html__('No captures found.', 'briqpay-for-woocommerce') . '</i></p>';
            return;
        }

        foreach (array_reverse($history) as $capture) {
            ?>
            <div class="briqpay-capture-item">
                <strong>ID: <?php echo esc_html($capture['captureId']); ?></strong><br>
                <span><?php echo esc_html($capture['date']); ?></span><br>
                <span><?php echo wp_kses_post(wc_price($capture['amount'] / 100)); ?></span>
            </div>
            <?php
        }
    }

    /**
     * Render Capture Form
     */
    private function render_capture_form($order)
    {
        $settings = get_option('woocommerce_briqpay_settings');
        if ('yes' !== ($settings['order_management_enabled'] ?? 'no')) {
            return;
        }

        $remaining = $this->get_remaining_items($order);
        if (empty($remaining)) {
            echo '<p><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Fully captured.', 'briqpay-for-woocommerce') . '</p>';
            return;
        }

        ?>
        <a href="#"
            class="button briqpay-capture-form-toggle"><?php esc_html_e('Manual Capture', 'briqpay-for-woocommerce'); ?></a>
        <div class="briqpay-capture-form">
            <input type="hidden" id="briqpay_capture_order_id" value="<?php echo esc_attr($order->get_id()); ?>">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Item', 'briqpay-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('Qty', 'briqpay-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($remaining as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['name']); ?></td>
                            <td>
                                <input type="number" class="briqpay-capture-qty"
                                    data-ref="<?php echo esc_attr($item['reference']); ?>"
                                    data-name="<?php echo esc_attr($item['name']); ?>"
                                    data-price-inc="<?php echo esc_attr($item['unitPriceIncVat']); ?>"
                                    data-tax="<?php echo esc_attr($item['taxRate']); ?>"
                                    data-type="<?php echo esc_attr($item['productType']); ?>" min="0"
                                    max="<?php echo esc_attr($item['quantity']); ?>"
                                    value="<?php echo esc_attr($item['quantity']); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button
                class="button button-primary briqpay-do-capture"><?php esc_html_e('Execute Capture', 'briqpay-for-woocommerce'); ?></button>
            <div class="briqpay-capture-loading" style="display:none;"><span class="spinner is-active"></span></div>
        </div>
        <?php
    }

    /**
     * Get remaining items for form
     */
    private function get_remaining_items($order)
    {
        $history = $order->get_meta('_briqpay_capture_history') ?: array();
        $captured_counts = array();
        foreach ($history as $h) {
            foreach ($h['items'] as $item) {
                $ref = $item['reference'];
                $captured_counts[$ref] = ($captured_counts[$ref] ?? 0) + $item['quantity'];
            }
        }

        $remaining_consolidated = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $sku = $product->get_sku();
            $id = $product->get_id();
            $ref = !empty($sku) ? $sku . '-' . $id : (string) $id;
            $total_qty = $item->get_quantity();
            $already_captured = $captured_counts[$ref] ?? 0;
            $rem = $total_qty - $already_captured;

            // Reduce the global captured count for this reference as we "consume" it across order items
            $captured_counts[$ref] = max(0, $already_captured - $total_qty);

            if ($rem > 0) {
                $item_total = (float) $item->get_subtotal();
                $item_tax = (float) $item->get_subtotal_tax();
                $unit_inc = ($item_total + $item_tax) / $total_qty;

                if (isset($remaining_consolidated[$ref])) {
                    $remaining_consolidated[$ref]['quantity'] += $rem;
                    // Note: unit price is assumed to be the same for the same product
                } else {
                    $remaining_consolidated[$ref] = array(
                        'productType' => 'physical',
                        'reference' => (string) $ref,
                        'name' => $item->get_name(),
                        'quantity' => $rem,
                        'unitPriceIncVat' => (int) round($unit_inc * 100),
                        'taxRate' => $this->get_item_tax_rate($item),
                    );
                }
            }
        }

        $remaining = array_values($remaining_consolidated);

        // Fees
        foreach ($order->get_fees() as $fee) {
            $ref = $fee->get_id();
            if (!isset($captured_counts[$ref])) {
                $fee_total = (float) $fee->get_total();
                $fee_tax = (float) $fee->get_total_tax();
                $total_inc = ($fee_total + $fee_tax);

                $remaining[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    'name' => $fee->get_name(),
                    'quantity' => 1,
                    'unitPriceIncVat' => (int) round($total_inc * 100),
                    'taxRate' => $this->get_item_tax_rate($fee),
                );
            }
        }

        // Shipping
        $shipping_total = (float) $order->get_shipping_total();
        if ($shipping_total > 0 && !isset($captured_counts['shipping'])) {
            $shipping_tax = (float) $order->get_shipping_tax();
            $remaining[] = array(
                'productType' => 'shipping_fee',
                'reference' => 'shipping',
                'name' => __('Shipping', 'briqpay-for-woocommerce'),
                'quantity' => 1,
                'unitPriceIncVat' => (int) round(($shipping_total + $shipping_tax) * 100),
                'taxRate' => $this->get_shipping_tax_rate($order),
            );
        }

        // Coupons
        foreach ($order->get_coupons() as $coupon) {
            $code = $coupon->get_code();
            $ref = 'discount_' . $code;
            if (!isset($captured_counts[$ref])) {
                $discount_amount = (float) $coupon->get_discount();
                $discount_tax = (float) $coupon->get_discount_tax();
                $tax_rate = $this->get_coupon_tax_rate($order);

                $remaining[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
                    'quantity' => 1,
                    'unitPriceIncVat' => (int) round(($discount_amount + $discount_tax) * -100),
                    'taxRate' => $tax_rate,
                );
            }
        }

        return $remaining;
    }

    /**
     * Get tax rate for an item
     */
    private function get_item_tax_rate($item)
    {
        $tax_data = $item->get_taxes();
        if (!empty($tax_data['total'])) {
            $tax_rate_id = key($tax_data['total']);
            return (int) round(\WC_Tax::get_rate_percent_value($tax_rate_id) * 100);
        }

        $total = (float) $item->get_total();
        $tax = (float) $item->get_total_tax();
        return ($total > 0) ? (int) round(($tax / $total) * 10000) : 0;
    }

    /**
     * Get tax rate for shipping
     */
    private function get_shipping_tax_rate($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $method) {
            return $this->get_item_tax_rate($method);
        }

        $total = (float) $order->get_shipping_total();
        $tax = (float) $order->get_shipping_tax();
        return ($total > 0) ? (int) round(($tax / $total) * 10000) : 0;
    }

    /**
     * AJAX Capture Callback
     */
    public function ajax_capture_items()
    {
        check_ajax_referer('briqpay_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'briqpay-for-woocommerce')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $items = isset($_POST['items']) ? map_deep(wp_unslash($_POST['items']), 'sanitize_text_field') : array();


        if (!$order_id || empty($items)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'briqpay-for-woocommerce')));
        }

        $order = wc_get_order($order_id);
        $order_mgmt = new Order_Management();
        $result = $order_mgmt->manual_capture($order, $items);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Capture successful.', 'briqpay-for-woocommerce')));
    }

    /**
     * Get Briqpay Backoffice URL
     */
    private function get_backoffice_url($session_id, $client_token)
    {
        if (!$session_id || !$client_token) {
            return '';
        }

        $jwt_explode = explode('.', (string) $client_token);
        $decoded_array = isset($jwt_explode[1]) ? json_decode(base64_decode($jwt_explode[1]), true) : array();
        $merchant_id = $decoded_array['merchantId'] ?? '';

        if (!$merchant_id) {
            return '';
        }

        $settings = get_option('woocommerce_briqpay_settings');
        $testmode = ('yes' === ($settings['testmode'] ?? 'no')) ? '1' : '0';

        return sprintf(
            'https://app.briqpay.com/dashboard/sessions/orders/%s?test=%s&merchantId=%s',
            $session_id,
            $testmode,
            $merchant_id
        );
    }

    /**
     * Get tax rate for coupons from the order's first line item.
     */
    private function get_coupon_tax_rate($order)
    {
        $items = $order->get_items();
        foreach ($items as $item) {
            return $this->get_item_tax_rate($item);
        }
        return 0;
    }
}
