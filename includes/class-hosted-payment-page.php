<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Hosted Payment Page
 *
 * Lets a merchant build an order in the WooCommerce admin and then create a
 * Briqpay-hosted payment link for it (POST /v3/session followed by
 * POST /v3/hosted-page). Payment status then flows back through the existing
 * webhook pipeline exactly like any other Briqpay order.
 */
class Hosted_Payment_Page
{
    const FLOW_B2C = 'b2c';
    const FLOW_B2B_PAYMENT = 'b2b_payment_module';
    const FLOW_B2B_CHECKOUT = 'b2b_checkout';

    const META_HPP_ID = '_briqpay_hpp_id';
    const META_HPP_URL = '_briqpay_hpp_url';
    const META_HPP_FLOW = '_briqpay_hpp_flow';
    const META_HPP_CREATED = '_briqpay_hpp_created';

    /**
     * Init
     */
    public function init()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_ajax_briqpay_create_hosted_page', array($this, 'ajax_create_hosted_page'));
        add_action('briqpay_webhook_session_verified', array($this, 'maybe_sync_from_session'), 10, 3);
    }

    /**
     * Get Briqpay Settings
     */
    protected function get_settings()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return is_array($settings) ? $settings : array();
    }

    /**
     * Get API Instance
     */
    protected function get_api()
    {
        $settings = $this->get_settings();
        return new API(
            isset($settings['merchant_id']) ? $settings['merchant_id'] : '',
            isset($settings['shared_secret']) ? $settings['shared_secret'] : '',
            'yes' === (isset($settings['testmode']) ? $settings['testmode'] : 'no')
        );
    }

    /**
     * Whether Hosted Payment Pages are enabled in settings
     */
    public function is_enabled()
    {
        $settings = $this->get_settings();
        return 'yes' === (isset($settings['hpp_enabled']) ? $settings['hpp_enabled'] : 'no');
    }

    /**
     * Get the merchant-configured default flow
     */
    public function get_default_flow()
    {
        $settings = $this->get_settings();
        return self::normalize_flow(isset($settings['hpp_default_flow']) ? $settings['hpp_default_flow'] : self::FLOW_B2C);
    }

    /**
     * Available flow presets
     *
     * @return array<string,array{customerType:string,loadModules:string[],label:string}>
     */
    public static function get_flows()
    {
        return array(
            self::FLOW_B2C => array(
                'customerType' => 'consumer',
                'loadModules' => array('payment'),
                'label' => __('Consumer', 'briqpay-for-woocommerce'),
            ),
            self::FLOW_B2B_PAYMENT => array(
                'customerType' => 'business',
                'loadModules' => array('payment'),
                'label' => __('Business - Payment Methods Only', 'briqpay-for-woocommerce'),
            ),
            self::FLOW_B2B_CHECKOUT => array(
                'customerType' => 'business',
                // Mirrors B2b_Checkout::filter_b2b_session_data() so the storefront
                // and hosted-page B2B experiences stay consistent.
                'loadModules' => array('company_lookup', 'billing', 'shipping', 'payment'),
                'label' => __('Business - Full Checkout', 'briqpay-for-woocommerce'),
            ),
        );
    }

    /**
     * Normalize a flow value, falling back to B2C for anything unrecognized.
     */
    public static function normalize_flow($flow)
    {
        $flows = self::get_flows();
        return isset($flows[$flow]) ? $flow : self::FLOW_B2C;
    }

    /**
     * Sanitize the hosted page title.
     *
     * Per the Briqpay API spec: minLength 3, maxLength 256. Returns '' if the
     * (trimmed) value is too short, otherwise truncated to the max length.
     */
    public static function sanitize_page_title($value)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < 3) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 256) : substr($value, 0, 256);
    }

    /**
     * Sanitize the hosted page logo URL.
     *
     * Per the Briqpay API spec: an absolute http(s) URL, max 512 characters,
     * pointing at a png/jpg/jpeg/svg image (query strings are tolerated).
     * Returns '' when the value doesn't satisfy these constraints.
     */
    public static function sanitize_logo_url($value)
    {
        $value = trim((string) $value);

        if ('' === $value || strlen($value) > 512) {
            return '';
        }

        // Reject embedded whitespace/control characters even though parse_url
        // may otherwise accept them.
        if (preg_match('/[\s\x00-\x1F]/', $value)) {
            return '';
        }

        $parts = wp_parse_url($value);
        if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return '';
        }

        if (empty($parts['host']) || empty($parts['path'])) {
            return '';
        }

        $extension = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
        if (!in_array($extension, array('png', 'jpg', 'jpeg', 'svg'), true)) {
            return '';
        }

        return $value;
    }

    /**
     * Sum cart line amounts using the exact rule Briqpay validates against
     * (see Session_Manager::get_session_data() for the storefront equivalent):
     * sales_tax lines contribute only to amountIncVat via totalTaxAmount;
     * every other line contributes unitPrice*quantity to amountExVat and
     * totalAmount to amountIncVat.
     *
     * @return array{amountIncVat:int,amountExVat:int}
     */
    public static function sum_cart_amounts(array $cart)
    {
        $sum_inc = 0;
        $sum_ex = 0;

        foreach ($cart as $item) {
            if ('sales_tax' === (isset($item['productType']) ? $item['productType'] : '')) {
                $sum_inc += $item['totalTaxAmount'];
                continue;
            }

            $qty = isset($item['quantity']) ? $item['quantity'] : 1;
            $sum_ex += $item['unitPrice'] * $qty;
            $sum_inc += $item['totalAmount'];
        }

        return array(
            'amountIncVat' => (int) round($sum_inc),
            'amountExVat' => (int) round($sum_ex),
        );
    }

    /**
     * Build the /v3/session payload for a hosted payment page.
     */
    public function build_session_payload($order, $flow)
    {
        $flow = self::normalize_flow($flow);
        $flows = self::get_flows();
        $cart = $this->get_order_cart($order);
        $amounts = self::sum_cart_amounts($cart);

        $data = array(
            'product' => array('type' => 'payment', 'intent' => 'payment_one_time'),
            'customerType' => $flows[$flow]['customerType'],
            'country' => $this->get_country($order),
            'locale' => $this->get_locale(),
            'urls' => array(
                'terms' => get_permalink(wc_get_page_id('terms')) ?: get_home_url(),
                'redirect' => $order->get_checkout_order_received_url(),
            ),
            'references' => array('reference1' => (string) $order->get_order_number()),
            'hooks' => (new Session_Manager())->get_webhooks(),
            // Top-level session config (distinct from modules.config below) -
            // only valid at session creation, which is always the case here
            // (hosted pages are never PATCHed).
            'config' => Session_Manager::get_realtime_session_config(),
            'modules' => array(
                'loadModules' => $flows[$flow]['loadModules'],
                'config' => array(
                    'payment' => array(
                        // MUST stay disabled: nothing calls make_decision() for a
                        // hosted page (that only happens via our own storefront
                        // JS), and the WooCommerce order already exists, so there
                        // is nothing left to decide. See HPP-IMPLEMENTATION-PLAN.md §2.1.
                        'decision' => array('enabled' => false),
                    ),
                ),
            ),
            'data' => array(
                'order' => array(
                    'currency' => $order->get_currency(),
                    'amountIncVat' => $amounts['amountIncVat'],
                    'amountExVat' => $amounts['amountExVat'],
                    'cart' => $cart,
                ),
            ),
        );

        $billing = $this->get_billing_address($order);
        if (null !== $billing) {
            $data['data']['billing'] = $billing;
        }

        $shipping = $this->get_shipping_address($order);
        if (null !== $shipping) {
            $data['data']['shipping'] = $shipping;
        }

        $company = $this->get_company($order, $flow);
        if (null !== $company) {
            $data['data']['company'] = $company;
        }

        /**
         * Filter the session payload used to back a hosted payment page.
         *
         * @param array     $data  Session payload.
         * @param \WC_Order $order The order the hosted page is for.
         * @param string    $flow  One of b2c|b2b_payment_module|b2b_checkout.
         */
        return apply_filters('briqpay_hpp_session_data', $data, $order, $flow);
    }

    /**
     * Build the /v3/hosted-page "config" object from settings.
     */
    public function build_hosted_page_config()
    {
        $settings = $this->get_settings();

        $config = array(
            'showCart' => 'no' !== (isset($settings['hpp_show_cart']) ? $settings['hpp_show_cart'] : 'yes'),
        );

        $page_title = self::sanitize_page_title(isset($settings['hpp_page_title']) ? $settings['hpp_page_title'] : '');
        if ('' !== $page_title) {
            $config['pageTitle'] = $page_title;
        }

        $logo_url = self::sanitize_logo_url(isset($settings['hpp_logo_url']) ? $settings['hpp_logo_url'] : '');
        if ('' !== $logo_url) {
            $config['logoUrl'] = $logo_url;
        }

        /**
         * Filter the hosted page config sent to POST /v3/hosted-page.
         *
         * @param array $config Hosted page config (pageTitle, logoUrl, showCart).
         */
        return apply_filters('briqpay_hpp_config', $config);
    }

    /**
     * Create a hosted payment page for an order.
     *
     * @param \WC_Order $order
     * @param string    $flow
     * @return array|\WP_Error The /v3/hosted-page response, or a WP_Error.
     */
    public function create($order, $flow)
    {
        if (!$this->is_enabled()) {
            return new \WP_Error('briqpay_hpp_disabled', __('Hosted Payment Pages are not enabled in the Briqpay settings.', 'briqpay-for-woocommerce'));
        }

        if (!$order) {
            return new \WP_Error('briqpay_hpp_invalid_order', __('Invalid order.', 'briqpay-for-woocommerce'));
        }

        if ($order->has_status(array('processing', 'completed', 'refunded', 'cancelled'))) {
            return new \WP_Error('briqpay_hpp_order_not_payable', __('This order has already been paid, refunded or cancelled.', 'briqpay-for-woocommerce'));
        }

        if (empty($order->get_items())) {
            return new \WP_Error('briqpay_hpp_empty_order', __('Add at least one line item to the order before creating a hosted payment page.', 'briqpay-for-woocommerce'));
        }

        $flow = self::normalize_flow($flow);

        // Refuse to regenerate over an already-completed session - that would
        // orphan a paid session instead of just replacing an unused link.
        $existing_session_id = $order->get_meta('_briqpay_session_id');
        if (!empty($existing_session_id)) {
            $existing_session = $this->get_api()->get_session($existing_session_id);
            if (!is_wp_error($existing_session) && 'completed' === (isset($existing_session['status']) ? $existing_session['status'] : '')) {
                return new \WP_Error('briqpay_hpp_session_completed', __('This order already has a completed Briqpay session and cannot be regenerated.', 'briqpay-for-woocommerce'));
            }
        }

        $payload = $this->build_session_payload($order, $flow);
        $cart = $payload['data']['order']['cart'];

        if (empty($cart)) {
            return new \WP_Error('briqpay_hpp_empty_cart', __('Could not build a Briqpay cart from this order.', 'briqpay-for-woocommerce'));
        }

        // Guard against drift from the WooCommerce order total. Webhooks::
        // process_webhook_callback() parks a payment on-hold if the amounts
        // differ by more than 5 minor units - better to refuse up front with a
        // clear message than to ship a link that silently stalls after payment.
        $amount_inc_vat = $payload['data']['order']['amountIncVat'];
        $order_total_minor = (int) round(((float) $order->get_total()) * 100);

        if (abs($amount_inc_vat - $order_total_minor) > 5) {
            return new \WP_Error('briqpay_hpp_total_mismatch', sprintf(
                /* translators: 1: Briqpay total, 2: WooCommerce order total */
                __('Briqpay total (%1$s) does not match the WooCommerce order total (%2$s). Recalculate the order and try again.', 'briqpay-for-woocommerce'),
                number_format($amount_inc_vat / 100, 2),
                $order->get_total()
            ));
        }

        /**
         * Fires before a hosted payment page session is created.
         *
         * @param \WC_Order $order   The order.
         * @param string    $flow    The resolved flow.
         * @param array     $payload The session payload about to be sent.
         */
        do_action('briqpay_hpp_before_create', $order, $flow, $payload);

        $session = $this->get_api()->create_session($payload);
        if (is_wp_error($session)) {
            Logger::error('Hosted_Payment_Page: session creation failed: ' . $session->get_error_message());
            return $session;
        }
        if (empty($session['sessionId'])) {
            Logger::error('Hosted_Payment_Page: session creation returned no sessionId.');
            return new \WP_Error('briqpay_hpp_no_session_id', __('Briqpay did not return a session ID.', 'briqpay-for-woocommerce'));
        }

        $hosted = $this->get_api()->create_hosted_page(array(
            'sessionId' => $session['sessionId'],
            'config' => $this->build_hosted_page_config(),
        ));

        if (is_wp_error($hosted)) {
            Logger::error('Hosted_Payment_Page: hosted page creation failed: ' . $hosted->get_error_message());
            return $hosted;
        }
        if (empty($hosted['url'])) {
            Logger::error('Hosted_Payment_Page: hosted page response had no url.');
            return new \WP_Error('briqpay_hpp_no_url', __('Briqpay did not return a hosted page URL.', 'briqpay-for-woocommerce'));
        }

        $flows = self::get_flows();

        $order->set_payment_method('briqpay');
        $order->set_payment_method_title('Briqpay');
        $order->update_meta_data('_briqpay_session_id', $session['sessionId']);
        $order->update_meta_data(self::META_HPP_ID, isset($hosted['hostedPageId']) ? $hosted['hostedPageId'] : '');
        $order->update_meta_data(self::META_HPP_URL, $hosted['url']);
        $order->update_meta_data(self::META_HPP_FLOW, $flow);
        $order->update_meta_data(self::META_HPP_CREATED, gmdate('Y-m-d H:i:s'));

        $this->freeze_line_references($order);

        if ($order->has_status(array('auto-draft', 'checkout-draft', 'draft', 'pending', 'on-hold', 'failed'))) {
            $order->set_status('pending');
        }

        $order->add_order_note(sprintf(
            /* translators: 1: flow label, 2: hosted page URL */
            __('Briqpay hosted payment page created (%1$s). Link: %2$s', 'briqpay-for-woocommerce'),
            $flows[$flow]['label'],
            $hosted['url']
        ));

        $order->save();

        /**
         * Fires after a hosted payment page has been created.
         *
         * @param \WC_Order $order   The order.
         * @param array     $hosted  The /v3/hosted-page response.
         * @param array     $session The /v3/session response.
         * @param string    $flow    The resolved flow.
         */
        do_action('briqpay_hpp_created', $order, $hosted, $session, $flow);

        return $hosted;
    }

    /**
     * AJAX: create a hosted payment page for an order.
     */
    public function ajax_create_hosted_page()
    {
        check_ajax_referer('briqpay_hpp_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'briqpay-for-woocommerce')));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $flow = isset($_POST['flow']) ? sanitize_key(wp_unslash($_POST['flow'])) : '';

        // Reject an unrecognized flow outright rather than silently falling
        // back to B2C - a tampered request should surface as an error.
        $flows = self::get_flows();
        if (!array_key_exists($flow, $flows)) {
            wp_send_json_error(array('message' => __('Invalid flow selected.', 'briqpay-for-woocommerce')));
        }

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'briqpay-for-woocommerce')));
        }

        $result = $this->create($order, $flow);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'url' => $result['url'],
            'hostedPageId' => isset($result['hostedPageId']) ? $result['hostedPageId'] : '',
            'flow' => $flow,
        ));
    }

    /**
     * Sync company + billing/shipping addresses collected by a
     * Business - Full Checkout (FLOW_B2B_CHECKOUT) hosted page flow back
     * onto the WooCommerce order. Hooked to briqpay_webhook_session_verified
     * so it runs on every verified webhook, not just terminal ones.
     *
     * @param array     $data    Sanitized webhook payload.
     * @param array     $session Authoritative session fetched from the Briqpay API.
     * @param \WC_Order $order   The matched WooCommerce order.
     */
    public function maybe_sync_from_session($data, $session, $order)
    {
        if (self::FLOW_B2B_CHECKOUT !== $order->get_meta(self::META_HPP_FLOW)) {
            return;
        }

        $session_data = isset($session['data']) ? $session['data'] : array();

        $company = isset($session_data['company']) ? $session_data['company'] : array();
        if (!empty($company['name'])) {
            $order->update_meta_data('_briqpay_company_name', sanitize_text_field($company['name']));
        }
        if (!empty($company['cin'])) {
            $order->update_meta_data('_briqpay_company_cin', sanitize_text_field($company['cin']));
        }

        $billing = isset($session_data['billing']) ? $session_data['billing'] : array();
        $this->apply_address_to_order($order, $billing, 'billing');

        $shipping = isset($session_data['shipping']) ? $session_data['shipping'] : array();
        $this->apply_address_to_order($order, $shipping, 'shipping');

        Legacy_B2b_Meta::apply($order, $session);

        $order->save();
    }

    /**
     * Apply a Briqpay address block onto the order's billing or shipping
     * fields. Only non-empty Briqpay values are written, so a field Briqpay
     * doesn't report never blanks out an existing order value.
     *
     * @param \WC_Order $order
     * @param array     $address Briqpay address block (firstName, lastName, ...).
     * @param string    $type    'billing' or 'shipping'.
     */
    private function apply_address_to_order($order, array $address, $type)
    {
        if (empty($address)) {
            return;
        }

        $map = array(
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'streetAddress' => 'address_1',
            'streetAddress2' => 'address_2',
            'zip' => 'postcode',
            'city' => 'city',
            'region' => 'state',
            'country' => 'country',
        );

        if ('billing' === $type) {
            $map['email'] = 'email';
            $map['phoneNumber'] = 'phone';
        }

        foreach ($map as $briqpay_key => $order_field) {
            if (empty($address[$briqpay_key])) {
                continue;
            }
            $setter = 'set_' . $type . '_' . $order_field;
            if (is_callable(array($order, $setter))) {
                $order->{$setter}(sanitize_text_field($address[$briqpay_key]));
            }
        }
    }

    /**
     * Add the "Briqpay Hosted Payment Page" meta box to the order edit screen.
     *
     * Only shown for orders manually created in the WooCommerce admin (the
     * "Add order" screen) - not for regular customer/checkout orders, which
     * always go through the storefront/iframe flow instead.
     */
    public function add_meta_box()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || ('shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id)) {
            return;
        }

        if (!self::is_admin_created_order($this->resolve_order_from_admin_request())) {
            return;
        }

        add_meta_box(
            'briqpay_hosted_page',
            __('Briqpay Hosted Payment Page', 'briqpay-for-woocommerce'),
            array($this, 'render_meta_box'),
            $screen->id,
            'side',
            'default'
        );
    }

    /**
     * Resolve the order being viewed/created on the current admin request,
     * from the HPOS ?id=, legacy ?post= query args, or (for a brand new
     * "Add order" screen, where WordPress creates an auto-draft post before
     * add_meta_boxes fires) the global $post.
     *
     * Protected so tests can stub it directly instead of fighting
     * wc_get_order()'s real, non-overridable test bootstrap shim.
     *
     * @return \WC_Order|null
     */
    protected function resolve_order_from_admin_request()
    {
        if (isset($_GET['id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state mutation.
            $order = wc_get_order(absint(wp_unslash($_GET['id']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($order) {
                return $order;
            }
        }

        if (isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order = wc_get_order(absint(wp_unslash($_GET['post']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($order) {
                return $order;
            }
        }

        global $post;
        if (isset($post->ID)) {
            return wc_get_order($post->ID);
        }

        return null;
    }

    /**
     * Whether an order was created manually in the WooCommerce admin (as
     * opposed to the storefront checkout, which this plugin's own
     * Checkout_Handler tags with created_via 'Briqpay').
     *
     * A brand new, not-yet-saved order (created_via still empty) counts as
     * admin-created. When the order can't be resolved at all (e.g. an
     * edge-case admin screen state), this fails open so the box is shown
     * rather than silently hidden.
     *
     * @param \WC_Order|null $order
     * @return bool
     */
    public static function is_admin_created_order($order)
    {
        if (!$order) {
            return true;
        }

        return in_array($order->get_created_via(), array('', 'admin'), true);
    }

    /**
     * Render the meta box.
     */
    public function render_meta_box($post_or_order)
    {
        $order = ($post_or_order instanceof \WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'briqpay-for-woocommerce') . '</p>';
            return;
        }

        if (empty($order->get_items())) {
            echo '<p>' . esc_html__('Add products and save the order first, then come back here to create a hosted payment page.', 'briqpay-for-woocommerce') . '</p>';
            return;
        }

        $hpp_url = $order->get_meta(self::META_HPP_URL);
        $hpp_flow = self::normalize_flow($order->get_meta(self::META_HPP_FLOW));
        $hpp_created = $order->get_meta(self::META_HPP_CREATED);
        $flows = self::get_flows();
        $already_paid = $order->has_status(array('processing', 'completed', 'refunded', 'cancelled'));

        echo '<input type="hidden" id="briqpay_hpp_order_id" value="' . esc_attr($order->get_id()) . '" />';

        if ($already_paid) {
            // The link is dead once the order is paid/refunded/cancelled (a
            // completed Briqpay session can't be reopened) - showing it here
            // would only invite a merchant to send a customer a broken link.
            echo '<p><i>' . esc_html__('This order has already been paid, refunded or cancelled. No new hosted page can be created.', 'briqpay-for-woocommerce') . '</i></p>';
            return;
        }

        if ($hpp_url) {
            echo '<p><strong>' . esc_html__('Hosted payment link:', 'briqpay-for-woocommerce') . '</strong></p>';
            echo '<input type="text" id="briqpay_hpp_url" class="widefat" readonly value="' . esc_attr($hpp_url) . '" />';
            echo '<p>';
            echo '<button type="button" class="button briqpay-hpp-copy">' . esc_html__('Copy link', 'briqpay-for-woocommerce') . '</button> ';
            echo '<a href="' . esc_url($hpp_url) . '" target="_blank" rel="noopener noreferrer" class="button">' . esc_html__('Open', 'briqpay-for-woocommerce') . '</a>';
            echo '</p>';
            echo '<p>' . esc_html__('Flow:', 'briqpay-for-woocommerce') . ' ' . esc_html($flows[$hpp_flow]['label']);
            if ($hpp_created) {
                echo ' &middot; ' . esc_html($hpp_created);
            }
            echo '</p>';
            echo '<button type="button" class="button briqpay-hpp-regenerate" data-flow="' . esc_attr($hpp_flow) . '">' . esc_html__('Regenerate', 'briqpay-for-woocommerce') . '</button>';
        } else {
            echo '<p><label for="briqpay_hpp_flow">' . esc_html__('Flow', 'briqpay-for-woocommerce') . '</label><br>';
            echo '<select id="briqpay_hpp_flow" class="widefat">';
            foreach ($flows as $key => $flow_data) {
                echo '<option value="' . esc_attr($key) . '"' . selected($this->get_default_flow(), $key, false) . '>' . esc_html($flow_data['label']) . '</option>';
            }
            echo '</select></p>';

            if (empty($order->get_billing_email())) {
                echo '<p class="briqpay-hpp-warning"><i>' . esc_html__('No billing email on this order - some payment methods require one.', 'briqpay-for-woocommerce') . '</i></p>';
            }

            echo '<button type="button" class="button button-primary briqpay-hpp-create">' . esc_html__('Create hosted payment page', 'briqpay-for-woocommerce') . '</button>';
        }

        echo '<div class="briqpay-hpp-result"></div>';
        echo '<div class="briqpay-hpp-loading" style="display:none;"><span class="spinner is-active"></span></div>';
    }

    /**
     * Enqueue admin assets: the settings-page fold/unfold script, or the
     * order-screen meta box script.
     */
    public function admin_scripts($hook)
    {
        if ('woocommerce_page_wc-settings' === $hook) {
            $this->maybe_enqueue_settings_script();
            return;
        }

        if (!$this->is_enabled()) {
            return;
        }

        if ('post.php' !== $hook && 'post-new.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }

        wp_enqueue_style('briqpay-admin', BRIQPAY_WC_URL . 'assets/css/admin.css', array(), BRIQPAY_WC_VERSION);
        wp_enqueue_script('briqpay-hpp-admin', BRIQPAY_WC_URL . 'assets/js/hpp-admin.js', array('jquery'), BRIQPAY_WC_VERSION, true);
        wp_localize_script('briqpay-hpp-admin', 'briqpayHpp', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_hpp_nonce'),
            'confirm_create' => __('Create a Briqpay hosted payment page for this order?', 'briqpay-for-woocommerce'),
            'confirm_regen' => __('This creates a NEW payment session and invalidates the previous link. Continue?', 'briqpay-for-woocommerce'),
            'error_generic' => __('Could not create the hosted payment page.', 'briqpay-for-woocommerce'),
            'copied' => __('Copied!', 'briqpay-for-woocommerce'),
        ));
    }

    /**
     * Enqueue the script that folds the Hosted Payment Pages sub-fields
     * (default flow, page title, logo URL, show cart) until "Enable Hosted
     * Payment Pages" is checked, so the section doesn't bloat the Briqpay
     * settings screen. Only loaded on the Briqpay gateway's own settings tab.
     */
    private function maybe_enqueue_settings_script()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state mutation.
        if (!isset($_GET['page']) || 'wc-settings' !== $_GET['page']) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['tab']) || 'checkout' !== $_GET['tab']) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['section']) || 'briqpay' !== $_GET['section']) {
            return;
        }

        wp_enqueue_script('briqpay-hpp-settings', BRIQPAY_WC_URL . 'assets/js/hpp-settings.js', array('jquery'), BRIQPAY_WC_VERSION, true);
    }

    /**
     * Map order line items/fees to the Briqpay cart format.
     *
     * Delegates to Order_Management::get_order_cart() so hosted-page sessions
     * use the exact same reference derivation that captures and refunds rely
     * on later.
     */
    private function get_order_cart($order)
    {
        return (new Order_Management())->get_order_cart($order);
    }

    /**
     * Freeze the Briqpay item/fee reference onto order line items and fees
     * that don't already have one, using the same derivation
     * Order_Management::get_order_cart() falls back to (SKU, else product
     * ID, else fee ID). This pins the reference actually sent to Briqpay
     * against a later SKU rename, which would otherwise silently break
     * captures and refunds for this order.
     */
    private function freeze_line_references($order)
    {
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_briqpay_item_reference')) {
                continue;
            }

            $product = $item->get_product();
            if ($product) {
                $sku = $product->get_sku();
                $id = $product->get_id();
                $ref = !empty($sku) ? $sku : (string) $id;
            } else {
                $ref = 'item_' . $item->get_id();
            }

            $item->add_meta_data('_briqpay_item_reference', $ref, true);
            $item->save();
        }

        foreach ($order->get_fees() as $fee) {
            if ($fee->get_meta('_briqpay_fee_reference')) {
                continue;
            }

            $fee->add_meta_data('_briqpay_fee_reference', (string) $fee->get_id(), true);
            $fee->save();
        }
    }

    /**
     * Get Billing Address from the order (mirrors Session_Manager::get_billing_address()).
     */
    private function get_billing_address($order)
    {
        $address = $order->get_billing_address_1();
        $city = $order->get_billing_city();
        $zip = $order->get_billing_postcode();
        $country = $order->get_billing_country();

        if (empty($address) || empty($city) || empty($zip) || empty($country)) {
            return null;
        }

        $billing = array(
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'streetAddress' => $address,
            'streetAddress2' => $order->get_billing_address_2(),
            'zip' => $zip,
            'city' => $city,
            'region' => $order->get_billing_state(),
            'country' => $country,
            'phoneNumber' => $order->get_billing_phone(),
        );

        /**
         * Filter the billing address sent to Briqpay for a hosted payment page.
         *
         * @param array     $billing Billing address fields.
         * @param \WC_Order $order   The order.
         */
        return apply_filters('briqpay_hpp_billing_address', $billing, $order);
    }

    /**
     * Get Shipping Address from the order (mirrors Session_Manager::get_shipping_address()).
     */
    private function get_shipping_address($order)
    {
        $address = $order->get_shipping_address_1();
        $city = $order->get_shipping_city();
        $zip = $order->get_shipping_postcode();
        $country = $order->get_shipping_country();

        if (empty($address) || empty($city) || empty($zip) || empty($country)) {
            return null;
        }

        $shipping = array(
            'firstName' => $order->get_shipping_first_name(),
            'lastName' => $order->get_shipping_last_name(),
            // WooCommerce orders have no dedicated shipping email/phone fields.
            'email' => $order->get_billing_email(),
            'streetAddress' => $address,
            'streetAddress2' => $order->get_shipping_address_2(),
            'zip' => $zip,
            'city' => $city,
            'region' => $order->get_shipping_state(),
            'country' => $country,
            'phoneNumber' => $order->get_billing_phone(),
        );

        /**
         * Filter the shipping address sent to Briqpay for a hosted payment page.
         *
         * @param array     $shipping Shipping address fields.
         * @param \WC_Order $order    The order.
         */
        return apply_filters('briqpay_hpp_shipping_address', $shipping, $order);
    }

    /**
     * Get Company data for B2B flows. Null for B2C.
     */
    private function get_company($order, $flow)
    {
        if (self::FLOW_B2C === $flow) {
            return null;
        }

        $name = $order->get_billing_company();
        if (empty($name)) {
            $name = $order->get_meta('_briqpay_company_name');
        }

        if (empty($name)) {
            return null;
        }

        $company = array('name' => $name);

        $cin = Legacy_B2b_Meta::get_company_cin($order);
        if (!empty($cin)) {
            $company['cin'] = $cin;
        }

        return $company;
    }

    /**
     * Resolve the session country: order billing country, else store base
     * country, else 'SE'.
     */
    private function get_country($order)
    {
        $country = $order->get_billing_country();
        if (!empty($country)) {
            return $country;
        }

        $base = wc_get_base_location();
        if (!empty($base['country'])) {
            return $base['country'];
        }

        return 'SE';
    }

    /**
     * Get Locale
     */
    private function get_locale()
    {
        $locale = strtolower(str_replace('_', '-', get_locale()));

        /**
         * Filter the locale sent for a hosted payment page session.
         *
         * @param string $locale Locale in "sv-se" / "en-gb" format.
         */
        return apply_filters('briqpay_hpp_locale', $locale);
    }
}
