<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay B2B Checkout Shortcode
 */
class B2b_Checkout
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
        // 1. Establish context as early as possible (before AJAX handlers)
        add_action('init', array($this, 'harden_b2b_context'), 5);

        // 2. Auto-detect B2B shortcode on page and establish session flags
        //    (template_redirect runs after wp so is_singular()/has_shortcode() are available)
        add_action('template_redirect', array($this, 'establish_b2b_page_context'), 5);

        add_shortcode('briqpay_b2b_checkout', array($this, 'render'));
        add_filter('briqpay_session_data', array($this, 'filter_b2b_session_data'), 10, 2);

        // Priority 100 ensures we are the final decision maker for B2B session regeneration.
        // This allows us to override any "ghost" filters that might incorrectly return TRUE during AJAX.
        add_filter('briqpay_force_new_session', array($this, 'force_new_session'), 100);

        add_filter('briqpay_customer_type', array($this, 'filter_customer_type'));
        add_filter('briqpay_is_b2b_active', array($this, 'is_b2b_active_filter'));
        add_filter('woocommerce_update_order_review_fragments', array($this, 'add_b2b_fragments'));
        add_filter('woocommerce_is_checkout', array($this, 'force_is_checkout'));
        add_filter('body_class', array($this, 'add_body_class'));
    }

    /**
     * Harden B2B Context
     * Ensures session flags and cookies are set as early as possible.
     */
    public function harden_b2b_context()
    {
        // Skip for most admin pages to avoid unnecessary processing, but allow AJAX
        if (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
            return;
        }

        if ($this->is_b2b_active() && null !== WC() && null !== WC()->session) {
            // Re-establish session if lost but cookie or referer exists
            if (!WC()->session->get('briqpay_b2b_active')) {
                WC()->session->set('briqpay_b2b_active', true);
            }

            // Re-establish cookie if lost but session exists
            if (!isset($_COOKIE['briqpay_b2b_active'])) {
                setcookie('briqpay_b2b_active', '1', time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
            }
        }
    }

    /**
     * Establish B2B Page Context
     *
     * Runs on template_redirect (after WordPress knows which page is being viewed)
     * to auto-detect the [briqpay_b2b_checkout] shortcode and set session flags.
     * This eliminates the need for external code snippets or filters.
     */
    public function establish_b2b_page_context()
    {
        // Only run on front-end page views (not AJAX, not admin)
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Check if the current page contains the B2B checkout shortcode
        if (!is_singular() || !has_shortcode(get_post()->post_content, 'briqpay_b2b_checkout')) {
            return;
        }

        // At this point we KNOW we are on a B2B shortcode page.
        // Establish all session flags so downstream filters and the session manager
        // see this as a B2B context — no external snippet needed.
        if (null === WC() || null === WC()->session) {
            return;
        }

        if (!WC()->session->get('briqpay_b2b_active')) {
            $this->log('establish_b2b_page_context: Setting B2B session flags on shortcode page.');
            WC()->session->set('briqpay_b2b_active', true);
            WC()->session->set('chosen_payment_method', 'briqpay');
            WC()->session->set('briqpay_customer_type', 'business');

            // Persist immediately so AJAX calls (which are separate HTTP requests)
            // can read these flags before the page-load request finishes.
            WC()->session->save_data();
        }

        // Sync cookie for volatile/hosted sessions
        if (!isset($_COOKIE['briqpay_b2b_active'])) {
            setcookie('briqpay_b2b_active', '1', time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    /**
     * Add Body Class for B2B
     */
    public function add_body_class($classes)
    {
        if ($this->is_b2b_active()) {
            $classes[] = 'briqpay-b2b-page';
            $classes[] = 'briqpay-selected';
        }
        return $classes;
    }

    /**
     * Force is_checkout to be true on B2B page
     */
    public function force_is_checkout($is_checkout)
    {
        if (!$is_checkout && $this->is_b2b_active()) {
            return true;
        }
        return $is_checkout;
    }

    /**
     * Add B2B specific fragments for AJAX updates
     */
    public function add_b2b_fragments($fragments)
    {
        if ($this->is_b2b_active()) {
            // Explicitly sync chosen shipping methods if sent via AJAX
            if (isset($_POST['security']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['security'])), 'update-order-review') && isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
                $raw_shipping_methods = wp_unslash($_POST['shipping_method']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $shipping_methods = array_map('wc_clean', $raw_shipping_methods);
                WC()->session->set('chosen_shipping_methods', $shipping_methods);
            }

            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();

            ob_start();
            woocommerce_order_review();
            $html = ob_get_clean();

            // Standard WC selector for the whole review order
            $fragments['.woocommerce-checkout-review-order-table'] = $html;

            // Also update the wrapper if it helps wc-checkout.js find targets
            $fragments['#order_review'] = '<div id="order_review" class="woocommerce-checkout-review-order">' . $html . '</div>';
        }
        return $fragments;
    }

    /**
     * Is B2B Checkout Active?
     */
    private function is_b2b_active()
    {
        // 1. Primary: Session flag
        if (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_b2b_active')) {
            return true;
        }

        // 2. Secondary: Cookie Fallback (for hosted environments with volatile sessions)
        if (isset($_COOKIE['briqpay_b2b_active']) && $_COOKIE['briqpay_b2b_active'] === '1') {
            return true;
        }

        // 3. Tertiary: AJAX Context (Referer or POST data)
        if (wp_doing_ajax()) {
            // Check Referer
            $referer = wp_get_raw_referer();
            if ($referer && strpos($referer, 'b2b-checkout') !== false) {
                return true;
            }

            // Check checkout_data if present (serialized form)
            // Accept both 'update-order-review' nonce (wc-checkout.js) and
            // 'briqpay_nonce' (our ajax_get_session) for validation.
            if (isset($_POST['checkout_data'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $is_checkout_data_valid = false;
                if (isset($_POST['security']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['security'])), 'update-order-review')) {
                    $is_checkout_data_valid = true;
                } elseif (isset($_POST['nonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'briqpay_nonce')) {
                    $is_checkout_data_valid = true;
                }

                if ($is_checkout_data_valid) {
                    parse_str(wp_unslash($_POST['checkout_data']), $checkout_data); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    if (isset($checkout_data['briqpay_b2b'])) {
                        return true;
                    }
                }
            }

            // Direct POST flag
            // Direct POST flag
            if (isset($_POST['briqpay_b2b'])) {
                // Check if it's a legitimate checkout process POST or our own AJAX nonce
                $is_valid_nonce = false;
                if (isset($_POST['woocommerce-process-checkout-nonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['woocommerce-process-checkout-nonce'])), 'woocommerce-process_checkout')) {
                    $is_valid_nonce = true;
                } elseif (isset($_POST['security']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['security'])), 'update-order-review')) {
                    $is_valid_nonce = true;
                } elseif (isset($_POST['nonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'briqpay_nonce')) {
                    $is_valid_nonce = true;
                }

                if ($is_valid_nonce) {
                    return true;
                }
            }
        }

        // 3. Tertiary: Page content (Shortcode)
        if (is_singular() && has_shortcode(get_post()->post_content, 'briqpay_b2b_checkout')) {
            return true;
        }

        return false;
    }

    /**
     * Force new session on B2B page load
     */
    public function force_new_session($force)
    {
        $is_b2b = $this->is_b2b_active();
        if (!$is_b2b) {
            return $force;
        }

        // Robust AJAX detection for hosted environments (Read-only context check)
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        $is_ajax = wp_doing_ajax() || (defined('DOING_AJAX') && DOING_AJAX) || (isset($_GET['wc-ajax']) && !empty($_GET['wc-ajax'])) || (isset($_POST['wc-ajax']) && !empty($_POST['wc-ajax']));
        $has_action = (isset($_POST['action']) && !empty($_POST['action'])) || (isset($_GET['action']) && !empty($_GET['action']));
        // phpcs:enable

        // Log detection variables for debugging
        $this->log(sprintf(
            'B2B Force Logic [Filter Input: %s] - Is AJAX: %s, Has Action: %s, Referer: %s',
            $force ? 'TRUE' : 'FALSE',
            $is_ajax ? 'YES' : 'NO',
            $has_action ? 'YES' : 'NO',
            wp_get_raw_referer() ?: 'NONE'
        ));

        // DIAGNOSTIC: If we received TRUE but we are in AJAX, find out who sent it.
        if ($force === true && ($is_ajax || $has_action)) {
            $this->log('DEBUG: force_new_session received TRUE during AJAX. Filter must have been set by another component.');
        }

        // DEFINITIVE BYPASS: If it's AJAX or has an Action, we MUST NOT force a new session for B2B.
        // We return FALSE regardless of the inherited $force value to stabilize the session.
        if ($is_ajax || $has_action) {
            $this->log('B2B Force Session: returning FALSE (AJAX/Action detected, blocking regeneration)');
            return false;
        }

        // Only force a new session on the initial (non-AJAX) page load of the B2B checkout.
        $this->log('B2B Force Session: returning TRUE (Initial page load detected)');
        return true;
    }

    /**
     * Filter Customer Type for B2B
     */
    public function filter_customer_type($type)
    {
        if ($this->is_b2b_active()) {
            return 'business';
        }
        return $type;
    }

    /**
     * Filter for B2B Active check
     */
    public function is_b2b_active_filter($active)
    {
        return $this->is_b2b_active() ?: $active;
    }

    /**
     * Filter Session Data for B2B
     */
    public function filter_b2b_session_data($data, $update)
    {
        if (!$this->is_b2b_active()) {
            return $data;
        }

        $this->log('filter_b2b_session_data() called. Update: ' . ($update ? 'YES' : 'NO'));

        if (!$update) {
            // Only add these during initial creation (POST).
            $data['customerType'] = 'business';
            $data['modules'] = array(
                'loadModules' => array('company_lookup', 'billing', 'shipping', 'payment'),
                'config' => array(
                    'payment' => array(
                        'decision' => array(
                            'enabled' => true
                        )
                    )
                )
            );
        } else {
            // STRICK CHECK: Briqpay PATCH (update) does not support these properties and returns 400 INVALID_DATA.
            // Explicitly unset them to ensure compliance even if they leaked in from other filters.
            $restricted = array('customerType', 'modules', 'country', 'locale');
            foreach ($restricted as $key) {
                if (isset($data[$key])) {
                    $this->log('Purging restricted field from PATCH: ' . $key);
                    unset($data[$key]);
                }
            }
        }

        return $data;
    }

    /**
     * Render Shortcode
     */
    public function render()
    {
        if (null === WC() || null === WC()->cart) {
            return '';
        }

        if (WC()->cart->is_empty()) {
            return '<p>' . esc_html__('Your cart is empty.', 'briqpay-for-woocommerce') . '</p>';
        }

        // Flag is already set via wp hook and cookies for persistence robustness.
        // We ensure assets are active.

        // Enqueue B2B specific assets
        $this->enqueue_assets();

        ob_start();
        ?>
        <div class="woocommerce">
            <form id="briqpay-b2b-checkout-form" name="checkout" method="post" class="checkout woocommerce-checkout"
                action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data">

                <input type="hidden" name="briqpay_b2b" value="1" />
                <input type="hidden" name="_wp_http_referer"
                    value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''))); ?>" />

                <?php // Standard WooCommerce Nonces ?>
                <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
                <?php wp_nonce_field('woocommerce-update_order_review', 'woocommerce-update-order-review-nonce'); ?>

                <div style="display:none !important;">
                    <input type="radio" name="payment_method" id="payment_method_briqpay" value="briqpay" checked="checked" />
                </div>

                <?php // Standard WooCommerce AJAX security nonce ?>
                <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('update-order-review')); ?>" />

                <?php // Basic hidden fields to help WooCommerce calculate shipping (needs IDs for wc-checkout.js) ?>
                <input type="hidden" id="billing_country" name="billing_country"
                    value="<?php echo esc_attr(WC()->customer->get_billing_country()); ?>" />
                <input type="hidden" id="billing_state" name="billing_state"
                    value="<?php echo esc_attr(WC()->customer->get_billing_state()); ?>" />
                <input type="hidden" id="billing_postcode" name="billing_postcode"
                    value="<?php echo esc_attr(WC()->customer->get_billing_postcode()); ?>" />
                <input type="hidden" id="billing_city" name="billing_city"
                    value="<?php echo esc_attr(WC()->customer->get_billing_city()); ?>" />
                <input type="hidden" id="billing_address_1" name="billing_address_1"
                    value="<?php echo esc_attr(WC()->customer->get_billing_address_1()); ?>" />
                <input type="hidden" id="billing_email" name="billing_email"
                    value="<?php echo esc_attr(WC()->customer->get_billing_email()); ?>" />

                <input type="hidden" id="shipping_country" name="shipping_country"
                    value="<?php echo esc_attr(WC()->customer->get_shipping_country()); ?>" />
                <input type="hidden" id="shipping_state" name="shipping_state"
                    value="<?php echo esc_attr(WC()->customer->get_shipping_state()); ?>" />
                <input type="hidden" id="shipping_postcode" name="shipping_postcode"
                    value="<?php echo esc_attr(WC()->customer->get_shipping_postcode()); ?>" />
                <input type="hidden" id="shipping_city" name="shipping_city"
                    value="<?php echo esc_attr(WC()->customer->get_shipping_city()); ?>" />
                <input type="hidden" id="shipping_address_1" name="shipping_address_1"
                    value="<?php echo esc_attr(WC()->customer->get_shipping_address_1()); ?>" />

                <input type="hidden" name="ship_to_different_address" value="0" />

                <div class="briqpay-b2b-checkout-wrapper">
                    <div class="briqpay-b2b-summary-column">
                        <h3><?php esc_html_e('Order Summary', 'briqpay-for-woocommerce'); ?></h3>
                        <div id="order_review" class="woocommerce-checkout-review-order">
                            <?php woocommerce_order_review(); ?>
                        </div>
                    </div>
                    <div class="briqpay-b2b-iframe-column">
                        <div id="briqpay-iframe-container">
                            <div class="briqpay-loader" style="text-align: center; padding: 50px;">
                                <span class="spinner is-active" style="float: none;"></span>
                                <p><?php esc_html_e('Loading payment...', 'briqpay-for-woocommerce'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

        </div>
        </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the summary table content (used for initial render and AJAX fragments)
     */
    private function render_summary_table()
    {
        ?>
        <table class="shop_table woocommerce-checkout-review-order-table">
            <thead>
                <tr>
                    <th class="product-name"><?php esc_html_e('Product', 'briqpay-for-woocommerce'); ?></th>
                    <th class="product-total"><?php esc_html_e('Total', 'briqpay-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
                    if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key)) {
                        ?>
                        <tr
                            class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
                            <td class="product-name">
                                <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)) . '&nbsp;'; ?>
                                <?php echo wp_kses_post(apply_filters('woocommerce_checkout_cart_item_quantity', ' <strong class="product-quantity">' . sprintf('&times;&nbsp;%s', $cart_item['quantity']) . '</strong>', $cart_item, $cart_item_key)); ?>
                                <?php echo wp_kses_post(wc_get_formatted_cart_item_data($cart_item)); ?>
                            </td>
                            <td class="product-total">
                                <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key)); ?>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
            <tfoot>
                <tr class="cart-subtotal">
                    <th><?php esc_html_e('Subtotal', 'briqpay-for-woocommerce'); ?></th>
                    <td><?php wc_cart_totals_subtotal_html(); ?></td>
                </tr>

                <?php foreach (WC()->cart->get_coupons() as $code => $coupon): ?>
                    <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
                        <th><?php wc_cart_totals_coupon_label($coupon); ?></th>
                        <td><?php wc_cart_totals_coupon_html($coupon); ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()): ?>
                    <?php do_action('woocommerce_review_order_before_shipping'); ?>
                    <?php wc_cart_totals_shipping_html(); ?>
                    <?php do_action('woocommerce_review_order_after_shipping'); ?>
                <?php endif; ?>

                <?php foreach (WC()->cart->get_fees() as $fee): ?>
                    <tr class="fee">
                        <th><?php echo esc_html($fee->name); ?></th>
                        <td><?php wc_cart_totals_fee_html($fee); ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (wc_tax_enabled() && !WC()->cart->display_prices_including_tax()): ?>
                    <?php if ('itemized' === get_option('woocommerce_tax_total_display')): ?>
                        <?php foreach (WC()->cart->get_tax_totals() as $code => $tax): ?>
                            <tr class="tax-total tax-rate-<?php echo esc_attr(sanitize_title($code)); ?>">
                                <th><?php echo esc_html($tax->label); ?></th>
                                <td><?php echo wp_kses_post($tax->formatted_amount); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="tax-total">
                            <th><?php echo esc_html(WC()->countries->tax_or_vat()); ?></th>
                            <td><?php wc_cart_totals_taxes_total_html(); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>

                <?php do_action('woocommerce_review_order_before_order_total'); ?>
                <tr class="order-total">
                    <th><?php esc_html_e('Total', 'briqpay-for-woocommerce'); ?></th>
                    <td><?php wc_cart_totals_order_total_html(); ?></td>
                </tr>
                <?php do_action('woocommerce_review_order_after_order_total'); ?>
            </tfoot>
        </table>
        <?php
    }

    /**
     * Enqueue Assets
     */
    private function enqueue_assets()
    {
        wp_enqueue_script('wc_checkout'); // Needed for the "normal flow" AJAX

        wp_enqueue_style('briqpay-b2b-checkout', BRIQPAY_WC_URL . 'assets/css/briqpay-b2b-checkout.css', array(), BRIQPAY_WC_VERSION);
        wp_enqueue_script('briqpay-b2b-checkout', BRIQPAY_WC_URL . 'assets/js/briqpay-b2b-checkout.js', array('jquery', 'briqpay-checkout'), BRIQPAY_WC_VERSION, true);

        wp_localize_script('briqpay-b2b-checkout', 'briqpayB2BParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_nonce'),
        ));
    }
}
