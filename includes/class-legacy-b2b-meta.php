<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Legacy B2B Order Meta Mapping
 *
 * Optional compatibility layer for stores migrating from the previous
 * "Briqpay for WooCommerce" plugin (krokedil/briqpay-for-woocommerce, v1 API).
 *
 * When the "Legacy B2B order meta mapping" setting is enabled, B2B orders
 * additionally get the meta keys the old plugin used to write, on top of the
 * keys this plugin already writes. This is intentionally additive - internal
 * features (Hosted_Payment_Page, B2b_Checkout admin box) read the current
 * keys, so those are never removed or replaced.
 *
 * The read-side CIN fallback (get_company_cin()) is NOT gated on the setting:
 * it lets orders/imports that only have the legacy `_billing_org_nr` key keep
 * working regardless of whether the toggle is on.
 */
class Legacy_B2b_Meta
{
    /**
     * Register admin-only display parity hooks.
     *
     * No-op unless the "legacy_b2b_meta_mapping" setting is enabled. Safe to
     * call unconditionally at bootstrap.
     */
    public static function init()
    {
        if (!self::is_enabled()) {
            return;
        }

        add_action('woocommerce_admin_order_data_after_billing_address', array(__CLASS__, 'render_org_nr_field'));
        add_action('woocommerce_admin_order_data_after_shipping_address', array(__CLASS__, 'render_shipping_email'));
        add_action('woocommerce_process_shop_order_meta', array(__CLASS__, 'save_org_nr_field'), 45, 1);
    }

    /**
     * Is the legacy B2B meta mapping toggle enabled?
     *
     * @return bool
     */
    public static function is_enabled()
    {
        $settings = get_option('woocommerce_briqpay_settings', array());
        return is_array($settings) && isset($settings['legacy_b2b_meta_mapping']) && 'yes' === $settings['legacy_b2b_meta_mapping'];
    }

    /**
     * Determine whether a Briqpay session represents a B2B purchase.
     *
     * Must be derivable from the session payload alone - some call sites
     * (webhooks, hosted payment pages) run with no WC()->session/cookie
     * available at all, unlike B2b_Checkout::is_b2b_active().
     *
     * @param array $session Briqpay session data (as returned by the API).
     * @return bool
     */
    public static function is_b2b_session(array $session)
    {
        $customer_type = $session['customerType'] ?? ($session['data']['customerType'] ?? '');
        if ('business' === $customer_type) {
            return true;
        }

        $company = $session['data']['company'] ?? array();
        if (!empty($company['cin']) || !empty($company['name'])) {
            return true;
        }

        return false;
    }

