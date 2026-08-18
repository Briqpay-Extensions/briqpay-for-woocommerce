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
     * Init
     */
    public function init()
    {
        add_action('wp_ajax_briqpay_get_session', array($this, 'ajax_get_session'));
        add_action('wp_ajax_nopriv_briqpay_get_session', array($this, 'ajax_get_session'));

        add_action('wp_ajax_briqpay_make_decision', array($this, 'ajax_make_decision'));
        add_action('wp_ajax_nopriv_briqpay_make_decision', array($this, 'ajax_make_decision'));

        add_action('template_redirect', array($this, 'handle_briqpay_return'), 5);
        add_action('woocommerce_thankyou', array($this, 'clear_customer_data_after_purchase'), 10, 1);
        add_filter('body_class', array($this, 'add_body_class'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_critical_assets'), 20);
        add_shortcode('briqpay_iframe', array($this, 'render_briqpay_iframe'));
    }

    /**
     * Filter Order Button HTML (Classic Checkout)
     */
    public function filter_order_button_html($button_html)
    {
        if (is_checkout() && !is_order_received_page() && null !== WC() && null !== WC()->session) {
            $chosen_payment_method = WC()->session->get('chosen_payment_method');
            if ('briqpay' === $chosen_payment_method) {
                return ''; // Remove the button entirely from HTML
            }
        }
        return $button_html;
    }

    /**
     * Enqueue critical CSS and JS to hide Place Order button instantly
     */
    public function enqueue_critical_assets()
    {
        if (!is_checkout() || is_order_received_page()) {
            return;
        }

        // Never fire on the cart page — is_checkout() can return true due to
        // the B2B shortcode's force_is_checkout filter, but the Nuclear CSS
        // must only target actual checkout pages.
        if (function_exists('is_cart') && is_cart()) {
            return;
        }

        $settings = get_option('woocommerce_briqpay_settings');
        if ('yes' !== ($settings['enabled'] ?? 'no')) {
            return;
        }

        // 1. Nuclear CSS: Target the primary button, any button in the action area, and the area itself.
        $critical_css = '
            /* Standard Checkout */
            body.briqpay-selected #place_order,
            body.briqpay-selected .form-row.place-order,
            /* Blocks Checkout Action Areas */
            body.briqpay-selected .wc-block-checkout__actions,
            body.briqpay-selected .wc-block-checkout__actions button,
            body.briqpay-selected .wc-block-components-checkout-place-order-button,
            body.briqpay-selected .wc-block-components-checkout-place-order-button button,
            body.briqpay-selected [data-testid="wc-block-components-checkout-place-order-button"],
            /* Any button that might be a primary action */
            body.briqpay-selected .wc-block-components-button.wc-block-components-checkout-place-order-button { 
                display: none !important; 
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                height: 1px !important;
                overflow: hidden !important;
                max-height: 1px !important;
            }

            /* Hide Payment Method Selection ONLY when Briqpay is the sole gateway */
            body.briqpay-only-gateway .wc_payment_method.payment_method_briqpay > label:first-child,
            body.briqpay-only-gateway .wc_payment_method.payment_method_briqpay > input[type="radio"],
            body.briqpay-only-gateway label[for="payment_method_briqpay"],
            body.briqpay-only-gateway .wc-block-components-radio-control__option[for*="briqpay"],
            body.briqpay-only-gateway label[for*="briqpay"] .wc-block-components-radio-control__label-group,
            body.briqpay-only-gateway .wc-block-components-checkout-payment-method-option--briqpay .wc-block-components-radio-control__option {
                display: none !important;
            }
            
            /* If it is the only method, hide the entire radio list item container but keep the description/iframe */
            body.briqpay-only-gateway .wc_payment_method.payment_method_briqpay {
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* When Briqpay is selected but NOT the only gateway, hide the iframe container for non-Briqpay methods */
            body.briqpay-not-selected #briqpay-iframe-container {
                display: none !important;
            }';

        wp_add_inline_style('briqpay-checkout-style', $critical_css);

        // 2. Nuclear JS Sentinel: MutationObserver PLUS high-frequency setInterval (10ms)
        $critical_js = '
            (function() {
                var checkAndKill = function() {
                    if (!document.body || !document.body.classList.contains("briqpay-selected")) {
                        return;
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

                checkAndKill();
                var sentinel = setInterval(checkAndKill, 50);
                setTimeout(function() { clearInterval(sentinel); }, 15000);

                var observer = new MutationObserver(checkAndKill);
                observer.observe(document.documentElement, { childList: true, subtree: true });
                setTimeout(function() { observer.disconnect(); }, 20000);
            })();';

        wp_add_inline_script('briqpay-checkout', $critical_js, 'before');
    }

    /**
     * Add Body Class for styling
     */
    public function add_body_class($classes)
    {
        if (is_checkout() && !is_order_received_page()) {
            $settings = get_option('woocommerce_briqpay_settings');
            if ('yes' === ($settings['enabled'] ?? 'no')) {
                $chosen_payment_method = (null !== WC() && null !== WC()->session) ? WC()->session->get('chosen_payment_method') : null;
                $available_gateways = function_exists('WC') && null !== WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
                $is_only_gateway = (1 === count($available_gateways) && isset($available_gateways['briqpay']));

                // Mirror WooCommerce's own wc_get_chosen_gateway() fallback: when nothing is
                // stored in session yet (or the stored choice is no longer available), WC
                // defaults to whichever gateway comes first in the list - which is not
                // necessarily Briqpay. Guessing "briqpay" here would wrongly hide the native
                // Place Order button while a different gateway is actually pre-selected.
                if (empty($chosen_payment_method) || !isset($available_gateways[$chosen_payment_method])) {
                    $default_gateway = reset($available_gateways);
                    $chosen_payment_method = $default_gateway ? $default_gateway->id : '';
                }

                if ($is_only_gateway || 'briqpay' === $chosen_payment_method) {
                    $classes[] = 'briqpay-selected';
                } else {
                    $classes[] = 'briqpay-not-selected';
                }

                if ($is_only_gateway) {
                    $classes[] = 'briqpay-only-gateway';
                }
            }
        }
        return $classes;
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

        Logger::log('handle_briqpay_return() triggered.');

        // Get session ID from WC session
        $session_id = Session_Manager::get_session_id();

        if (!$session_id) {
            Logger::log('Error: No session ID found in WC session.');
            wc_add_notice(__('Payment session not found.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Find the existing temporary order
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $order = $this->get_order_by_session_id($session_id);

        if (!$order) {
            Logger::log('Warning: No temporary order found for session. This should not happen.');
            wc_add_notice(__('Order not found.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        Logger::log('Found order: ' . $order->get_id() . ' with status: ' . $order->get_status());

        // Get session from Briqpay to verify it's approved and get PSP name
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            Logger::log('Error retrieving session: ' . $session->get_error_message());
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

        Logger::log('Resolved PSP Name: ' . $psp_name);

        // Update order with PSP name and Extended Metadata
        $order->set_payment_method_title($psp_name);
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

        $order->update_meta_data(
            '_briqpay_auto_capture_enabled',
            Order_Management::session_has_auto_capture_enabled($session) ? 'yes' : 'no'
        );

        Legacy_B2b_Meta::apply($order, $session);

        $order->save();

        // If already upgraded to pending, run cleanup and redirect
        if ($order->has_status(array('pending', 'processing', 'completed'))) {
            Logger::log('Order already processed. Running cleanup before redirect.');
            // Idempotent cart and session cleanup
            if (null !== WC()->cart && !WC()->cart->is_empty()) {
                WC()->cart->empty_cart();
            }
            Session_Manager::set_session_id(null);
            if (null !== WC()->session) {
                WC()->session->save_data();
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // Verify session is approved/completed BEFORE early redirect check
        $order_status = $session['order']['status'] ?? ($session['status'] ?? '');
        Logger::log('Session order status: ' . $order_status);

        if ($order_status !== 'completed') {
            Logger::log('Session not completed. Status: ' . $order_status);
            // translators: %s: session status
            $order->add_order_note(sprintf(__('Payment verification failed. Status: %s', 'briqpay-for-woocommerce'), $order_status));
            wc_add_notice(__('Payment not approved.', 'briqpay-for-woocommerce'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // B2B specific cleanup - Move here to only run on success
        $is_b2b = (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_b2b_active')) || (isset($_COOKIE['briqpay_b2b_active']) && $_COOKIE['briqpay_b2b_active'] === '1');
        if ($is_b2b) {
            Logger::log('B2B Checkout Success. Performing cleanup...');
            if (null !== WC() && null !== WC()->session) {
                WC()->session->set('briqpay_b2b_active', false);
                WC()->session->set('briqpay_customer_type', null);
                WC()->session->set('briqpay_prev_b2b_active', null);

                // Clear address so guests don't have their info remembered for next purchase
                if (null !== WC()->customer && get_current_user_id() === 0) {
                    Logger::log('Clearing guest customer address data.');
                    WC()->customer->set_billing_first_name('');
                    WC()->customer->set_billing_last_name('');
                    WC()->customer->set_billing_company('');
                    WC()->customer->set_billing_address_1('');
                    WC()->customer->set_billing_address_2('');
                    WC()->customer->set_billing_city('');
                    WC()->customer->set_billing_postcode('');
                    WC()->customer->set_billing_country('');
                    WC()->customer->set_billing_state('');
                    WC()->customer->set_billing_email('');
                    WC()->customer->set_billing_phone('');

                    WC()->customer->set_shipping_first_name('');
                    WC()->customer->set_shipping_last_name('');
                    WC()->customer->set_shipping_company('');
                    WC()->customer->set_shipping_address_1('');
                    WC()->customer->set_shipping_address_2('');
                    WC()->customer->set_shipping_city('');
                    WC()->customer->set_shipping_postcode('');
                    WC()->customer->set_shipping_country('');
                    WC()->customer->set_shipping_state('');
                    WC()->customer->save();
                }
            }
            Session_Manager::set_session_id(null);
            setcookie('briqpay_b2b_active', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }

        // Idempotent cart and session cleanup
        if (null !== WC()->cart && !WC()->cart->is_empty()) {
            WC()->cart->empty_cart();
        }
        if (null !== WC()->session) {
            WC()->session->save_data();
        }

        // Upgrade order status if not already processed
        if (!$order->has_status(array('pending', 'processing', 'completed'))) {
            $order->update_status('pending', __('Briqpay session verified. Awaiting webhook confirmation.', 'briqpay-for-woocommerce'));
            $order->save();
            Logger::log('Order upgraded to pending: ' . $order->get_id());

            /**
             * Action after payment is verified and order upgraded.
             *
             * @param WC_Order $order   The WooCommerce order.
             * @param array    $session Briqpay session from API.
             */
            do_action('briqpay_payment_complete', $order, $session);
        }

        // Clear session data after successful placement to ensure second purchase starts fresh
        Session_Manager::clear_session_id();
        if (null !== WC()->session) {
            WC()->session->set('order_awaiting_payment', null);
            WC()->session->set('briqpay_customer_type', null);
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * Clear customer session data after purchase
     * 
     * Restricted strictly to guest B2B session flags to prevent erasing
     * registered B2C customer profiles.
     */
    public function clear_customer_data_after_purchase($order_id)
    {
        if (!$order_id) {
            return;
        }

        // Prevent re-running cleanup on thank-you page revisit
        $cleanup_key = 'briqpay_cleanup_done_' . $order_id;
        if (get_transient($cleanup_key)) {
            return;
        }
        set_transient($cleanup_key, 1, HOUR_IN_SECONDS);

        $order = wc_get_order($order_id);
        if (!$order || 'briqpay' !== $order->get_payment_method()) {
            return;
        }

        Logger::log('clear_customer_data_after_purchase triggered for order: ' . $order_id);

        // Session-only B2B cleanup
        if (null !== WC() && null !== WC()->session) {
            WC()->session->set('briqpay_b2b_active', false);
            WC()->session->set('briqpay_customer_type', null);
            WC()->session->set('briqpay_prev_b2b_active', null);
        }
        setcookie('briqpay_b2b_active', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);

        // Only clear stored customer address if guest user to avoid corrupting registered user details
        if (get_current_user_id() === 0 && null !== WC() && null !== WC()->customer) {
            Logger::log('Clearing guest customer address fields on thank-you page.');
            WC()->customer->set_billing_first_name('');
            WC()->customer->set_billing_last_name('');
            WC()->customer->set_billing_company('');
            WC()->customer->set_billing_address_1('');
            WC()->customer->set_billing_address_2('');
            WC()->customer->set_billing_city('');
            WC()->customer->set_billing_postcode('');
            WC()->customer->set_billing_country('');
            WC()->customer->set_billing_state('');
            WC()->customer->set_billing_email('');
            WC()->customer->set_billing_phone('');

            WC()->customer->set_shipping_first_name('');
            WC()->customer->set_shipping_last_name('');
            WC()->customer->set_shipping_company('');
            WC()->customer->set_shipping_address_1('');
            WC()->customer->set_shipping_address_2('');
            WC()->customer->set_shipping_city('');
            WC()->customer->set_shipping_postcode('');
            WC()->customer->set_shipping_country('');
            WC()->customer->set_shipping_state('');
            WC()->customer->save();
        }

        if (null !== WC() && null !== WC()->session) {
            WC()->session->save_data();
        }
    }

    /**
     * Get Order by Session ID
     */
    private function get_order_by_session_id($session_id)
    {
        $order_ids = wc_get_orders(array(
            'meta_key' => '_briqpay_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'limit' => 1,
            'return' => 'ids',
        ));
        return !empty($order_ids) ? wc_get_order(reset($order_ids)) : null;
    }

    /**
     * AJAX: Get Session
     */
    public function ajax_get_session()
    {
        check_ajax_referer('briqpay_nonce', 'nonce');
        Logger::log('ajax_get_session() triggered.');

        // Capture total BEFORE processing to detect changes
        $total_before = WC()->cart->get_total('edit');

        // Handle Blocks Data
        if (isset($_POST['blocks_data'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must be decoded first; sanitized recursively below via sanitize_recursive().
            $blocks_data = json_decode(wp_unslash($_POST['blocks_data']), true);
            if (is_array($blocks_data)) {
                $blocks_data = $this->sanitize_recursive($blocks_data);
                Logger::log('Updating WC Customer from blocks data.');
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
                    Logger::log('Setting chosen shipping methods from BLOCKS, count: ' . count($chosen_methods));
                    WC()->session->set('chosen_shipping_methods', $chosen_methods);
                }

                // Capture WooCommerce's native Order Attribution data (see
                // capture_order_attribution_from_blocks() for why Blocks needs
                // its own path - it never reaches the classic checkout form).
                if (isset($blocks_data['order_attribution']) && is_array($blocks_data['order_attribution'])) {
                    $this->capture_order_attribution_from_blocks($blocks_data['order_attribution']);
                }
            }
        }

        // Update WC Customer data from posted form data if available (Classic Checkout)
        if (isset($_POST['checkout_data'])) {
            $checkout_data = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- data is sanitized per-field below
            parse_str(wp_unslash($_POST['checkout_data']), $checkout_data);

            if (is_array($checkout_data)) {
                // Recursively sanitize checkout_data
                $checkout_data = $this->sanitize_recursive($checkout_data);
                Logger::log('Updating WC Customer from sanitized checkout data.');

                // WooCommerce's own Order Attribution feature stamps hidden
                // wc_order_attribution_* inputs into the checkout form and reads
                // them back in WC_Checkout::process_checkout() - which our decision
                // flow never calls. Since this full form serialization is the only
                // place those fields ever reach us, capture them here (they don't
                // change meaningfully between requests) and apply them to the order
                // once it actually exists, in apply_order_attribution_to_order().
                $this->capture_order_attribution($checkout_data);
            } else {
                Logger::log('checkout_data found but is empty.');
            }
        } else {
            Logger::log('checkout_data is COMPLETELY MISSING from POST.');
        }

        // Record terms & conditions acceptance from the actual checkout form submitted
        // here, so validate_data_integrity() can check it later. ajax_make_decision()
        // (which calls validate_data_integrity()) never resubmits the checkout form -
        // it only ever posts a sessionId - so this is the only request where the
        // real 'terms' checkbox state is available.
        if (wc_terms_and_conditions_checkbox_enabled() && isset($checkout_data) && !empty($checkout_data)) {
            // WooCommerce always renders a 'terms-field' marker alongside the checkbox
            // when the terms notice is shown; 'terms' itself is only present when checked.
            $terms_shown = isset($checkout_data['terms-field']) || isset($checkout_data['terms']);
            $terms_accepted = !$terms_shown || !empty($checkout_data['terms']);
            if (null !== WC()->session) {
                WC()->session->set('briqpay_terms_accepted', $terms_accepted);
            }
            Logger::log('Terms acceptance recorded: ' . ($terms_accepted ? 'yes' : 'no'));
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
                    Logger::log('Setting chosen shipping methods, count: ' . count($methods));
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

        $shipping_country = WC()->customer->get_shipping_country();
        $shipping_postcode = WC()->customer->get_shipping_postcode();
        $shipping_city = WC()->customer->get_shipping_city();
        $chosen_shipping_methods = null !== WC()->session ? WC()->session->get('chosen_shipping_methods') : null;
        $cart_hash = WC()->cart->get_cart_hash();

        $address_data = array(
            $shipping_country,
            $shipping_postcode,
            $shipping_city,
            $chosen_shipping_methods,
            $cart_hash
        );
        $new_hash = md5(wp_json_encode($address_data));
        $stored_hash = null !== WC()->session ? WC()->session->get('briqpay_address_hash') : null;

        if ($stored_hash !== $new_hash) {
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
            if (null !== WC()->session) {
                WC()->session->set('briqpay_address_hash', $new_hash);
            }
            Logger::log('Recalculating shipping and totals because address or cart changed.');
        } else {
            Logger::log('Skipping shipping and totals recalculation (address and cart unchanged).');
        }

        $total_after = WC()->cart->get_total('edit');
        Logger::log(sprintf('Total Check: Before=%s, After=%s', $total_before, $total_after));

        if ($total_before !== $total_after) {
            // Allow session to be patched (suspend/resume) even when totals change.
            // Session_Manager::get_or_create_session() handles updates via PATCH.
            Logger::log('Total changed, session will be patched (not regenerated).');
        }

        Logger::log('Recalculated WC Total: ' . $total_after . ' (Shipping: ' . WC()->cart->get_shipping_total() . ')');

        // Detect if the frontend signals that company name is required
        // (standard WooCommerce checkout with "business" option enabled).
        // Nonce was already verified at the top of this method via check_ajax_referer().
        $company_required = false;
        if (isset($blocks_data) && !empty($blocks_data['company_required'])) {
            $company_required = true;
        } elseif (isset($checkout_data) && !empty($checkout_data['briqpay_company_required'])) {
            $company_required = true;
        }
        if (null !== WC()->session) {
            WC()->session->set('briqpay_company_required', $company_required);
        }

        // Auto-detect B2B context from the shortcode's hidden field.
        // The [briqpay_b2b_checkout] shortcode renders <input name="briqpay_b2b" value="1">
        // which checkout.js serializes into checkout_data. If present, establish B2B session
        // flags so the session manager creates a 'business' session with the correct modules.
        // Nonce was already verified at the top of this method via check_ajax_referer().
        if (isset($checkout_data) && !empty($checkout_data['briqpay_b2b'])) {
            if (null !== WC()->session && !WC()->session->get('briqpay_b2b_active')) {
                Logger::log('B2B auto-detect: briqpay_b2b=1 found in checkout_data. Setting B2B session flags.');
                WC()->session->set('briqpay_b2b_active', true);
                WC()->session->set('chosen_payment_method', 'briqpay');
                WC()->session->set('briqpay_customer_type', 'business');
            }
        }

        $session_manager = new Session_Manager();
        $session = $session_manager->get_or_create_session();

        if (is_wp_error($session)) {
            Logger::log('AJAX Error: ' . $session->get_error_message());
            wp_send_json_error(array('message' => 'An error occurred while creating the session.'));
        }

        Logger::log('AJAX Success.');
        WC()->session->save_data();
        wp_send_json_success($session);
    }

    /**
     * AJAX: Make Decision
     */
    public function ajax_make_decision()
    {
        check_ajax_referer('briqpay_nonce', 'nonce');

        $session_id = isset($_POST['sessionId']) ? sanitize_key(wp_unslash($_POST['sessionId'])) : '';
        if (!$session_id) {
            wp_send_json_error(array('message' => 'Missing session ID'));
        }

        $stored_session_id = Session_Manager::get_session_id();
        if (!$stored_session_id || $stored_session_id !== $session_id) {
            Logger::log('Security warning: ajax_make_decision session ID mismatch. Provided: ' . $session_id . ', Stored: ' . ($stored_session_id ?: 'none'));
            wp_send_json_error(array('message' => 'Unauthorized session ID'));
        }

        $lock_key = 'briqpay_decision_lock_' . $session_id;
        if (get_transient($lock_key)) {
            Logger::log('Decision already in progress for session: ' . $session_id);
            wp_send_json_error(array('message' => 'Decision in progress'));
        }
        set_transient($lock_key, 1, 30);

        Logger::log('ajax_make_decision() triggered for session: ' . $session_id);

        // 1. Get session from Briqpay
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            delete_transient($lock_key);
            Logger::log('Error retrieving session: ' . $session->get_error_message());
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

        // Sync company name to WC customer (B2B: used for thank-you page and order details).
        $company_name = $session['data']['company']['name'] ?? '';
        if ($company_name) {
            WC()->customer->set_billing_company(sanitize_text_field($company_name));
            WC()->customer->set_shipping_company(sanitize_text_field($company_name));
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

        // Robust re-calculation: Force shipping before totals to ensure sync.
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        // 2. Create order at decision point
        try {
            $order = $this->create_order_at_decision($session);
            Logger::log('Order created at decision: ' . $order->get_id());

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

            // Set company name on order (B2B: visible in order details and thank-you page).
            $company_name = $session['data']['company']['name'] ?? '';
            if ($company_name) {
                $order->set_billing_company(sanitize_text_field($company_name));
                $order->set_shipping_company(sanitize_text_field($company_name));
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

            $this->apply_order_attribution_to_order($order);

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

            // Optimization: If there is an amount or cart-contents mismatch, try ONE
            // synchronous sync to Briqpay to reconcile race conditions (e.g. shipping
            // fee just added, or a session PATCH that failed earlier) before giving up.
            $has_amount_mismatch = false;
            foreach ($validation['errors'] as $err) {
                if (strpos($err, 'Amount mismatch') !== false || strpos($err, 'Cart contents mismatch') !== false) {
                    $has_amount_mismatch = true;
                    break;
                }
            }

            if ($has_amount_mismatch) {
                Logger::log(sprintf('Amount mismatch detected for session %s. Attempting emergency synchronization...', $session_id));
                $session_manager = new Session_Manager();
                $session = $session_manager->update_session($session_id);

                if (!is_wp_error($session)) {
                    // Extract amounts for logging to verify the PATCH actually updated Briqpay
                    $synced_bp_amount = $session['data']['order']['amountIncVat'] ?? 0;
                    $current_wc_amount = $this->get_cart_total_inc_vat();
                    Logger::log(sprintf('Emergency sync complete. New BP: %s, Current WC: %s. Re-validating...', $synced_bp_amount, $current_wc_amount));

                    $validation = $this->validate_data_integrity($session);
                } else {
                    Logger::log('Emergency sync failed: ' . $session->get_error_message());
                }
            }

            $initial_decision = 'allow';

            if (!$validation['valid']) {
                Logger::log('Validation failed: ' . implode(', ', $validation['errors']));
                $initial_decision = array(
                    'decision' => 'reject',
                    'rejectionType' => 'notify_user',
                    'softErrors' => array()
                );

                $has_user_error = false;
                $whitelist = array(
                    __('Please select a shipping method.', 'briqpay-for-woocommerce'),
                    __('No shipping methods are available for your address.', 'briqpay-for-woocommerce'),
                    __('Please fill in your email.', 'briqpay-for-woocommerce'),
                    __('You must accept our Terms & Conditions to complete your purchase.', 'briqpay-for-woocommerce')
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
                Logger::log('Decision overridden to REJECT by filter for session: ' . $session_id);
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

            delete_transient('briqpay_decision_lock_' . $session_id);

            if (is_wp_error($decision_result)) {
                Logger::log('Decision API error: ' . $decision_result->get_error_message());
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

            Logger::log('Decision processed for session: ' . $session_id);

            // Return success - order will be upgraded to pending on return
            wp_send_json_success(array(
                'message' => 'Decision processed',
                'session_id' => $session_id,
                'order_id' => $order->get_id(),
                'redirect_url' => add_query_arg('briqpay_return', '1', $this->get_current_url())
            ));
        } catch (\Exception $e) {
            delete_transient('briqpay_decision_lock_' . $session_id);
            Logger::log('Error creating order: ' . $e->getMessage());
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
            Logger::log('Found existing order for session: ' . $existing_order->get_id());
            return $existing_order;
        }

        // 2. Check if WC has an order awaiting payment in session
        $order_id = WC()->session->get('order_awaiting_payment');
        if (!$order_id) {
            $order_id = WC()->session->get('store_api_draft_order', 0); // Blocks specific
        }

        $needs_items = false; // Track whether we need to add cart items

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->has_status(array('pending', 'on-hold', 'checkout-draft', 'draft', 'auto-draft'))) {
                // Verify ownership: customer ID must match
                $current_user_id = (int) get_current_user_id();
                $order_customer_id = (int) $order->get_customer_id();

                if ($current_user_id !== $order_customer_id) {
                    Logger::log('Security: Guest or customer mismatch for session-stored draft order. Resetting order.');
                    $order = null;
                } else {
                    Logger::log('Reusing existing WC order from session: ' . $order_id . ' (Status: ' . $order->get_status() . ')');
                    $order->update_meta_data('_briqpay_session_id', $session_id);

                    // Check if the order items match current cart items
                    $existing_items = $order->get_items();
                    if (!empty($existing_items)) {
                        $cart_contents = (null !== WC() && null !== WC()->cart) ? WC()->cart->get_cart() : array();
                        $items_match = (count($existing_items) === count($cart_contents));

                        if ($items_match) {
                            foreach ($existing_items as $order_item) {
                                $found = false;
                                foreach ($cart_contents as $cart_item) {
                                    $p = $cart_item['data'];
                                    $p_id = $p->get_type() === 'variation' ? $p->get_parent_id() : $p->get_id();
                                    $v_id = $p->get_type() === 'variation' ? $p->get_id() : 0;
                                    if ((int) $order_item->get_product_id() === (int) $p_id && (int) $order_item->get_variation_id() === (int) $v_id && (int) $order_item->get_quantity() === (int) $cart_item['quantity']) {
                                        $found = true;
                                        break;
                                    }
                                }
                                if (!$found) {
                                    $items_match = false;
                                    break;
                                }
                            }
                        }

                        if ($items_match) {
                            Logger::log('Order items match cart exactly. Keeping existing items.');
                            $needs_items = false;
                        } else {
                            Logger::log('Order items do not match cart. Rebuilding items.');
                            $order->remove_order_items();
                            $needs_items = true;
                        }
                    } else {
                        Logger::log('Order has no items. Will add from cart.');
                        $needs_items = true;
                    }

                    $order->save();
                }
            } else {
                $order = null;
            }
        }

        // 2b. Fallback: Search for any draft order with same customer/session if not in WC session
        if (!$order) {
            Logger::log('No order in WC session, searching database for recent drafts.');
            $current_user_id = get_current_user_id();
            $recent_orders = wc_get_orders(array(
                'customer' => $current_user_id ?: 0,
                'status' => array('checkout-draft', 'draft', 'auto-draft'),
                'limit' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
            ));

            foreach ($recent_orders as $ro) {
                // If it's very recent (e.g. 10 mins) and has same session (or no session, but only for registered users)
                if (time() - $ro->get_date_created()->getTimestamp() < 600) {
                    $ro_session = $ro->get_meta('_briqpay_session_id');
                    $is_allowed = false;
                    if ($current_user_id > 0) {
                        // Registered user can reuse their own recent drafts
                        if (!$ro_session || $ro_session === $session_id) {
                            $is_allowed = true;
                        }
                    } else {
                        // Guests can ONLY reuse a draft if it is already bound to this specific session ID.
                        // They cannot reuse unbound guest drafts.
                        if ($ro_session && $ro_session === $session_id) {
                            $is_allowed = true;
                        }
                    }

                    if ($is_allowed) {
                        Logger::log('Found recent draft in DB to reuse: ' . $ro->get_id());
                        $order = $ro;
                        $order->update_meta_data('_briqpay_session_id', $session_id);

                        $existing_items = $order->get_items();
                        $needs_items = empty($existing_items);
                        break;
                    }
                }
            }
        }

        // 2c. If reusing a draft that is NOT a trusted current-session draft, rebuild items from current cart
        $is_trusted_draft = false;
        if ($order) {
            $session_order_id = WC()->session->get('order_awaiting_payment');
            if (!$session_order_id) {
                $session_order_id = WC()->session->get('store_api_draft_order', 0);
            }
            if ($session_order_id && (int) $order->get_id() === (int) $session_order_id) {
                $is_trusted_draft = true;
            }
        }

        if ($order && !$is_trusted_draft) {
            Logger::log('Reused draft is not a trusted current-session draft. Rebuilding items from current cart.');
            $order->remove_order_items();
            $needs_items = true;
        }

        // 3. Create new order if none found
        if (!$order) {
            Logger::log('Creating new manual order at decision point.');
            $order = wc_create_order(array(
                'customer_id' => get_current_user_id() ?: 0,
                'status' => 'pending',
                'created_via' => 'Briqpay',
            ));

            if (is_wp_error($order)) {
                throw new \Exception('Could not create order');
            }

            $needs_items = true; // New order always needs items
        }


        $order->save();

        // Set this order as the one awaiting payment in session to prevent WC from creating another
        WC()->session->set('order_awaiting_payment', $order->get_id());

        // Copy cart items with full metadata support (only if the order doesn't already have them)
        if ($needs_items) {
            Logger::log('Adding cart items to order.');
            foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
                /** @var \WC_Product $product */
                $product = $values['data'];

                $item = new \WC_Order_Item_Product();
                $item->set_name($product->get_name());

                // Correctly set parent product ID and variation ID
                if ($product->get_type() === 'variation') {
                    $item->set_product_id($product->get_parent_id());
                    $item->set_variation_id($product->get_id());
                } else {
                    $item->set_product_id($product->get_id());
                    $item->set_variation_id(0);
                }

                $item->set_quantity($values['quantity']);
                $item->set_subtotal($values['line_subtotal']);
                $item->set_total($values['line_total']);
                $item->set_subtotal_tax($values['line_subtotal_tax']);
                $item->set_total_tax($values['line_tax']);
                $item->set_taxes($values['line_tax_data']);
                $item->set_backorder_meta();

                $sku = $product->get_sku();
                $id = $product->get_id();
                $base_ref = !empty($sku) ? $sku : (string) $id;

                // Mirror Session_Manager::get_cart_items()'s reference format so
                // capture/refund lookups (which match session cart items to order
                // items by reference) keep working when the same SKU appears at
                // different prices in one cart (add-ons, bundles, personalization,
                // role pricing). Store it so it survives even if the product is
                // later deleted or its price changes.
                $unit_price_minor_units = $values['quantity'] > 0
                    ? (int) round(($values['line_subtotal'] / $values['quantity']) * 100)
                    : 0;
                $ref = $base_ref . '-' . $unit_price_minor_units;
                $item->add_meta_data('_briqpay_item_reference', $ref);

                // Add variation attributes as item meta (e.g. "Color: Blue")
                if (!empty($values['variation'])) {
                    foreach ($values['variation'] as $attr_key => $attr_value) {
                        $item->add_meta_data($attr_key, $attr_value);
                    }
                }

                // Fire the standard hook that plugins like "Extra Product Options" use
                // to attach their custom metadata to the order line item.
                do_action('woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $values, $order);

                $order->add_item($item);
            }

            // Set shipping, fees, and coupons from the current cart
            $this->add_shipping_items_from_cart($order);
            $this->add_fee_items_from_cart($order);
            $this->add_coupon_items_from_cart($order);

            // Persist items now; tax-aware totals are calculated below after addresses are set
            $order->save();
        } else {
            // Product items already match the cart and were kept as-is (e.g. a
            // trusted Store API draft), but shipping method, fees, and coupons can
            // still have drifted after this draft was first created - a shipping
            // method or coupon can change without the product line items themselves
            // changing. Reconcile those three categories independently so the order
            // never authorizes/charges a stale shipping method, fee, or coupon.
            // Product line items are intentionally left untouched here since we
            // already verified above that they match the cart.
            Logger::log('Order items match cart. Reconciling shipping/fees/coupons in case those changed since the draft was created.');

            // Shipping is reconciled conservatively. add_shipping_items_from_cart()
            // can legitimately resolve nothing (e.g. a Blocks draft where the Store
            // API owns the shipping selection and chosen_shipping_methods was never
            // mirrored into the WC session), so removing first and adding second
            // could strip the draft's shipping line and undercharge the customer.
            // Only swap it out when the cart actually yields replacement rates, and
            // only clear it outright when the cart genuinely no longer needs shipping.
            if (!WC()->cart->needs_shipping()) {
                $order->remove_order_items('shipping');
            } elseif ($this->cart_has_resolvable_shipping()) {
                $order->remove_order_items('shipping');
                $this->add_shipping_items_from_cart($order);
            } else {
                Logger::log('Keeping existing shipping item: no chosen shipping rate could be resolved from the current cart.');
            }

            // Fees and coupons come straight from the cart, which calculate_totals()
            // has already refreshed, so an empty result here genuinely means "none".
            $order->remove_order_items('fee');
            $this->add_fee_items_from_cart($order);

            $order->remove_order_items('coupon');
            $this->add_coupon_items_from_cart($order);

            $order->save();
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

        Logger::log('Initial PSP Name at decision: ' . $psp_name);

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

        $order->update_meta_data(
            '_briqpay_auto_capture_enabled',
            Order_Management::session_has_auto_capture_enabled($session) ? 'yes' : 'no'
        );

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

        // Set company name on order (B2B: visible in order details and thank-you page).
        $company_name = $session['data']['company']['name'] ?? '';
        if ($company_name) {
            $order->set_billing_company(sanitize_text_field($company_name));
            $order->set_shipping_company(sanitize_text_field($company_name));
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

        // Calculate totals with tax AFTER addresses are set so tax rules resolve correctly
        $order->calculate_totals(true);

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

        Legacy_B2b_Meta::apply($order, $session);

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
     * Add shipping item(s) to the order from the current cart's chosen shipping methods.
     */
    private function add_shipping_items_from_cart($order)
    {
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        if (empty($chosen_methods)) {
            return;
        }

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

                // Allow plugins to modify shipping item
                do_action('woocommerce_checkout_create_order_shipping_item', $item, $i, $package, $order);

                $order->add_item($item);
            }
        }
    }

    /**
     * Whether the current cart yields at least one resolvable chosen shipping rate.
     * Used to avoid removing an existing shipping line we cannot replace.
     */
    private function cart_has_resolvable_shipping()
    {
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        if (empty($chosen_methods)) {
            return false;
        }

        foreach (WC()->shipping()->get_packages() as $i => $package) {
            if (isset($chosen_methods[$i], $package['rates'][$chosen_methods[$i]])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add fee item(s) to the order from the current cart's fees.
     */
    private function add_fee_items_from_cart($order)
    {
        foreach (WC()->cart->get_fees() as $fee) {
            $item = new \WC_Order_Item_Fee();
            $item->set_name($fee->name);
            $item->set_tax_class($fee->tax_class);
            $item->set_tax_status($fee->taxable ? 'taxable' : 'none');
            $item->set_total($fee->total);
            $item->set_total_tax($fee->tax);
            $item->set_taxes(array('total' => $fee->tax_data));
            $item->add_meta_data('_briqpay_fee_reference', $fee->id);

            do_action('woocommerce_checkout_create_order_fee_item', $item, $fee->id, $fee, $order);

            $order->add_item($item);
        }
    }

    /**
     * Add coupon item(s) to the order from the current cart's applied coupons.
     */
    private function add_coupon_items_from_cart($order)
    {
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $item = new \WC_Order_Item_Coupon();
            $item->set_code($code);
            $item->set_discount(WC()->cart->get_coupon_discount_amount($code));
            $item->set_discount_tax(WC()->cart->get_coupon_discount_tax_amount($code));

            do_action('woocommerce_checkout_create_order_coupon_item', $item, $code, $coupon, $order);

            $order->add_item($item);
        }
    }

    /**
     * Is the WooCommerce Terms & Conditions validation enabled?
     *
     * Merchants who collect consent through Briqpay's own terms module or a
     * third-party consent plugin can switch this off so the customer is not
     * asked to accept twice.
     *
     * Defaults to enabled: the setting is absent on installs that upgraded from
     * a version predating it, and silently dropping a purchase guard on upgrade
     * would be the wrong default.
     *
     * @return bool
     */
    public static function terms_validation_enabled()
    {
        $settings = get_option('woocommerce_briqpay_settings', array());
        if (!is_array($settings) || !isset($settings['terms_validation_enabled'])) {
            return true;
        }

        return 'yes' === $settings['terms_validation_enabled'];
    }

    /**
     * Validate Data Integrity
     */
    private function validate_data_integrity($session)
    {
        $errors = array();

        // Email Validation (Non-B2B only)
        // Ensure email is present in B2C flow since some blocks/external checkouts might bypass frontend checks.
        $is_b2b = (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_b2b_active')) || (isset($_COOKIE['briqpay_b2b_active']) && $_COOKIE['briqpay_b2b_active'] === '1');
        if (!$is_b2b) {
            $email = $session['data']['billing']['email'] ?? '';
            if (empty($email)) {
                $errors[] = __('Please fill in your email.', 'briqpay-for-woocommerce');
            }
        }

        // Use Session_Manager to calculate expected payload
        $session_manager = new Session_Manager();
        $wc_data = $session_manager->get_session_data(true); // Get update payload

        Logger::log('Validating Session Integrity...');
        Logger::log('BP Data: ' . wp_json_encode($session['data']['order'] ?? array()));
        Logger::log('WC Data: ' . wp_json_encode($wc_data['data']['order'] ?? array()));

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

        // Validate cart CONTENTS, not just the aggregate total. The aggregate check
        // above can coincidentally pass even when a failed/raced session sync left
        // Briqpay authorizing a same-value but different set of products/quantities
        // than what WooCommerce is about to charge (e.g. two same-priced products
        // swapped during a mid-checkout API failure). Compare per-reference
        // quantities on both sides to catch that.
        // Only compare when BOTH sides actually returned a cart. A missing/empty cart
        // on either side means we have nothing meaningful to compare - treating that
        // as a mismatch would reject every checkout, which is far worse than the race
        // this guards against.
        $bp_cart = $session['data']['order']['cart'] ?? array();
        $wc_cart = $wc_data['data']['order']['cart'] ?? array();
        if (!empty($bp_cart) && !empty($wc_cart) && !$this->cart_quantities_match($bp_cart, $wc_cart)) {
            $errors[] = 'Cart contents mismatch: WC and BP carts contain different products or quantities.';
        }

        // Check if there was a previous session update sync failure
        if (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_sync_failed')) {
            $errors[] = __('We were unable to synchronize your cart with the payment provider. Please reload the page and try again.', 'briqpay-for-woocommerce');
        }

        // Validate stock levels for all items in the cart
        if (null !== WC() && null !== WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$product || !$product->is_purchasable()) {
                    $errors[] = sprintf(__('"%s" is no longer available for purchase.', 'briqpay-for-woocommerce'), $product ? $product->get_name() : '');
                } elseif (!$product->is_in_stock()) {
                    $errors[] = sprintf(__('"%s" is out of stock.', 'briqpay-for-woocommerce'), $product->get_name());
                } elseif ($product->managing_stock() && !$product->has_enough_stock($cart_item['quantity'])) {
                    $errors[] = sprintf(__('We do not have enough stock of "%s" to fulfill your order.', 'briqpay-for-woocommerce'), $product->get_name());
                }
            }
        }

        // Validate coupons
        if (null !== WC()->cart) {
            foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
                $coupon = new \WC_Coupon($coupon_code);
                if (!$coupon->is_valid()) {
                    $errors[] = sprintf(__('Coupon "%s" is not valid.', 'briqpay-for-woocommerce'), $coupon_code);
                }
            }
        }

        // Validate terms acceptance
        //
        // This method runs from ajax_make_decision(), which only ever posts a
        // sessionId - the checkout form is never resubmitted here, so $_POST can't
        // tell us whether terms were accepted. Instead we read the flag
        // ajax_get_session() stored the last time the actual checkout form (with its
        // terms checkbox) was submitted. If that flag was never set (e.g. Blocks
        // checkout, which doesn't submit a classic serialized form), we don't block -
        // there is no reliable signal to check here, and rejecting unconditionally is
        // exactly the bug this replaces.
        //
        // Merchants who collect consent elsewhere (Briqpay's own terms module, a
        // third-party consent plugin) can opt out of this check entirely via
        // WooCommerce > Settings > Payments > Briqpay > "Validate Terms &
        // Conditions".
        if (self::terms_validation_enabled() && wc_terms_and_conditions_checkbox_enabled()) {
            $terms_accepted = (null !== WC() && null !== WC()->session) ? WC()->session->get('briqpay_terms_accepted') : null;
            if (false === $terms_accepted) {
                $errors[] = __('You must accept our Terms & Conditions to complete your purchase.', 'briqpay-for-woocommerce');
            }
        } elseif (!self::terms_validation_enabled()) {
            Logger::log('Terms & Conditions validation skipped: disabled in gateway settings.');
        }

        // Validate Address Fields
        if (isset($session['data']['billing']) && isset($wc_data['data']['billing'])) {
            $this->validate_address_fields($wc_data['data']['billing'], $session['data']['billing'], 'Billing', $errors);
        }

        if (isset($session['data']['shipping']) && isset($wc_data['data']['shipping'])) {
            $this->validate_address_fields($wc_data['data']['shipping'], $session['data']['shipping'], 'Shipping', $errors);
        }

        // Validate Shipping Selection (multi-package aware)
        if (WC()->cart->needs_shipping()) {
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $chosen_methods = is_array($chosen_methods) ? $chosen_methods : array();
            $packages = WC()->shipping()->get_packages();

            foreach ($packages as $pkg_index => $package) {
                if (empty($package['rates'])) {
                    $errors[] = __('No shipping methods are available for your address.', 'briqpay-for-woocommerce');
                    break;
                }
                $chosen = $chosen_methods[$pkg_index] ?? '';
                if (empty($chosen) || !isset($package['rates'][$chosen])) {
                    // Check fallback for external integrations
                    if ((float) WC()->cart->get_shipping_total() <= 0) {
                        $errors[] = __('Please select a shipping method.', 'briqpay-for-woocommerce');
                        break;
                    }
                }
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * Compare item references+quantities between two Briqpay-format cart arrays.
     * Only quantities are compared (not amounts) to avoid false positives from
     * rounding noise - the aggregate amount check elsewhere already covers totals.
     */
    private function cart_quantities_match($bp_cart, $wc_cart)
    {
        return $this->cart_quantity_signature($bp_cart) === $this->cart_quantity_signature($wc_cart);
    }

    /**
     * Build a reference => total quantity map for a Briqpay-format cart array.
     *
     * sales_tax lines are excluded: they are built without a 'quantity' key at all
     * (see Session_Manager::get_cart_items()), so any quantity we inferred for them
     * would be invented on our side and could disagree with whatever Briqpay echoes
     * back - producing a false mismatch that would block every US checkout. Tax
     * totals are already covered by the aggregate amount check.
     */
    private function cart_quantity_signature($cart)
    {
        $signature = array();
        foreach ((array) $cart as $line) {
            if ('sales_tax' === ($line['productType'] ?? '')) {
                continue;
            }
            $ref = (string) ($line['reference'] ?? '');
            $qty = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            $signature[$ref] = ($signature[$ref] ?? 0) + $qty;
        }
        ksort($signature);
        return $signature;
    }

    /**
     * Get current cart total inc VAT in integer cents (or equivalent)
     */
    private function get_cart_total_inc_vat()
    {
        $session_manager = new Session_Manager();
        $data = $session_manager->get_session_data();
        return $data['data']['order']['amountIncVat'] ?? 0;
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
     * Recursive Sanitization for Arrays
     */
    private function sanitize_recursive($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_recursive($value);
            }
        } else {
            $data = sanitize_text_field($data);
        }
        return $data;
    }

    /**
     * WooCommerce's own Order Attribution field names, without the
     * wc_order_attribution_ POST-field prefix or the leading underscore its
     * order meta keys use (e.g. 'source_type' -> POST field
     * 'wc_order_attribution_source_type' -> order meta
     * '_wc_order_attribution_source_type').
     *
     * @var string[]
     */
    private static $order_attribution_fields = array(
        'source_type',
        'referrer',
        'utm_campaign',
        'utm_source',
        'utm_medium',
        'utm_content',
        'utm_id',
        'utm_term',
        'utm_source_platform',
        'utm_creative_format',
        'utm_marketing_tactic',
        'session_entry',
        'session_start_time',
        'session_pages',
        'session_count',
        'user_agent',
    );

    /**
     * Capture WooCommerce's native Order Attribution fields from parsed
     * classic-checkout form data (prefixed wc_order_attribution_* keys, as
     * they appear in the serialized 'checkout_data') and stash them in the
     * WC session, to be applied to the order once it actually exists
     * (create_order_at_decision() runs on a separate AJAX request that
     * never resends the checkout form).
     *
     * Without this, orders created through this plugin's decision flow never
     * go through WC_Checkout::process_checkout() - the only place WooCommerce
     * itself captures this data - and show as "Unknown" in the admin Origin
     * column instead of Organic/Direct/Referral/UTM/etc.
     *
     * @param array $source Parsed, sanitized checkout form data (e.g. from
     *                       parse_str() on the serialized 'checkout_data').
     */
    private function capture_order_attribution(array $source)
    {
        $fields = array();

        foreach (self::$order_attribution_fields as $field) {
            $key = 'wc_order_attribution_' . $field;
            if (isset($source[$key])) {
                $fields[$field] = $source[$key];
            }
        }

        $this->store_order_attribution($fields);
    }

    /**
     * Same as capture_order_attribution(), for Blocks checkout. WooCommerce
     * Blocks stores this data in the Checkout block's own extension data
     * rather than hidden form fields, so our own JS (readOrderAttribution()
     * in blocks-checkout.js) reads it client-side - via WC's own
     * wc_order_attribution.getAttributionData() helper, or by reading what
     * order-attribution.js already pushed into the 'wc/store/checkout' data
     * store - and sends it here already unprefixed (e.g. 'source_type', not
     * 'wc_order_attribution_source_type').
     *
     * @param array $fields Unprefixed field => value pairs.
     */
    private function capture_order_attribution_from_blocks(array $fields)
    {
        $this->store_order_attribution($fields);
    }

    /**
     * Shared by capture_order_attribution() and
     * capture_order_attribution_from_blocks(): drop empty/placeholder values
     * and stash whatever's left in the WC session.
     *
     * @param array $fields Unprefixed field => value pairs.
     */
    private function store_order_attribution(array $fields)
    {
        $attribution = array();

        foreach (self::$order_attribution_fields as $field) {
            // WooCommerce's own hidden inputs (and the Blocks helper) use the
            // literal string '(none)' as a placeholder for "not detected" -
            // treat it the same as absent.
            if (isset($fields[$field]) && '' !== $fields[$field] && '(none)' !== $fields[$field]) {
                $attribution[$field] = $fields[$field];
            }
        }

        if (empty($attribution)) {
            return;
        }

        if (null !== WC() && null !== WC()->session) {
            WC()->session->set('briqpay_order_attribution', $attribution);
        }
    }

    /**
     * Apply previously-captured Order Attribution fields to a newly created
     * order, using WooCommerce's own order meta key naming
     * (_wc_order_attribution_*) so its native admin "Origin" column displays
     * correctly, exactly as if WC_Checkout::process_checkout() had captured
     * it directly.
     *
     * @param \WC_Order $order
     */
    private function apply_order_attribution_to_order($order)
    {
        if (null === WC() || null === WC()->session) {
            return;
        }

        $attribution = WC()->session->get('briqpay_order_attribution');
        if (empty($attribution) || !is_array($attribution)) {
            return;
        }

        // Don't overwrite attribution the order may already have (e.g. a
        // reused draft order that was already attributed some other way).
        if ($order->get_meta('_wc_order_attribution_source_type')) {
            return;
        }

        foreach (self::$order_attribution_fields as $field) {
            if (isset($attribution[$field])) {
                $order->update_meta_data('_wc_order_attribution_' . $field, sanitize_text_field($attribution[$field]));
            }
        }
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

    /**
     * Get Current URL helper
     */
    private function get_current_url()
    {
        if (wp_doing_ajax()) {
            return wp_get_raw_referer();
        }
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        return esc_url_raw(home_url($request_uri));
    }
}
