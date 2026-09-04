<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../');
}

if (!defined('BRIQPAY_WC_PATH')) {
    define('BRIQPAY_WC_PATH', __DIR__ . '/../');
}

if (!defined('BRIQPAY_WC_URL')) {
    define('BRIQPAY_WC_URL', 'https://example.com/wp-content/plugins/briqpay-for-woocommerce/');
}

if (!defined('BRIQPAY_WC_VERSION')) {
    define('BRIQPAY_WC_VERSION', '1.0.12');
}

// WordPress Constants
if (!defined('MINUTE_IN_SECONDS'))
    define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS'))
    define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))
    define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS'))
    define('WEEK_IN_SECONDS', 604800);
if (!defined('MONTH_IN_SECONDS'))
    define('MONTH_IN_SECONDS', 2592000);
if (!defined('YEAR_IN_SECONDS'))
    define('YEAR_IN_SECONDS', 31536000);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Recording do_action().
 *
 * WP_Mock defines do_action() itself, but only behind function_exists(), and it
 * is loaded by WP_Mock::bootstrap() below - so defining it first wins. This
 * version behaves identically (it delegates to the same WP_Mock call, so
 * WP_Mock::expectAction() and onAction() keep working) and additionally records
 * every fired action.
 *
 * Why: the checkout hook parity work has to prove a negative - that a store
 * which has not opted in fires NO WooCommerce checkout actions. WP_Mock's own
 * API can only assert on actions a test registered in advance, so an action
 * firing with unexpected arguments would go unnoticed. A global recorder catches
 * everything.
 *
 * Read it with Briqpay_Test_Actions::fired() / ::matching() / ::reset().
 */
class Briqpay_Test_Actions
{
    /** @var array<int,array{tag:string,args:array}> */
    public static $fired = array();

    /**
     * Forget everything recorded so far. Call in setUp().
     */
    public static function reset()
    {
        self::$fired = array();
    }

    /**
     * All fired action names, in order, duplicates preserved.
     *
     * @return string[]
     */
    public static function fired()
    {
        return array_column(self::$fired, 'tag');
    }

    /**
     * Fired action names beginning with $prefix, in order.
     *
     * @param string $prefix
     * @return string[]
     */
    public static function matching($prefix)
    {
        return array_values(array_filter(
            self::fired(),
            function ($tag) use ($prefix) {
                return 0 === strpos($tag, $prefix);
            }
        ));
    }

    /**
     * The arguments the named action was fired with the first time.
     *
     * @param string $tag
     * @return array|null
     */
    public static function argsFor($tag)
    {
        foreach (self::$fired as $entry) {
            if ($entry['tag'] === $tag) {
                return $entry['args'];
            }
        }
        return null;
    }

    /**
     * How many times the named action fired.
     *
     * @param string $tag
     * @return int
     */
    public static function countFor($tag)
    {
        return count(array_keys(self::fired(), $tag, true));
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, $arg = '')
    {
        $args = array_slice(func_get_args(), 1);

        Briqpay_Test_Actions::$fired[] = array('tag' => $tag, 'args' => $args);

        // Same delegation as WP_Mock's own implementation, so tests using
        // WP_Mock::expectAction() / onAction() are unaffected.
        return \WP_Mock::onAction($tag)->react($args);
    }
}

// WP_Mock defines add_action() itself (backed by expectActionAdded()/
// onActionAdded(), which several tests rely on - see NativeCheckoutParityTest
// and UpgradeMigrationTest), but it does not define has_action() or
// remove_action() at all. Checkout_Handler::fire_commit_hooks() calls both
// (to unhook core's own wc_reserve_stock_for_order around a do_action()
// replay), so leaving them undefined would fatal. In this pure-unit
// environment no real WordPress core ever registers wc_reserve_stock_for_order
// via add_action(), so reporting "not registered" is simply correct - it also
// means these stubs never need to interact with WP_Mock's add_action registry.
if (!function_exists('has_action')) {
    function has_action($tag, $function_to_check = false)
    {
        return false;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($tag, $function_to_remove, $priority = 10)
    {
        return false;
    }
}

WP_Mock::bootstrap();

/**
 * Custom autoloader for Briqpay classes to handle class-*.php naming convention.
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'Briqpay\\WooCommerce\\') !== 0) {
        return;
    }

    $relative_class = substr($class, strlen('Briqpay\\WooCommerce\\'));
    $filename = 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    $file = __DIR__ . '/../includes/' . $filename;

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Minimal WC_Payment_Gateway stub.
 *
 * Briqpay\WooCommerce\Gateway extends it, so the class cannot even be loaded -
 * let alone reflected on - without a parent present. Only enough surface to load
 * and reflect; nothing here is exercised as behaviour.
 */
if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public $id;
        public $title;
        public $description;
        public $enabled;
        public $method_title;
        public $method_description;
        public $has_fields;
        public $supports = array();
        public $form_fields = array();
        public $settings = array();

        public function init_settings()
        {
        }

        public function init_form_fields()
        {
        }

        public function get_option($key, $empty_value = null)
        {
            return array_key_exists($key, $this->settings) ? $this->settings[$key] : $empty_value;
        }

        public function process_admin_options()
        {
            return true;
        }

        public function add_error($error)
        {
        }

        public function get_return_url($order = null)
        {
            return 'https://example.com/order-received/';
        }
    }
}

/**
 * In-memory options store.
 *
 * Briqpay\WooCommerce\Lock relies on add_option()'s INSERT-or-fail semantics for
 * atomicity, so the tests need a store where add_option() genuinely refuses to
 * overwrite. update_option() is deliberately left undefined so tests that mock it
 * through WP_Mock keep working.
 *
 * Reset between tests with Briqpay_Test_Options::reset().
 */
class Briqpay_Test_Options
{
    /** @var array<string,mixed> */
    public static $store = array();

    public static function reset()
    {
        self::$store = array();
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if ($option === 'woocommerce_briqpay_settings') {
            return array('logging' => 'yes', 'merchant_id' => '123', 'shared_secret' => '456', 'testmode' => 'yes');
        }
        if (array_key_exists($option, Briqpay_Test_Options::$store)) {
            return Briqpay_Test_Options::$store[$option];
        }
        return $default;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
        // Mirrors WordPress: refuses if the option already exists. This is the
        // property Lock depends on.
        if (array_key_exists($option, Briqpay_Test_Options::$store)) {
            return false;
        }
        Briqpay_Test_Options::$store[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        if (!array_key_exists($option, Briqpay_Test_Options::$store)) {
            return false;
        }
        unset(Briqpay_Test_Options::$store[$option]);
        return true;
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($id = false) {
        if (is_object($id)) {
            return $id;
        }
        return null;
    }
}
