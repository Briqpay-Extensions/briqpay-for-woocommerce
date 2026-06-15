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

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if ($option === 'woocommerce_briqpay_settings') {
            return array('logging' => 'yes', 'merchant_id' => '123', 'shared_secret' => '456', 'testmode' => 'yes');
        }
        return $default;
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
