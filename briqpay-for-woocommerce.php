<?php
/**
 * Plugin Name: Briqpay for WooCommerce
 * Plugin URI: https://github.com/Briqpay-Extensions/briqpay-for-woocommerce
 * Description: Briqpay connects multiple payment providers like Adyen, Stripe, PayPal, and Klarna in one integration.
 * Version: 1.1.4
 * Author: Briqpay
 * Author URI: https://briqpay.com
 * Text Domain: briqpay-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.5
 * WC tested up to: 11.0
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BRIQPAY_WC_VERSION', '1.1.4');
define('BRIQPAY_WC_PLUGIN_FILE', __FILE__);
define('BRIQPAY_WC_PATH', plugin_dir_path(__FILE__));
define('BRIQPAY_WC_URL', plugin_dir_url(__FILE__));

/**
 * Main Briqpay Class
 */
if (!class_exists('Briqpay_WooCommerce')) {
    /**
     * Main Briqpay Class
     */
    class Briqpay_WooCommerce
    {

        /**
         * @var Briqpay_WooCommerce
         */
        private static $instance;

        /**
         * Get Instance
         */
        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor
         */
        private function __construct()
        {
            $this->init();
        }

        /**
         * Initialize the plugin
         */
        private function init()
        {
            // Autoloader for PSR-4
            spl_autoload_register(array($this, 'autoload'));

            // Declare HPOS compatibility
            add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

            // Hooks
            add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
            add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'), 20);
            add_filter('woocommerce_available_payment_gateways', array($this, 'check_available_gateways'), 999);

            if (did_action('woocommerce_blocks_loaded')) {
                $this->register_blocks_integration();
            } else {
                add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_integration'));
            }

            // Classic Checkout detection
            add_action('woocommerce_checkout_billing', function () {
                // Keep this for future diagnostics if needed, but hushed for now
            });

            // Blocks Checkout detection (REST API)
            add_action('woocommerce_blocks_checkout_get_checkout_data', function () {
                // Keep this for future diagnostics if needed, but hushed for now
            });
        }

        /**
         * Register Blocks Integration
         */
        public function register_blocks_integration()
        {

            add_action('woocommerce_blocks_payment_method_type_registration', function ($payment_method_registry) {
                try {
                    $integration = new \Briqpay\WooCommerce\Blocks_Integration();
                    $payment_method_registry->register($integration);
                } catch (\Exception $e) {
                    \Briqpay\WooCommerce\Logger::log('ERROR registering blocks integration: ' . $e->getMessage());
                }
            });
        }


        /**
         * Check available gateways for diagnostics
         */
        public function check_available_gateways($gateways)
        {
            return $gateways;
        }

        /**
         * PSR-4 Autoloader
         */
        public function autoload($class)
        {
            $prefix = 'Briqpay\\WooCommerce\\';
            $base_dir = BRIQPAY_WC_PATH . 'includes/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . 'class-' . str_replace('_', '-', strtolower(str_replace('\\', '/', $relative_class))) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        }

        /**
         * Declare HPOS and Blocks compatibility
         */
        public function declare_hpos_compatibility()
        {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
            }
        }

        /**
         * On plugins loaded
         */
        public function on_plugins_loaded()
        {
            if (!class_exists('WC_Payment_Gateway')) {
                return;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Briqpay\WooCommerce\Logger::log('Initializing Briqpay components...');
            }

            // Initialize components
            (new \Briqpay\WooCommerce\Order_Status_Manager())->init();
            (new \Briqpay\WooCommerce\Checkout_Handler())->init();
            (new \Briqpay\WooCommerce\B2b_Checkout())->init();
            (new \Briqpay\WooCommerce\Webhooks())->init();
            (new \Briqpay\WooCommerce\Order_Management())->init();
            (new \Briqpay\WooCommerce\Admin_Order_Meta_Box())->init();
            (new \Briqpay\WooCommerce\Session_Reset_Handler())->init();
            (new \Briqpay\WooCommerce\Pay_Button_Handler())->init();
            (new \Briqpay\WooCommerce\Hosted_Payment_Page())->init();
            \Briqpay\WooCommerce\Legacy_B2b_Meta::init();
        }

        /**
         * Add the gateway to WooCommerce
         */
        public function add_gateway($gateways)
        {
            $gateways[] = 'Briqpay\\WooCommerce\\Gateway';
            return $gateways;
        }
    }
}

// Initialize the plugin
Briqpay_WooCommerce::get_instance();

// Register activation and deactivation hooks
register_activation_hook(BRIQPAY_WC_PLUGIN_FILE, function() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-order-status-manager.php';
    \Briqpay\WooCommerce\Order_Status_Manager::schedule_events();
});

register_deactivation_hook(BRIQPAY_WC_PLUGIN_FILE, function() {
    require_once plugin_dir_path(__FILE__) . 'includes/class-order-status-manager.php';
    \Briqpay\WooCommerce\Order_Status_Manager::unschedule_events();
});