    /**
     * Write the legacy meta keys onto an order, if applicable.
     *
     * No-op unless the setting is enabled AND the session is B2B. Does not
     * call $order->save() - callers are expected to save the order themselves.
     *
     * @param \WC_Order  $order   The WooCommerce order.
     * @param array      $session Briqpay session data.
     * @param bool|null  $enabled Internal test seam - defaults to is_enabled().
     *                            get_option() is hard-defined once by
     *                            tests/bootstrap.php and cannot be overridden
     *                            per-test (same constraint documented in
     *                            AdminOrderMetaBoxTest), so tests exercise the
     *                            "enabled" branch by passing this explicitly.
     *                            Production call sites never pass it.
     */
    public static function apply($order, array $session, $enabled = null)
    {
        if (null === $enabled) {
            $enabled = self::is_enabled();
        }

        if (!$enabled || !self::is_b2b_session($session)) {
            return;
        }

        Logger::log('Legacy_B2b_Meta::apply() writing legacy B2B meta for order ' . $order->get_id());

        $company = $session['data']['company'] ?? array();
        $billing = $session['data']['billing'] ?? array();
        $shipping = $session['data']['shipping'] ?? array();

        // 1. Organisation number - the key most migrated integrations/ERPs read.
        if (!empty($company['cin'])) {
            $order->update_meta_data('_billing_org_nr', sanitize_text_field($company['cin']));
        }

        // 2. Shipping email + phone. WooCommerce orders have no dedicated
        //    shipping email/phone fields, so fall back to billing as the old
        //    plugin's own address helpers effectively did.
        $shipping_email = $shipping['email'] ?? ($billing['email'] ?? '');
        if (!empty($shipping_email)) {
            $order->update_meta_data('_shipping_email', sanitize_email($shipping_email));
        }

        $shipping_phone = $shipping['phoneNumber'] ?? ($billing['phoneNumber'] ?? '');
        if (!empty($shipping_phone) && is_callable(array($order, 'set_shipping_phone'))) {
            $order->set_shipping_phone(sanitize_text_field($shipping_phone));
        }

        // 3. Payment method mirror. Prefer whatever the caller already
        //    resolved onto the order; fall back to the payment method title.
        $psp_name = $order->get_meta('_briqpay_psp_name');
        if (empty($psp_name)) {
            $psp_name = $order->get_payment_method_title();
        }
        if (!empty($psp_name)) {
            $order->update_meta_data('_briqpay_payment_method', sanitize_text_field($psp_name));
        }

        // 4. Autocapture - legacy stored a raw truthy/empty value, consumers
        //    used !empty() checks against it.
        $order->update_meta_data(
            '_briqpay_autocapture',
            Order_Management::session_has_auto_capture_enabled($session) ? 1 : ''
        );

        // 5. Rules result - deprecated in the v3 API, no equivalent exists.
        //    Write the same empty-array shape the old plugin wrote when no
        //    rules result was present, for structural parity only.
        $order->update_meta_data('_briqpay_rules_result', wp_json_encode(array()));

        // 6. PSP update-order support - only when v3 actually surfaces it.
        $supported = $session['data']['transactions'][0]['pspSupportedOrderOperations']['updateOrderSupported'] ?? null;
        if (true === $supported) {
            $order->update_meta_data('_briqpay_psp_update_order_supported', true);
        }
    }

    /**
     * Get the company CIN for an order, falling back to the legacy meta key.
     *
     * Ungated: works regardless of whether the legacy toggle is enabled, so
     * orders imported from the previous plugin (which only set
     * `_billing_org_nr`) still resolve correctly.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return string
     */
    public static function get_company_cin($order)
    {
        $cin = $order->get_meta('_briqpay_company_cin');
        if (empty($cin)) {
            $cin = $order->get_meta('_billing_org_nr');
        }
        return (string) $cin;
    }

    /**
     * Render the legacy "Billing Organization Number" field on the order
     * edit screen, matching the previous plugin's field.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function render_org_nr_field($order)
    {
        if ('briqpay' !== $order->get_payment_method()) {
            return;
        }
        ?>
        <div class="order_data_column" style="clear:both; float:none; width:100%;">
            <div class="edit_address">
                <?php
                woocommerce_wp_text_input(
                    array(
                        'id' => '_billing_org_nr',
                        'label' => __('Billing Organization Number', 'briqpay-for-woocommerce'),
                        'wrapper_class' => '_billing_company_field',
                        'value' => $order->get_meta('_billing_org_nr'),
                    )
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the legacy shipping email as a mailto link, matching the
     * previous plugin's admin display.
     *
     * @param \WC_Order $order The WooCommerce order.
     */
    public static function render_shipping_email($order)
    {
        if ('briqpay' !== $order->get_payment_method()) {
            return;
        }

        $shipping_email = $order->get_meta('_shipping_email');
        if (empty($shipping_email)) {
            return;
        }
        ?>
        <p>
            <strong><?php esc_html_e('Email', 'briqpay-for-woocommerce'); ?>:</strong>
            <br>
            <a href="mailto:<?php echo esc_attr($shipping_email); ?>"><?php echo esc_html($shipping_email); ?></a>
        </p>
        <?php
    }

    /**
     * Save the legacy "Billing Organization Number" field from the order
     * edit screen (classic admin metabox save).
     *
     * @param int $post_id The order/post ID.
     */
    public static function save_org_nr_field($post_id)
    {
        if (!isset($_POST['_billing_org_nr'])) {
            return;
        }

        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }

        if (!current_user_can('edit_shop_orders') && !current_user_can('edit_shop_order', $post_id)) {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $org_number = sanitize_text_field(wp_unslash($_POST['_billing_org_nr']));
        $order->update_meta_data('_billing_org_nr', $org_number);
        $order->save();
    }
}
