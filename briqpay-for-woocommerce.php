<?php
/**
 * Plugin Name: Briqpay for WooCommerce
 * Plugin URI: https://github.com/Briqpay-Extensions/briqpay-for-woocommerce
 * Description: Briqpay connects multiple payment providers like Adyen, Stripe, PayPal, and Klarna in one integration.
 * Version: 1.1.9
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
define('BRIQPAY_WC_VERSION', '1.1.9');
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

            // Bundled translations in /languages are NOT picked up automatically on
            // the WordPress versions this plugin supports: before 6.1, just-in-time
            // loading only looked in WP_LANG_DIR/plugins, not the plugin's own
            // Domain Path. The header declared the path but nothing ever loaded it,
            // so every shipped .mo was ignored. Hooked to init rather than
            // plugins_loaded because WordPress 6.7+ warns about translations loaded
            // before that point.
            add_action('init', array($this, 'load_textdomain'));
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
         * Load the plugin's own translations from /languages.
         */
        public function load_textdomain()
        {
            $domain = 'briqpay-for-woocommerce';

            // Exact match first. This also picks up an official translation in
            // WP_LANG_DIR/plugins, which must always win over anything bundled here.
            if (load_plugin_textdomain($domain, false, dirname(plugin_basename(BRIQPAY_WC_PLUGIN_FILE)) . '/languages')) {
                return;
            }

            // Nothing matched the exact locale. WordPress has far more locales than
            // any plugin ships catalogues for: a site set to de_DE_formal, de_AT,
            // pt_BR, es_MX, fr_CA, nl_BE or sv_FI finds no file and silently falls
            // back to English - even though the LANGUAGE is one we translated. Reuse
            // the catalogue for the same base language instead.
            $locale = determine_locale();
            $fallback = self::find_language_fallback($domain, $locale);

            if ($fallback) {
                load_textdomain($domain, $fallback, $locale);
            }
        }

        /**
         * Find a shipped catalogue for the same base language as $locale.
         *
         * Driven by scanning /languages rather than a hardcoded table, so adding a
         * catalogue automatically covers every regional variant of that language.
         *
         * @param string $domain Text domain.
         * @param string $locale Locale WordPress asked for, e.g. "pt_BR".
         * @return string|false Absolute path to a .mo file, or false.
         */
        private static function find_language_fallback($domain, $locale)
        {
            $language = self::locale_base_language($locale);

            if ('' === $language) {
                return false;
            }

            // Norwegian is two written standards that share catalogues in practice;
            // WordPress offers nb_NO, nn_NO and a bare "no".
            $aliases = array('nn' => 'nb', 'no' => 'nb');
            $language = isset($aliases[$language]) ? $aliases[$language] : $language;

            $dir = plugin_dir_path(BRIQPAY_WC_PLUGIN_FILE) . 'languages/';
            $files = glob($dir . $domain . '-*.mo');

            if (empty($files)) {
                return false;
            }

            foreach ($files as $file) {
                $candidate = substr(basename($file, '.mo'), strlen($domain) + 1);

                if (self::locale_base_language($candidate) === $language) {
                    return $file;
                }
            }

            return false;
        }

        /**
         * The language part of a locale: "pt_BR" and "de_DE_formal" both reduce to
         * their first segment.
         *
         * @param string $locale
         * @return string Lowercase language code, or '' if unusable.
         */
        private static function locale_base_language($locale)
        {
            $parts = preg_split('/[_-]/', (string) $locale);

            return isset($parts[0]) ? strtolower($parts[0]) : '';
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
            // Upgrade first: it stamps settings defaults that the components
            // below read, so it must not run after they have been asked.
            (new \Briqpay\WooCommerce\Upgrade())->init();
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
