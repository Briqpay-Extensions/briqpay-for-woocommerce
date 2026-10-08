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
    /** @var array<string,callable[]> Callbacks a test wants run when an action fires. */
    public static $listeners = array();

    public static function reset()
    {
        self::$fired = array();
        self::$listeners = array();
    }

    /**
     * Run $callback with the action's arguments whenever $tag fires. For the
     * cases WP_Mock::onAction() cannot express: zero-argument actions and
     * arguments (objects, arrays) that cannot be matched ahead of time.
     */
    public static function listen($tag, callable $callback)
    {
        self::$listeners[$tag][] = $callback;
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

        foreach (Briqpay_Test_Actions::$listeners[$tag] ?? array() as $listener) {
            call_user_func_array($listener, $args);
        }

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

// A WP_Error with WordPress's real surface, for every test. Several test files
// carry a minimal guarded copy of their own; this one loads first and wins.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $errors = array();
        public $error_data = array();
        public function __construct($code = '', $message = '', $data = '')
        {
            if ('' !== $code) {
                $this->add($code, $message, $data);
            }
        }
        public function add($code, $message, $data = '')
        {
            $this->errors[$code][] = $message;
            if ('' !== $data) {
                $this->error_data[$code] = $data;
            }
        }
        public function get_error_codes()
        {
            return array_keys($this->errors);
        }
        public function get_error_code()
        {
            $codes = $this->get_error_codes();
            return $codes ? $codes[0] : '';
        }
        public function get_error_messages($code = '')
        {
            if ('' !== $code) {
                return isset($this->errors[$code]) ? $this->errors[$code] : array();
            }
            $all = array();
            foreach ($this->errors as $messages) {
                $all = array_merge($all, $messages);
            }
            return $all;
        }
        public function get_error_message($code = '')
        {
            $code = '' !== $code ? $code : $this->get_error_code();
            return isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
        }
        public function get_error_data($code = '')
        {
            $code = '' !== $code ? $code : $this->get_error_code();
            return isset($this->error_data[$code]) ? $this->error_data[$code] : null;
        }
        public function has_errors()
        {
            return !empty($this->errors);
        }
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
        // The store wins even for the gateway settings, so a test that needs a
        // particular setting (say, enabled = yes) can put one there; the defaults
        // below stand for every test that does not care.
        if ($option === 'woocommerce_briqpay_settings' && !array_key_exists($option, Briqpay_Test_Options::$store)) {
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

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $changed = !array_key_exists($option, Briqpay_Test_Options::$store) || Briqpay_Test_Options::$store[$option] !== $value;
        Briqpay_Test_Options::$store[$option] = $value;
        return $changed;
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

/**
 * Orders a test wants wc_get_order() to find by id.
 */
class Briqpay_Test_Orders
{
    /** @var array<int,object> */
    public static $by_id = array();

    public static function register($id, $order)
    {
        self::$by_id[(int) $id] = $order;
    }

    public static function reset()
    {
        self::$by_id = array();
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($id = false) {
        if (is_object($id)) {
            return $id;
        }
        if (is_numeric($id) && isset(Briqpay_Test_Orders::$by_id[(int) $id])) {
            return Briqpay_Test_Orders::$by_id[(int) $id];
        }
        return null;
    }
}
