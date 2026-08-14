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
        // This is usually called when the native WC "Place Order" button is pressed.
        // However, Briqpay overrides the checkout and uses its own button in the iframe.
        // If we reach here, it means the native button was visible and clicked.
        // We block this to prevent bypassing Briqpay.
        wc_add_notice(__('Please complete your payment using the Briqpay checkout.', 'briqpay-for-woocommerce'), 'error');

        return array(
            'result' => 'failure',
            'reload' => true,
        );
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
