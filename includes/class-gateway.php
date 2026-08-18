<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Payment Gateway
 */
class Gateway extends \WC_Payment_Gateway
{

    /**
     * @var bool
     */
    public $testmode;

    /**
     * @var string
     */
    public $merchant_id;

    /**
     * @var string
     */
    public $shared_secret;

    /**
     * @var bool
     */
    public $order_management_enabled;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'briqpay';
        $this->method_title = __('Briqpay', 'briqpay-for-woocommerce');
        $this->method_description = __('Briqpay streamlines all payment methods into one integration.', 'briqpay-for-woocommerce');
        $this->has_fields = true; // We use an iframe

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = 'Briqpay';
        $this->description = 'Pay securely with Briqpay';
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->shared_secret = $this->get_option('shared_secret');
        $this->order_management_enabled = 'yes' === $this->get_option('order_management_enabled');
        $this->verbose_logging = 'yes' === $this->get_option('verbose_logging');

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('wp_footer', array($this, 'output_overlay'));

        // Supported features
        $this->supports = array(
            'products',
            'refunds',
            'shipping'
        );
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Briqpay', 'briqpay-for-woocommerce'),
                'default' => 'no',
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID (MID)', 'briqpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Found in Briqpay Dashboard.', 'briqpay-for-woocommerce'),
            ),
            'shared_secret' => array(
                'title' => __('Shared Secret', 'briqpay-for-woocommerce'),
                'type' => 'password',
                'description' => __('Found in Briqpay Dashboard.', 'briqpay-for-woocommerce'),
            ),
            'testmode' => array(
                'title' => __('Test mode', 'briqpay-for-woocommerce'),
                'label' => __('Enable Test Mode', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using playground API.', 'briqpay-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'order_management_enabled' => array(
                'title' => __('Enable Briqpay Order Management', 'briqpay-for-woocommerce'),
                'label' => __('Enable Order Management', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('If enabled, captures and refunds will be handled automatically towards Briqpay.', 'briqpay-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'terms_validation_enabled' => array(
                'title' => __('Validate Terms & Conditions', 'briqpay-for-woocommerce'),
                'label' => __('Require the WooCommerce Terms & Conditions checkbox', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('When enabled, a purchase is rejected at the payment decision unless the customer ticked WooCommerce\'s native Terms & Conditions checkbox. Disable this if you collect consent elsewhere - for example with Briqpay\'s own terms module, or a third-party consent plugin - to avoid asking the customer twice. Has no effect when WooCommerce has no Terms and conditions page configured.', 'briqpay-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => false,
            ),
            'checkout_hooks_enabled' => array(
                'title' => __('WooCommerce checkout actions', 'briqpay-for-woocommerce'),
                'label' => __('Fire WooCommerce\'s standard checkout actions', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Lets third-party plugins (custom checkout fields, ERP and invoicing connectors, delivery-date pickers) receive Briqpay orders the same way they receive orders paid with other methods. <strong>Before enabling, check any custom code you added to compensate for these actions being missing</strong> - for example on <code>briqpay_after_create_order</code> - because it will now run alongside the plugins it was standing in for. Existing stores keep this off until you turn it on.', 'briqpay-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => false,
            ),
            'hpp_section' => array(
                'title' => __('Hosted Payment Pages', 'briqpay-for-woocommerce'),
                'type' => 'title',
                'description' => __('Create a Briqpay hosted payment page for an order you have built in the WooCommerce admin, then send the link to your customer.', 'briqpay-for-woocommerce'),
            ),
            'hpp_enabled' => array(
                'title' => __('Enable Hosted Payment Pages', 'briqpay-for-woocommerce'),
                'label' => __('Enable Hosted Payment Pages', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Adds a "Briqpay Hosted Payment Page" box to the order edit screen.', 'briqpay-for-woocommerce'),
                'default' => 'no',
                'desc_tip' => true,
            ),
            'hpp_default_flow' => array(
                'title' => __('Default flow', 'briqpay-for-woocommerce'),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'description' => __('Pre-selected on the order screen. You can always pick a different flow when creating the page.', 'briqpay-for-woocommerce'),
                'default' => 'b2c',
                'desc_tip' => true,
                'options' => array(
                    'b2c' => __('Consumer', 'briqpay-for-woocommerce'),
                    'b2b_payment_module' => __('Business - Payment Methods Only', 'briqpay-for-woocommerce'),
                    'b2b_checkout' => __('Business - Full Checkout', 'briqpay-for-woocommerce'),
                ),
            ),
            'hpp_page_title' => array(
                'title' => __('Hosted page title', 'briqpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Short text shown to the customer beneath your logo. Between 3 and 256 characters; leave empty to omit.', 'briqpay-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'hpp_logo_url' => array(
                'title' => __('Hosted page logo URL', 'briqpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Absolute URL to a PNG, JPG, JPEG or SVG image (max 512 characters). Leave empty to omit.', 'briqpay-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'hpp_show_cart' => array(
                'title' => __('Show cart on hosted page', 'briqpay-for-woocommerce'),
                'label' => __('Display the order lines above the payment section', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'logging' => array(
                'title' => __('Logging', 'briqpay-for-woocommerce'),
                'label' => __('Log Briqpay events', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Log Briqpay events, such as API requests.', 'briqpay-for-woocommerce'),
                'default' => 'no',
            ),
            'verbose_logging' => array(
                'title' => __('Verbose Logging', 'briqpay-for-woocommerce'),
                'label' => __('Enable verbose debug logs', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Include detailed trace messages like availability checks and cart processing.', 'briqpay-for-woocommerce'),
                'default' => 'no',
            ),
            'legacy_section' => array(
                'title' => __('Migration / legacy compatibility', 'briqpay-for-woocommerce'),
                'type' => 'title',
                'description' => __('Options for stores migrating from the previous Briqpay for WooCommerce plugin.', 'briqpay-for-woocommerce'),
            ),
            'legacy_b2b_meta_mapping' => array(
                'title' => __('Legacy B2B order meta mapping', 'briqpay-for-woocommerce'),
                'label' => __('Also store B2B order data using the previous plugin\'s meta keys', 'briqpay-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('For stores migrating from the previous Briqpay plugin. B2B orders additionally store the organisation number in <code>_billing_org_nr</code> and the shipping email in <code>_shipping_email</code>, so existing ERP exports and integrations keep working. Current meta keys are always written as well.', 'briqpay-for-woocommerce'),
                'default' => 'no',
                'desc_tip' => false,
            ),
        );
    }

    /**
     * Payment Scripts
     */
    public function payment_scripts()
    {
        Logger::log('payment_scripts() called.');
        if ('no' === $this->enabled) {
            Logger::log('payment_scripts() aborted. Gateway disabled.');
            return;
        }

        // Check if we are on checkout page or any page containing the checkout block/shortcode
        $post_content = is_singular() ? get_post()->post_content : '';
        $is_checkout = is_checkout();
        $is_checkout_page = is_page(wc_get_page_id('checkout'));
        $has_checkout_block = has_block('woocommerce/checkout');
        $has_iframe_shortcode = has_shortcode($post_content, 'briqpay_iframe');
        $has_b2b_shortcode = has_shortcode($post_content, 'briqpay_b2b_checkout');

        Logger::log(sprintf(
            'Script Check: is_checkout=%s, is_checkout_page=%s, has_checkout_block=%s, has_iframe_shortcode=%s, has_b2b_shortcode=%s, page_id=%s',
            $is_checkout ? 'yes' : 'no',
            $is_checkout_page ? 'yes' : 'no',
            $has_checkout_block ? 'yes' : 'no',
            $has_iframe_shortcode ? 'yes' : 'no',
            $has_b2b_shortcode ? 'yes' : 'no',
            get_the_ID()
        ));

        if (!$is_checkout && !$is_checkout_page && !$has_checkout_block && !$has_iframe_shortcode && !$has_b2b_shortcode) {
            Logger::log('payment_scripts() aborted. Not identified as checkout or iframe page.');
            return;
        }

        Logger::log('Enqueuing checkout.js');
        wp_enqueue_style('briqpay-checkout-style', BRIQPAY_WC_URL . 'assets/css/briqpay-checkout.css', array(), BRIQPAY_WC_VERSION);
        wp_enqueue_script('briqpay-checkout', BRIQPAY_WC_URL . 'assets/js/checkout.js', array('jquery'), BRIQPAY_WC_VERSION, true);

        wp_localize_script('briqpay-checkout', 'briqpayParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_nonce'),
        ));
    }

    /**
     * Check if the gateway is available.
     */
    public function is_available()
    {
        $enabled = 'yes' === $this->enabled;
        $has_creds = !empty($this->merchant_id) && !empty($this->shared_secret);
        $order_total = 0;
        if (null !== WC()->cart) {
            $order_total = WC()->cart->get_total('edit');
        }

        Logger::log(sprintf(
            'Availability Check: Enabled=%s, HasCreds=%s, Currency=%s, OrderTotal=%s',
            $enabled ? 'yes' : 'no',
            $has_creds ? 'yes' : 'no',
            get_woocommerce_currency(),
            $order_total
        ));

        if (!$enabled) {
            return false;
        }

        if (!$has_creds) {
            return false;
        }

        // Every amount this plugin sends is converted to minor units assuming two
        // decimal places. On a store configured otherwise those amounts are wrong
        // by a factor of ten or more, so refuse to offer the gateway rather than
        // taking payments for the wrong sum. Overridable for a merchant who has
        // verified their own setup.
        if (!Money::is_supported_precision()) {
            $allow = (bool) apply_filters('briqpay_allow_unsupported_currency_precision', false, Money::decimals());

            if (!$allow) {
                Logger::error(sprintf(
                    'Briqpay hidden at checkout: the store is configured for %d decimal places, '
                    . 'but Briqpay amount conversion supports 2. Amounts would be sent incorrectly. '
                    . 'Set WooCommerce > Settings > Products > Number of decimals to 2, or override '
                    . 'with the briqpay_allow_unsupported_currency_precision filter if your setup is verified.',
                    Money::decimals()
                ));
                return false;
            }

            Logger::error(sprintf(
                'Briqpay running with %d decimal places via '
                . 'briqpay_allow_unsupported_currency_precision. Amounts may be sent incorrectly.',
                Money::decimals()
            ));
        }

        /**
         * Filter whether the Briqpay payment gateway is available.
         *
         * @param bool    $available Whether the gateway is available.
         * @param Gateway $gateway   The gateway instance.
         */
        return apply_filters('briqpay_is_available', true, $this);
    }

    /**
     * Output the container for the Briqpay iframe.
     */
    public function payment_fields()
    {
        Logger::log('payment_fields() called.');
        echo '<div id="briqpay-iframe-container"></div>';
    }

    /**
     * Output persistent overlay in footer
     */
    public function output_overlay()
    {
        $post_content = is_singular() ? get_post()->post_content : '';
        if ((!is_checkout() || is_order_received_page()) && !has_shortcode($post_content, 'briqpay_iframe')) {
            return;
        }
        echo '<div id="briqpay-overlay" style="display:none;"></div>';
    }

    /**
     * Process Payment
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Normally the Briqpay iframe owns the purchase and its own button drives
        // the decision flow, so reaching here means WooCommerce's native Place
        // Order button was used. That is not automatically an error: the customer
        // may have completed payment inside the iframe and then submitted the
        // native form, in which case failing would strand a paid order.
        //
        // So: succeed only when Briqpay itself confirms the session is paid. The
        // check is an API call against the same standard the return handler uses -
        // never a local flag, and never an assumption - because returning success
        // for an unpaid order is the worst failure mode a gateway has.
        if ($order && $this->session_is_paid_for_order($order)) {
            Logger::log(sprintf(
                'process_payment(): Briqpay session for order %s is confirmed paid. '
                . 'Completing through WooCommerce\'s pipeline.',
                $order_id
            ));

            $session_id = Session_Manager::get_session_id();
            if ($session_id) {
                $order->update_meta_data('_briqpay_session_id', $session_id);
                $order->save();
            }

            return array(
                'result' => 'success',
                'redirect' => add_query_arg('briqpay_return', '1', $order->get_checkout_payment_url(true)),
            );
        }

        // Not paid: block, so the native button cannot bypass the Briqpay flow.
        Logger::log(sprintf(
            'process_payment(): no confirmed Briqpay payment for order %s - directing '
            . 'the customer back to the Briqpay checkout.',
            $order_id
        ));

        wc_add_notice(__('Please complete your payment using the Briqpay checkout.', 'briqpay-for-woocommerce'), 'error');

        return array(
            'result' => 'failure',
            'reload' => true,
        );
    }

    /**
     * Has Briqpay confirmed payment for this order's session?
     *
     * Deliberately strict. Anything other than an explicit paid status from the
     * API - a missing session, an API error, an unexpected shape - reads as "not
     * paid", so a transient network failure can never be mistaken for a payment.
     *
     * @param \WC_Order $order The order.
     * @return bool
     */
    private function session_is_paid_for_order($order)
    {
        $session_id = $order->get_meta('_briqpay_session_id');
        if (!$session_id) {
            $session_id = Session_Manager::get_session_id();
        }

        if (!$session_id) {
            return false;
        }

        $api = new API($this->merchant_id, $this->shared_secret, 'yes' === $this->testmode);
        $session = $api->get_session($session_id);

        if (is_wp_error($session) || !is_array($session)) {
            Logger::log('process_payment(): could not verify the Briqpay session - treating as unpaid.');
            return false;
        }

        // A session or order status alone is not proof of payment - the
        // transaction underneath can be pending or rejected. Refuse outright when
        // the payload shows no approved transaction.
        if ('unapproved' === Order_Management::transaction_approval_state($session)) {
            Logger::log('process_payment(): session carries no approved transaction - treating as unpaid.');
            return false;
        }

        // Same statuses the return handler and webhooks treat as money secured.
        $paid_statuses = array('completed', 'order_approved_not_captured', 'captured');
        $status = $session['status'] ?? '';

        if (in_array($status, $paid_statuses, true)) {
            return true;
        }

        $order_status = $session['data']['order']['status'] ?? '';

        return in_array($order_status, $paid_statuses, true);
    }

    /**
     * Process Refund
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$this->order_management_enabled) {
            return false;
        }

        // Logic handled in Briqpay_Order_Management
        return apply_filters('briqpay_process_refund', false, $order_id, $amount, $reason);
    }

    /**
     * Validate the hosted page title setting field.
     */
    public function validate_hpp_page_title_field($key, $value)
    {
        return Hosted_Payment_Page::sanitize_page_title($value);
    }

    /**
     * Validate the hosted page logo URL setting field.
     */
    public function validate_hpp_logo_url_field($key, $value)
    {
        $sanitized = Hosted_Payment_Page::sanitize_logo_url($value);
        if ('' === $sanitized && '' !== trim((string) $value)) {
            \WC_Admin_Settings::add_error(__('Briqpay: the hosted page logo URL must be an absolute URL ending in .png, .jpg, .jpeg or .svg, and at most 512 characters.', 'briqpay-for-woocommerce'));
        }
        return $sanitized;
    }
}
