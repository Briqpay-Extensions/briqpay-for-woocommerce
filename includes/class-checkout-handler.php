<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Checkout Handler
 */
class Checkout_Handler
{
    /**
     * Log message
     */
    private function log($message)
    {
        if (defined('WC_LOG_DIR')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'briqpay-for-woocommerce'));
        }
    }


    /**
     * Init
     */
    public function init()
    {
        add_action('wp_ajax_briqpay_get_session', array($this, 'ajax_get_session'));
        add_action('wp_ajax_nopriv_briqpay_get_session', array($this, 'ajax_get_session'));

        add_action('wp_ajax_briqpay_make_decision', array($this, 'ajax_make_decision'));
        add_action('wp_ajax_nopriv_briqpay_make_decision', array($this, 'ajax_make_decision'));

        add_action('template_redirect', array($this, 'handle_briqpay_return'));
        add_filter('body_class', array($this, 'add_body_class'));
        add_action('wp_head', array($this, 'output_critical_css'));
        add_filter('woocommerce_order_get_created_via', array($this, 'force_briqpay_origin'), 10, 2);
        add_filter('wc_order_attribution_origin_label', array($this, 'filter_attribution_origin_label'), 10, 3);
        add_shortcode('briqpay_iframe', array($this, 'render_briqpay_iframe'));
    }

    /**
     * Filter Order Button HTML (Classic Checkout)
     */
    public function filter_order_button_html($button_html)
    {
        if (is_checkout() && !is_order_received_page()) {
            $chosen_payment_method = WC()->session->get('chosen_payment_method');
            if ('briqpay' === $chosen_payment_method) {
                return ''; // Remove the button entirely from HTML
            }
        }
        return $button_html;
    }

    /**
     * Output critical CSS to hide Place Order button instantly
     */
    public function output_critical_css()
    {
        if (!is_checkout() || is_order_received_page()) {
            return;
        }

        $settings = get_option('woocommerce_briqpay_settings');
        if ('yes' !== ($settings['enabled'] ?? 'no')) {
            return;
        }

        // 1. Nuclear CSS: Target the primary button, any button in the action area, and the area itself.
        // We use maximum specificity and secondary properties like opacity and pointer-events as fallbacks.
        echo '<style id="briqpay-critical-css">
            /* Standard Checkout */
            #place_order,
            .form-row.place-order,
            /* Blocks Checkout Action Areas */
            .wc-block-checkout__actions,
            .wc-block-checkout__actions button,
            .wc-block-components-checkout-place-order-button,
            .wc-block-components-checkout-place-order-button button,
            [data-testid="wc-block-components-checkout-place-order-button"],
            /* Any button that might be a primary action */
            .wc-block-components-button.wc-block-components-checkout-place-order-button { 
                display: none !important; 
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                height: 1px !important;
                overflow: hidden !important;
                max-height: 1px !important;
            }

            /* Hide Payment Method Selection (Briqpay is the only one) */
            .wc_payment_method.payment_method_briqpay > label:first-child,
            .wc_payment_method.payment_method_briqpay > input[type="radio"],
            label[for="payment_method_briqpay"],
            .wc-block-components-radio-control__option[for*="briqpay"],
            label[for*="briqpay"] .wc-block-components-radio-control__label-group,
            .wc-block-components-checkout-payment-method-option--briqpay .wc-block-components-radio-control__option {
                display: none !important;
            }
            
            /* If it is the only method, hide the entire radio list item container but keep the description/iframe */
            .wc_payment_method.payment_method_briqpay {
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
        </style>';

        // 2. Nuclear JS Sentinel: MutationObserver PLUS high-frequency setInterval (10ms)
        // This catches React hydration before the browser can even paint the next frame.
        echo '<script id="briqpay-critical-js">
            (function() {
                var checkAndKill = function() {
                    // Only run if Briqpay is supposedly active (body class added by PHP)
                    if (document.body && !document.body.classList.contains("briqpay-selected")) {
                        // If we already marked it as explicitly NOT selected via JS, stop.
                        if (document.body.classList.contains("briqpay-not-selected")) return;
                    }
                    
                    var selectors = [
                        "#place_order",
                        ".form-row.place-order",
                        ".wc-block-checkout__actions",
                        ".wc-block-components-checkout-place-order-button",
                        "[data-testid=\"wc-block-components-checkout-place-order-button\"]"
                    ];
                    
                    selectors.forEach(function(selector) {
                        var elements = document.querySelectorAll(selector);
                        for (var i = 0; i < elements.length; i++) {
                            var el = elements[i];
                            if (el.style.display !== "none") {
                                el.style.setProperty("display", "none", "important");
                                el.style.setProperty("visibility", "hidden", "important");
                                el.style.setProperty("pointer-events", "none", "important");
                                el.setAttribute("aria-hidden", "true");
                                el.setAttribute("disabled", "disabled");
                            }
                        }
                    });
                };

                // Run immediately
                checkAndKill();

                // High-frequency sentinel for the first 5 seconds (React hydration window)
                var sentinel = setInterval(checkAndKill, 50);
                setTimeout(function() { clearInterval(sentinel); }, 15000);

                // Safety net: MutationObserver
                var observer = new MutationObserver(checkAndKill);
                observer.observe(document.documentElement, { childList: true, subtree: true });
                setTimeout(function() { observer.disconnect(); }, 20000);
            })();
        </script>';
    }

    /**
     * Add Body Class for styling
     */
    public function add_body_class($classes)
    {
        if (is_checkout() && !is_order_received_page()) {
            $settings = get_option('woocommerce_briqpay_settings');
            if ('yes' === ($settings['enabled'] ?? 'no')) {
                $classes[] = 'briqpay-selected';
            }
        }
        return $classes;
    }

    /**
     * Filter attribution origin label
     */
    public function filter_attribution_origin_label($label, $source_type, $source)
    {
        if ($source === 'Briqpay') {
            return 'Briqpay';
        }
        return $label;
    }

    /**
     * Force Briqpay as Origin if missing
     */
    public function force_briqpay_origin($created_via, $order)
    {
        if ($order->get_payment_method() === 'briqpay') {
            if (empty($created_via) || 'checkout' === $created_via || 'unknown' === strtolower($created_via)) {
                return 'Briqpay';
            }
        }
        return $created_via;
    }

    /**
     * Handle WC Checkout Order Processed
     * This handles the case where the native WC checkout button is used.
     */
    public function handle_checkout_order_processed($order_id, $posted_data, $order)
    {
        if ($order->get_payment_method() !== 'briqpay') {
            return;
        }

        $session_id = Session_Manager::get_session_id();
        if ($session_id) {
            $order->update_meta_data('_briqpay_session_id', $session_id);
            $order->set_created_via('Briqpay');
            $order->update_meta_data('_created_via', 'Briqpay');
            $order->update_meta_data('_order_origin', 'Briqpay');
            // WC Order Attribution
            $order->update_meta_data('_wc_order_attribution_source_type', 'utm');
            $order->update_meta_data('_wc_order_attribution_utm_source', 'Briqpay');
            $order->set_status('pending', __('Order created via native checkout. Awaiting Briqpay decision.', 'briqpay-for-woocommerce'));
            $order->save();
        }
    }

    /**
     * Handle Return from Briqpay
     */
    public function handle_briqpay_return()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['briqpay_return'])) {
            return;
        }

        $this->log('handle_briqpay_return() triggered.');

        // Get session ID from WC session
        $session_id = Session_Manager::get_session_id();

        if (!$session_id) {
            $this->log('Error: No session ID found in WC session.');
            wc_add_notice(__('Payment session not found.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Find the existing temporary order
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $order = $this->get_order_by_session_id($session_id);

        if (!$order) {
            $this->log('Warning: No temporary order found for session. This should not happen.');
            wc_add_notice(__('Order not found.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $this->log('Found order: ' . $order->get_id() . ' with status: ' . $order->get_status());

        // Get session from Briqpay to verify it's approved and get PSP name
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            $this->log('Error retrieving session: ' . $session->get_error_message());
            wc_add_notice(__('Could not verify payment.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Fully robust PSP name lookup
        $psp_name = 'Briqpay';

        // 1. Check paymentMethod.name (User says this worked)
        if (!empty($session['paymentMethod']['name'])) {
            $psp_name = $session['paymentMethod']['name'];
        }
        // 2. Check transactions (V3 data structure)
        elseif (!empty($session['data']['transactions'])) {
            foreach ($session['data']['transactions'] as $tx) {
                if (!empty($tx['pspDisplayName'])) {
                    $psp_name = $tx['pspDisplayName'];
                    break;
                }
            }
        }
        // 3. Fallback to any sensible field
        elseif (!empty($session['data']['paymentMethod']['name'])) {
            $psp_name = $session['data']['paymentMethod']['name'];
        }

        $this->log('Resolved PSP Name: ' . $psp_name);

        // Update order with PSP name, Origin and Extended Metadata
        $order->set_payment_method_title($psp_name);
        $order->set_created_via('Briqpay');
        $order->update_meta_data('_created_via', 'Briqpay');
        $order->update_meta_data('_order_origin', 'Briqpay');
        // WC Order Attribution
        $order->update_meta_data('_wc_order_attribution_source_type', 'utm');
        $order->update_meta_data('_wc_order_attribution_utm_source', 'Briqpay');
        $order->update_meta_data('_briqpay_psp_name', $psp_name);

        // Extended metadata for Admin Box
        if (!empty($session['clientToken'])) {
            $order->update_meta_data('_briqpay_client_token', $session['clientToken']);
        }

        if (!empty($session['purchaseSession']['pspIntegrationName'])) {
            $order->update_meta_data('_briqpay_psp_integration_name', $session['purchaseSession']['pspIntegrationName']);
        } elseif (!empty($session['data']['transactions'][0]['pspIntegrationName'])) {
            $order->update_meta_data('_briqpay_psp_integration_name', $session['data']['transactions'][0]['pspIntegrationName']);
        }

        if (!empty($session['purchaseSession']['reservationId'])) {
            $order->update_meta_data('_briqpay_reservation_id', $session['purchaseSession']['reservationId']);
        } elseif (!empty($session['data']['transactions'][0]['reservationId'])) {
            $order->update_meta_data('_briqpay_reservation_id', $session['data']['transactions'][0]['reservationId']);
        }

        $order->save();

        // B2B specific cleanup - Must happen BEFORE early exit to ensure data reset
        $is_b2b = (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_b2b_active')) || (isset($_COOKIE['briqpay_b2b_active']) && $_COOKIE['briqpay_b2b_active'] === '1');
        if ($is_b2b) {
            $this->log('B2B Checkout detected. Resetting customer session and fail-safe cookies.');

            // Clear all flags and cookies
            if (null !== WC() && null !== WC()->session) {
                WC()->session->set('briqpay_b2b_active', false);
                WC()->session->set('briqpay_customer_type', null);
                WC()->session->set('briqpay_prev_b2b_active', null);
            }
            Session_Manager::set_session_id(null); // This clears both session and cookie

            setcookie('briqpay_b2b_active', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);

            // Reset customer address data to prevent persistence for next business user
            if (null !== WC() && null !== WC()->customer) {
                $this->log('Clearing customer address data for B2B cleanup.');
                WC()->customer->set_billing_first_name('');
                WC()->customer->set_billing_last_name('');
                WC()->customer->set_billing_company('');
                WC()->customer->set_billing_address_1('');
                WC()->customer->set_billing_address_2('');
                WC()->customer->set_billing_city('');
                WC()->customer->set_billing_state('');
                WC()->customer->set_billing_postcode('');
                WC()->customer->set_billing_country('');
                WC()->customer->set_billing_email('');
                WC()->customer->set_billing_phone('');

                WC()->customer->set_shipping_first_name('');
                WC()->customer->set_shipping_last_name('');
                WC()->customer->set_shipping_company('');
                WC()->customer->set_shipping_address_1('');
                WC()->customer->set_shipping_address_2('');
                WC()->customer->set_shipping_city('');
                WC()->customer->set_shipping_state('');
                WC()->customer->set_shipping_postcode('');
                WC()->customer->set_shipping_country('');
                WC()->customer->save();
            }

            if (null !== WC() && null !== WC()->session) {
                WC()->session->save_data();
            }
        }

        // If already upgraded to pending, just redirect now after updating title
        if ($order->has_status(array('pending', 'processing', 'completed'))) {
            $this->log('Order already processed. Redirecting to order received page.');
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // Verify session is approved/completed
        $order_status = $session['order']['status'] ?? ($session['status'] ?? '');
        $this->log('Session order status: ' . $order_status);

        if ($order_status !== 'completed') {
            $this->log('Session not completed. Status: ' . $order_status);
            // translators: %s: session status
            $order->add_order_note(sprintf(__('Payment verification failed. Status: %s', 'briqpay-for-woocommerce'), $order_status));
            wc_add_notice(__('Payment not approved.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Upgrade order status to pending
        $order->update_status('pending', __('Briqpay session verified. Awaiting webhook confirmation.', 'briqpay-for-woocommerce'));
        $order->save();
        $this->log('Order upgraded to pending: ' . $order->get_id());

        /**
         * Action after payment is verified and order upgraded.
         *
         * @param WC_Order $order   The WooCommerce order.
         * @param array    $session Briqpay session from API.
         */
        do_action('briqpay_payment_complete', $order, $session);

        // Clear cart
        WC()->cart->empty_cart();

        // Redirect to order received page
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * Get Order by Session ID
     */
    private function get_order_by_session_id($session_id)
    {
        $orders = wc_get_orders(array(
            'meta_key' => '_briqpay_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'limit' => 1,
        ));
        return !empty($orders) ? reset($orders) : null;
    }

    /**
     * AJAX: Get Session
     */
    public function ajax_get_session()
    {
        check_ajax_referer('briqpay_nonce', 'nonce');
        $this->log('ajax_get_session() triggered.');
        $this->log('POST data: ' . json_encode($_POST));

        // Capture total BEFORE processing to detect changes
        $total_before = WC()->cart->get_total('edit');

        // Handle Blocks Data
        if (isset($_POST['blocks_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and each field sanitized individually below
            $blocks_data = json_decode(wp_unslash($_POST['blocks_data']), true);
            if ($blocks_data) {
                $this->log('Updating WC Customer from blocks data: ' . json_encode($blocks_data));
                // Update Billing
                if (isset($blocks_data['billing_address'])) {
                    $b = $blocks_data['billing_address'];
                    if (isset($b['first_name']))
                        WC()->customer->set_billing_first_name(sanitize_text_field($b['first_name']));
                    if (isset($b['last_name']))
                        WC()->customer->set_billing_last_name(sanitize_text_field($b['last_name']));
                    if (isset($b['address_1']))
                        WC()->customer->set_billing_address_1(sanitize_text_field($b['address_1']));
                    if (isset($b['city']))
                        WC()->customer->set_billing_city(sanitize_text_field($b['city']));
                    if (isset($b['postcode']))
                        WC()->customer->set_billing_postcode(sanitize_text_field($b['postcode']));
                    if (isset($b['country']))
                        WC()->customer->set_billing_country(sanitize_text_field($b['country']));
                    if (isset($b['email']))
                        WC()->customer->set_billing_email(sanitize_email($b['email']));
                    if (isset($b['phone']))
                        WC()->customer->set_billing_phone(sanitize_text_field($b['phone']));
                    if (isset($b['company']))
                        WC()->customer->set_billing_company(sanitize_text_field($b['company']));
                }

                // Update Shipping
                if (isset($blocks_data['shipping_address'])) {
                    $s = $blocks_data['shipping_address'];
                    if (isset($s['first_name']))
                        WC()->customer->set_shipping_first_name(sanitize_text_field($s['first_name']));
                    if (isset($s['last_name']))
                        WC()->customer->set_shipping_last_name(sanitize_text_field($s['last_name']));
                    if (isset($s['address_1']))
                        WC()->customer->set_shipping_address_1(sanitize_text_field($s['address_1']));
                    if (isset($s['city']))
                        WC()->customer->set_shipping_city(sanitize_text_field($s['city']));
                    if (isset($s['postcode']))
                        WC()->customer->set_shipping_postcode(sanitize_text_field($s['postcode']));
                    if (isset($s['country']))
                        WC()->customer->set_shipping_country(sanitize_text_field($s['country']));
                }

                // Update chosen shipping methods from blocks data if present
                if (isset($blocks_data['shipping_rates'])) {
                    $chosen_methods = array();
                    foreach ($blocks_data['shipping_rates'] as $package_index => $rate_id) {
                        $chosen_methods[$package_index] = $rate_id;
                    }
                    $this->log('Setting chosen shipping methods from BLOCKS: ' . json_encode($chosen_methods));
                    WC()->session->set('chosen_shipping_methods', $chosen_methods);
                }

                WC()->customer->save();
            }
        }

        // Update WC Customer data from posted form data if available (Classic Checkout)
        if (isset($_POST['checkout_data'])) {
            $checkout_data = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- data is sanitized per-field below
            parse_str(wp_unslash($_POST['checkout_data']), $checkout_data);

            if (!empty($checkout_data)) {
                $this->log('Updating WC Customer from checkout data.');
                $this->log('Raw checkout_data: ' . json_encode($checkout_data));
            } else {
                $this->log('checkout_data found but is empty.');
            }
        } else {
            $this->log('checkout_data is COMPLETELY MISSING from POST.');
        }

        // Only proceed if we have checkout_data or blocks_data
        if (isset($checkout_data) || isset($blocks_data)) {
            if (isset($checkout_data) && !empty($checkout_data)) {

                // Update billing address
                if (isset($checkout_data['billing_first_name']))
                    WC()->customer->set_billing_first_name(sanitize_text_field($checkout_data['billing_first_name']));
                if (isset($checkout_data['billing_last_name']))
                    WC()->customer->set_billing_last_name(sanitize_text_field($checkout_data['billing_last_name']));
                if (isset($checkout_data['billing_company']))
                    WC()->customer->set_billing_company(sanitize_text_field($checkout_data['billing_company']));
                if (isset($checkout_data['billing_address_1']))
                    WC()->customer->set_billing_address_1(sanitize_text_field($checkout_data['billing_address_1']));
                if (isset($checkout_data['billing_address_2']))
                    WC()->customer->set_billing_address_2(sanitize_text_field($checkout_data['billing_address_2']));
                if (isset($checkout_data['billing_city']))
                    WC()->customer->set_billing_city(sanitize_text_field($checkout_data['billing_city']));
                if (isset($checkout_data['billing_postcode']))
                    WC()->customer->set_billing_postcode(sanitize_text_field($checkout_data['billing_postcode']));
                if (isset($checkout_data['billing_country']))
                    WC()->customer->set_billing_country(sanitize_text_field($checkout_data['billing_country']));
                if (isset($checkout_data['billing_state']))
                    WC()->customer->set_billing_state(sanitize_text_field($checkout_data['billing_state']));
                if (isset($checkout_data['billing_email']))
                    WC()->customer->set_billing_email(sanitize_email($checkout_data['billing_email']));
                if (isset($checkout_data['billing_phone']))
                    WC()->customer->set_billing_phone(sanitize_text_field($checkout_data['billing_phone']));

                if (isset($checkout_data['shipping_method'])) {
                    $methods = is_array($checkout_data['shipping_method']) ? $checkout_data['shipping_method'] : array($checkout_data['shipping_method']);
                    $this->log('Setting chosen shipping methods: ' . json_encode($methods));
                    WC()->session->set('chosen_shipping_methods', $methods);
                }

                // Update shipping address
                if (isset($checkout_data['ship_to_different_address']) && $checkout_data['ship_to_different_address']) {
                    if (isset($checkout_data['shipping_first_name']))
                        WC()->customer->set_shipping_first_name(sanitize_text_field($checkout_data['shipping_first_name']));
                    if (isset($checkout_data['shipping_last_name']))
                        WC()->customer->set_shipping_last_name(sanitize_text_field($checkout_data['shipping_last_name']));
                    if (isset($checkout_data['shipping_address_1']))
                        WC()->customer->set_shipping_address_1(sanitize_text_field($checkout_data['shipping_address_1']));
                    if (isset($checkout_data['shipping_address_2']))
                        WC()->customer->set_shipping_address_2(sanitize_text_field($checkout_data['shipping_address_2']));
                    if (isset($checkout_data['shipping_city']))
                        WC()->customer->set_shipping_city(sanitize_text_field($checkout_data['shipping_city']));
                    if (isset($checkout_data['shipping_postcode']))
                        WC()->customer->set_shipping_postcode(sanitize_text_field($checkout_data['shipping_postcode']));
                    if (isset($checkout_data['shipping_country']))
                        WC()->customer->set_shipping_country(sanitize_text_field($checkout_data['shipping_country']));
                    if (isset($checkout_data['shipping_state']))
                        WC()->customer->set_shipping_state(sanitize_text_field($checkout_data['shipping_state']));
                } else {
                    // Copy billing to shipping if same address
                    WC()->customer->set_shipping_first_name(WC()->customer->get_billing_first_name());
                    WC()->customer->set_shipping_last_name(WC()->customer->get_billing_last_name());
                    WC()->customer->set_shipping_address_1(WC()->customer->get_billing_address_1());
                    WC()->customer->set_shipping_address_2(WC()->customer->get_billing_address_2());
                    WC()->customer->set_shipping_city(WC()->customer->get_billing_city());
                    WC()->customer->set_shipping_postcode(WC()->customer->get_billing_postcode());
                    WC()->customer->set_shipping_country(WC()->customer->get_billing_country());
                    WC()->customer->set_shipping_state(WC()->customer->get_billing_state());
                }

            }
        }

        WC()->customer->save();
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $total_after = WC()->cart->get_total('edit');
        $this->log(sprintf('Total Check: Before=%s, After=%s', $total_before, $total_after));

        if ($total_before !== $total_after) {
            // Allow session to be patched (suspend/resume) even when totals change.
            // Session_Manager::get_or_create_session() handles updates via PATCH.
            $this->log('Total changed, session will be patched (not regenerated).');
        }

        $this->log('Recalculated WC Total: ' . $total_after . ' (Shipping: ' . WC()->cart->get_shipping_total() . ')');

        $session_manager = new Session_Manager();
        $session = $session_manager->get_or_create_session();

        if (is_wp_error($session)) {
            $this->log('AJAX Error: ' . $session->get_error_message());
            wp_send_json_error(array('message' => $session->get_error_message()));
        }

        $this->log('AJAX Success.');
        WC()->session->save_data();
        wp_send_json_success($session);
    }

    /**
     * AJAX: Make Decision
     */
    public function ajax_make_decision()
    {
        check_ajax_referer('briqpay_nonce', 'nonce');

        $session_id = isset($_POST['sessionId']) ? sanitize_text_field(wp_unslash($_POST['sessionId'])) : '';
        if (!$session_id) {
            wp_send_json_error(array('message' => 'Missing session ID'));
        }

        $this->log('ajax_make_decision() triggered for session: ' . $session_id);

        // 1. Get session from Briqpay
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            $this->log('Error retrieving session: ' . $session->get_error_message());
            wp_send_json_error(array('message' => 'Could not retrieve session'));
        }

        /**
         * Action before the decision is made.
         *
         * @param string $session_id Briqpay session ID.
         * @param array  $session    Full session data from API.
         */
        do_action('briqpay_before_make_decision', $session_id, $session);

        // Sync Customer Address from Briqpay session before making decision
        // This ensures WC calculations (shipping/taxes) are based on the final session address.
        if (isset($session['data']['billing'])) {
            $b = $session['data']['billing'];
            WC()->customer->set_billing_first_name($b['firstName'] ?? '');
            WC()->customer->set_billing_last_name($b['lastName'] ?? '');
            WC()->customer->set_billing_email($b['email'] ?? '');
            WC()->customer->set_billing_address_1($b['streetAddress'] ?? '');
            WC()->customer->set_billing_address_2($b['streetAddress2'] ?? '');
            WC()->customer->set_billing_postcode($b['zip'] ?? '');
            WC()->customer->set_billing_city($b['city'] ?? '');
            WC()->customer->set_billing_state($b['region'] ?? '');
            WC()->customer->set_billing_country($b['country'] ?? '');
            WC()->customer->set_billing_phone($b['phoneNumber'] ?? '');
        }

        if (isset($session['data']['shipping'])) {
            $s = $session['data']['shipping'];
            WC()->customer->set_shipping_first_name($s['firstName'] ?? '');
            WC()->customer->set_shipping_last_name($s['lastName'] ?? '');
            WC()->customer->set_shipping_address_1($s['streetAddress'] ?? '');
            WC()->customer->set_shipping_address_2($s['streetAddress2'] ?? '');
            WC()->customer->set_shipping_postcode($s['zip'] ?? '');
            WC()->customer->set_shipping_city($s['city'] ?? '');
            WC()->customer->set_shipping_state($s['region'] ?? '');
            WC()->customer->set_shipping_country($s['country'] ?? '');
        }
        WC()->customer->save();
        WC()->cart->calculate_totals();

        // 2. Create order at decision point
        try {
            $order = $this->create_order_at_decision($session);
            $this->log('Order created at decision: ' . $order->get_id());

            // Always map billing/shipping from Briqpay session to the order.
            // create_order_at_decision() may reuse an existing draft that has no address data
            // (common in classic checkout). In blocks checkout, the data matches what the
            // Store API already set, so this is safe for both flows.
            if (isset($session['data']['billing']) && !empty($session['data']['billing'])) {
                $b = $session['data']['billing'];
                $order->set_billing_first_name($b['firstName'] ?? '');
                $order->set_billing_last_name($b['lastName'] ?? '');
                $order->set_billing_email($b['email'] ?? '');
                $order->set_billing_address_1($b['streetAddress'] ?? '');
                $order->set_billing_address_2($b['streetAddress2'] ?? '');
                $order->set_billing_postcode($b['zip'] ?? '');
                $order->set_billing_city($b['city'] ?? '');
                $order->set_billing_state($b['region'] ?? '');
                $order->set_billing_country($b['country'] ?? '');
                $order->set_billing_phone($b['phoneNumber'] ?? '');
            }

            if (isset($session['data']['shipping']) && !empty($session['data']['shipping'])) {
                $s = $session['data']['shipping'];
                $order->set_shipping_first_name($s['firstName'] ?? '');
                $order->set_shipping_last_name($s['lastName'] ?? '');
                $order->set_shipping_address_1($s['streetAddress'] ?? '');
                $order->set_shipping_address_2($s['streetAddress2'] ?? '');
                $order->set_shipping_postcode($s['zip'] ?? '');
                $order->set_shipping_city($s['city'] ?? '');
                $order->set_shipping_state($s['region'] ?? '');
                $order->set_shipping_country($s['country'] ?? '');
            }

            $order->save();

            // 3. Store session ID in WC session for return handler
            Session_Manager::set_session_id($session_id);

            // 4. Update metadata with actual order ID
            $api->update_metadata($session_id, array(
                'references' => array(
                    'reference1' => (string) $order->get_id()
                )
            ));

            // 5. Make Decision
            /**
             * Filter the decision value before sending to Briqpay.
             *
             * Return 'allow' to approve, or an array for rejection:
             * ['decision' => 'reject', 'rejectionType' => 'notify_user', 'softErrors' => [['message' => '...']]]
             *
             * @param string $decision   Default decision ('allow').
             * @param string $session_id Briqpay session ID.
             * @param array  $session    Full session data from API.
             */

            // Validate data integrity before approving
            $validation = $this->validate_data_integrity($session);
            $initial_decision = 'allow';

            if (!$validation['valid']) {
                $this->log('Validation failed: ' . implode(', ', $validation['errors']));
                $initial_decision = array(
                    'decision' => 'reject',
                    'rejectionType' => 'notify_user',
                    'softErrors' => array()
                );

                $has_user_error = false;
                $whitelist = array(
                    __('Please select a shipping method.', 'briqpay-for-woocommerce'),
                    __('No shipping methods are available for your address.', 'briqpay-for-woocommerce')
                );

                foreach ($validation['errors'] as $err) {
                    if (in_array($err, $whitelist, true)) {
                        $initial_decision['softErrors'][] = array('message' => $err);
                        $has_user_error = true;
                    }
                }

                if (!$has_user_error) {
                    $initial_decision['softErrors'][] = array('message' => __('Something went wrong, please try again', 'briqpay-for-woocommerce'));
                }
            }

            $decision = apply_filters('briqpay_decision_value', $initial_decision, $session_id, $session);

            // Handle reject decision with softErrors
            if (is_array($decision) && isset($decision['decision']) && $decision['decision'] === 'reject') {
                $this->log('Decision overridden to REJECT by filter for session: ' . $session_id);
                $reject_payload = array(
                    'decision' => 'reject',
                    'rejectionType' => $decision['rejectionType'] ?? 'notify_user',
                );
                if (!empty($decision['softErrors'])) {
                    $reject_payload['softErrors'] = $decision['softErrors'];
                }
                $decision_result = $api->request('POST', '/v3/session/' . $session_id . '/decision', $reject_payload);
            } else {
                $decision_result = $api->make_decision($session_id, 'allow');
            }

            if (is_wp_error($decision_result)) {
                $this->log('Decision API error: ' . $decision_result->get_error_message());
                wp_send_json_error(array('message' => 'Decision failed'));
            }

            /**
             * Action after the decision has been made.
             *
             * @param array|WP_Error $decision_result API response from decision call.
             * @param string         $session_id      Briqpay session ID.
             * @param WC_Order       $order           The WooCommerce order.
             */
            do_action('briqpay_after_make_decision', $decision_result, $session_id, $order);

            $this->log('Decision processed for session: ' . $session_id);

            // Return success - order will be upgraded to pending on return
            wp_send_json_success(array(
                'message' => 'Decision processed',
                'session_id' => $session_id,
                'order_id' => $order->get_id()
            ));
        } catch (\Exception $e) {
            $this->log('Error creating order: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Create Order at Decision Point
     */
    private function create_order_at_decision($session)
    {
        $session_id = $session['sessionId'];

        /**
         * Action before order creation at the decision point.
         *
         * @param array $session Full Briqpay session data.
         */
        do_action('briqpay_before_create_order', $session);

        // 1. Check if we already have an order for this session
        $existing_order = $this->get_order_by_session_id($session_id);
        if ($existing_order) {
            $this->log('Found existing order for session: ' . $existing_order->get_id());
            return $existing_order;
        }

        // 2. Check if WC has an order awaiting payment in session
        $order_id = WC()->session->get('order_awaiting_payment');
        if (!$order_id) {
            $order_id = WC()->session->get('store_api_draft_order', 0); // Blocks specific
        }

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->has_status(array('pending', 'on-hold', 'checkout-draft', 'draft', 'auto-draft'))) {
                $this->log('Reusing existing WC order from session: ' . $order_id . ' (Status: ' . $order->get_status() . ')');
                $order->update_meta_data('_briqpay_session_id', $session_id);
                $order->save();
                return $order;
            }
        }

        // 2b. Fallback: Search for any draft order with same customer/session if not in WC session
        $this->log('No order in WC session, searching database for recent drafts.');
        $recent_orders = wc_get_orders(array(
            'customer' => get_current_user_id() ?: 0,
            'status' => array('checkout-draft', 'draft', 'auto-draft'),
            'limit' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ($recent_orders as $ro) {
            // If it's very recent (e.g. 10 mins) and has no Briqpay session or same session
            if (time() - $ro->get_date_created()->getTimestamp() < 600) {
                $ro_session = $ro->get_meta('_briqpay_session_id');
                if (!$ro_session || $ro_session === $session_id) {
                    $this->log('Found recent draft in DB to reuse: ' . $ro->get_id());
                    $ro->set_created_via('Briqpay');
                    $ro->update_meta_data('_created_via', 'Briqpay');
                    $ro->update_meta_data('_order_origin', 'Briqpay');
                    // WC Order Attribution
                    $ro->update_meta_data('_wc_order_attribution_source_type', 'utm');
                    $ro->update_meta_data('_wc_order_attribution_utm_source', 'Briqpay');
                    $ro->update_meta_data('_briqpay_session_id', $session_id);
                    $ro->save();
                    return $ro;
                }
            }
        }

        // 3. Create new order if none found
        $this->log('Creating new manual order at decision point.');
        $order = wc_create_order(array(
            'customer_id' => get_current_user_id() ?: 0,
            'status' => 'pending',
            'created_via' => 'Briqpay',
        ));
        $order->update_meta_data('_wc_order_attribution_source_type', 'utm');
        $order->update_meta_data('_wc_order_attribution_utm_source', 'Briqpay');
        $order->save();

        if (is_wp_error($order)) {
            throw new \Exception('Could not create order');
        }

        // Set this order as the one awaiting payment in session to prevent WC from creating another
        WC()->session->set('order_awaiting_payment', $order->get_id());

        // Copy cart items
        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
            $item_id = $order->add_product($values['data'], $values['quantity'], array(
                'variation' => $values['variation'],
                'totals' => array(
                    'subtotal' => $values['line_subtotal'],
                    'subtotal_tax' => $values['line_subtotal_tax'],
                    'total' => $values['line_total'],
                    'tax' => $values['line_tax'],
                    'tax_data' => $values['line_tax_data'],
                ),
            ));
        }

        // Set shipping
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        if (!empty($chosen_methods)) {
            $shipping_packages = WC()->shipping()->get_packages();
            foreach ($shipping_packages as $i => $package) {
                if (isset($chosen_methods[$i], $package['rates'][$chosen_methods[$i]])) {
                    $rate = $package['rates'][$chosen_methods[$i]];
                    $item = new \WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => $rate->label,
                        'method_id' => $rate->method_id,
                        'instance_id' => $rate->instance_id,
                        'total' => wc_format_decimal($rate->cost),
                        'taxes' => array('total' => $rate->taxes),
                    ));
                    $order->add_item($item);
                }
            }
        }

        // Get PSP display name from session data using robust lookup
        $psp_name = 'Briqpay';
        if (!empty($session['paymentMethod']['name'])) {
            $psp_name = $session['paymentMethod']['name'];
        } elseif (!empty($session['data']['transactions'])) {
            foreach ($session['data']['transactions'] as $tx) {
                if (!empty($tx['pspDisplayName'])) {
                    $psp_name = $tx['pspDisplayName'];
                    break;
                }
            }
        }

        $this->log('Initial PSP Name at decision: ' . $psp_name);

        $order->set_payment_method('briqpay');
        $order->set_payment_method_title($psp_name);
        $order->update_meta_data('_briqpay_session_id', $session['sessionId']);
        $order->update_meta_data('_briqpay_psp_name', $psp_name);

        // Extended metadata for Admin Box
        if (!empty($session['clientToken'])) {
            $order->update_meta_data('_briqpay_client_token', $session['clientToken']);
        }

        if (!empty($session['purchaseSession']['pspIntegrationName'])) {
            $order->update_meta_data('_briqpay_psp_integration_name', $session['purchaseSession']['pspIntegrationName']);
        } elseif (!empty($session['data']['transactions'][0]['pspIntegrationName'])) {
            $order->update_meta_data('_briqpay_psp_integration_name', $session['data']['transactions'][0]['pspIntegrationName']);
        }

        if (!empty($session['purchaseSession']['reservationId'])) {
            $order->update_meta_data('_briqpay_reservation_id', $session['purchaseSession']['reservationId']);
        } elseif (!empty($session['data']['transactions'][0]['reservationId'])) {
            $order->update_meta_data('_briqpay_reservation_id', $session['data']['transactions'][0]['reservationId']);
        }

        // 4. Map billing/shipping from session
        $b = $session['data']['billing'] ?? array();
        if (!empty($b)) {
            $order->set_billing_first_name($b['firstName'] ?? '');
            $order->set_billing_last_name($b['lastName'] ?? '');
            $order->set_billing_email($b['email'] ?? '');
            $order->set_billing_address_1($b['streetAddress'] ?? '');
            $order->set_billing_address_2($b['streetAddress2'] ?? '');
            $order->set_billing_postcode($b['zip'] ?? '');
            $order->set_billing_city($b['city'] ?? '');
            $order->set_billing_state($b['region'] ?? '');
            $order->set_billing_country($b['country'] ?? '');
            $order->set_billing_phone($b['phoneNumber'] ?? '');
        }

        $s = $session['data']['shipping'] ?? array();
        if (!empty($s)) {
            $order->set_shipping_first_name($s['firstName'] ?? '');
            $order->set_shipping_last_name($s['lastName'] ?? '');
            $order->set_shipping_address_1($s['streetAddress'] ?? '');
            $order->set_shipping_address_2($s['streetAddress2'] ?? '');
            $order->set_shipping_postcode($s['zip'] ?? '');
            $order->set_shipping_city($s['city'] ?? '');
            $order->set_shipping_state($s['region'] ?? '');
            $order->set_shipping_country($s['country'] ?? '');
        }

        $order->calculate_totals();

        /**
         * Filter additional metadata to store on the order.
         *
         * @param array    $metadata Key-value pairs of meta to add.
         * @param WC_Order $order    The WooCommerce order.
         * @param array    $session  Briqpay session data.
         */
        $extra_meta = apply_filters('briqpay_order_metadata', array(), $order, $session);
        foreach ($extra_meta as $key => $value) {
            $order->update_meta_data($key, $value);
        }

        $order->save();

        /**
         * Action after an order is created/found at the decision point.
         *
         * @param WC_Order $order   The WooCommerce order.
         * @param array    $session Briqpay session data.
         */
        do_action('briqpay_after_create_order', $order, $session);

        return $order;
    }

    /**
     * Validate Data Integrity
     */
    private function validate_data_integrity($session)
    {
        $errors = array();

        // Use Session_Manager to calculate expected payload
        $session_manager = new Session_Manager();
        $wc_data = $session_manager->get_session_data(true); // Get update payload

        $this->log('Validating Session Integrity...');
        $this->log('BP Data: ' . json_encode($session['data']['order'] ?? array()));
        $this->log('WC Data: ' . json_encode($wc_data['data']['order'] ?? array()));

        // Validate Totals
        // Briqpay uses integers (minor units) or floats. Session data usually has ints for amounts if strictly typed on backend?
        // PHP session array from json_decode usually has ints or floats.
        // Let's compare as integers/floats with strict equality.

        $bp_amount = $session['data']['order']['amountIncVat'] ?? 0;
        $wc_amount = $wc_data['data']['order']['amountIncVat'] ?? 0;

        // Use epsilon for float comparison safety or round to 2 decimals then strict
        // Briqpay amounts are usually integer cents? No, depends on currency.
        // Usually V3 APIs use standard currency formatting.
        // Let's assume strict equality on what `get_session_data` produces vs what API returns.

        if (abs($bp_amount - $wc_amount) > 0.01) {
            $errors[] = "Amount mismatch: WC {$wc_amount} vs BP {$bp_amount}";
        }

        $bp_currency = $session['data']['order']['currency'] ?? '';
        $wc_currency = $wc_data['data']['order']['currency'] ?? '';
        if ($bp_currency !== $wc_currency) {
            $errors[] = "Currency mismatch: WC {$wc_currency} vs BP {$bp_currency}";
        }

        // Validate Address Fields
        if (isset($session['data']['billing']) && isset($wc_data['data']['billing'])) {
            $this->validate_address_fields($wc_data['data']['billing'], $session['data']['billing'], 'Billing', $errors);
        }

        if (isset($session['data']['shipping']) && isset($wc_data['data']['shipping'])) {
            $this->validate_address_fields($wc_data['data']['shipping'], $session['data']['shipping'], 'Shipping', $errors);
        }

        // Validate Shipping Selection
        if (WC()->cart->needs_shipping()) {
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $chosen_methods = is_array($chosen_methods) ? $chosen_methods : array();

            // Check if any shipping rates are actually available for this package
            $packages = WC()->shipping()->get_packages();
            $available_rate_ids = array();
            foreach ($packages as $package) {
                if (!empty($package['rates'])) {
                    foreach (array_keys($package['rates']) as $rate_id) {
                        $available_rate_ids[] = (string) $rate_id;
                    }
                }
            }

            $valid_selection = false;
            foreach ($chosen_methods as $chosen) {
                if (in_array((string) $chosen, $available_rate_ids, true)) {
                    $valid_selection = true;
                    break;
                }
            }

            // Fallback for external iFrames/fees that might not be in standard packages (e.g., Ingrid, nShift)
            // If there's a shipping cost, we assume a valid external selection was made.
            if (!$valid_selection && (float) WC()->cart->get_shipping_total() > 0) {
                $valid_selection = true;
            }

            // Final check
            if (!$valid_selection) {
                if (!empty($available_rate_ids)) {
                    $errors[] = __('Please select a shipping method.', 'briqpay-for-woocommerce');
                } else {
                    $errors[] = __('No shipping methods are available for your address.', 'briqpay-for-woocommerce');
                }
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * Validate Address Fields (Helper)
     */
    private function validate_address_fields($wc_addr, $bp_addr, $type, &$errors)
    {
        // Critical fields only: Country, Zip, City
        $fields = array('country', 'zip', 'city');

        foreach ($fields as $field) {
            $wc_val = $wc_addr[$field] ?? '';
            $bp_val = $bp_addr[$field] ?? '';

            if ($this->normalize_address_field($wc_val) !== $this->normalize_address_field($bp_val)) {
                $errors[] = "$type $field mismatch: WC '$wc_val' vs BP '$bp_val'";
            }
        }
    }

    /**
     * Normalize Address Field
     */
    private function normalize_address_field($val)
    {
        // Remove whitespace, lowercase
        return strtolower(preg_replace('/\s+/', '', trim($val)));
    }
    /**
     * Render Briqpay Iframe Shortcode
     * 
     * @return string
     */
    public function render_briqpay_iframe()
    {
        return '<div id="briqpay-iframe-container"></div>';
    }
}
