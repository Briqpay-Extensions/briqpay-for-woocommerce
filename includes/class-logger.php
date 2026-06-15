<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized Logger
 *
 * Gates all debug logging behind the gateway's "logging" setting.
 * Errors are always logged regardless of the setting.
 */
class Logger
{
    /**
     * Cached logging-enabled flag (null = not yet resolved).
     *
     * @var bool|null
     */
    private static $enabled = null;

    /**
     * Cached verbose logging-enabled flag (null = not yet resolved).
     *
     * @var bool|null
     */
    private static $verbose = null;

    /**
     * Check whether verbose debug logging is enabled.
     *
     * @return bool
     */
    private static function is_verbose_enabled()
    {
        if (null === self::$verbose) {
            $settings = get_option('woocommerce_briqpay_settings');
            self::$verbose = (is_array($settings) && isset($settings['verbose_logging']) && 'yes' === $settings['verbose_logging']);
        }
        return self::$verbose;
    }

    /**
     * Check whether debug logging is enabled.
     *
     * The result is cached for the duration of the request so the option
     * is read at most once.
     *
     * @return bool
     */
    private static function is_enabled()
    {
        if (null === self::$enabled) {
            $settings = get_option('woocommerce_briqpay_settings');
            self::$enabled = (is_array($settings) && isset($settings['logging']) && 'yes' === $settings['logging']);
        }
        return self::$enabled;
    }

    /**
     * Log a debug-level message.
     *
     * Only writes when the gateway's "Logging" setting is enabled.
     *
     * @param string $message The message to log.
     */
    public static function log($message)
    {
        if (!self::is_enabled()) {
            return;
        }

        if (!self::is_verbose_enabled()) {
            $chatty_patterns = array(
                'Availability Check',
                'payment_scripts()',
                'Script Check',
                'payment_fields()',
                'get_cart_items()',
                'Processing item',
                'Shipping Total',
                'Processing shipping',
                'Processing fee',
                'Processing coupon',
                'Building order data',
                'Setting URLs',
                'Setting hooks',
                'get_session_data()',
                'Building extra session data',
                'Total Check:',
                'Total changed, session will be patched',
                'Recalculating shipping and totals',
                'Skipping shipping and totals recalculation',
                'Recalculated WC Total',
                'Session Stats',
                'briqpay_force_new_session filter',
                'get_or_create_session() entered'
            );

            foreach ($chatty_patterns as $pattern) {
                if (stripos($message, $pattern) !== false) {
                    return;
                }
            }
        }

        if (!defined('WC_LOG_DIR') || !function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $logger->debug($message, array('source' => 'briqpay-for-woocommerce'));
    }

    /**
     * Log an error-level message.
     *
     * Errors are always logged regardless of the "Logging" setting
     * because they indicate problems that need attention.
     *
     * @param string $message The error message to log.
     */
    public static function error($message)
    {
        if (!defined('WC_LOG_DIR') || !function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $logger->error($message, array('source' => 'briqpay-for-woocommerce'));
    }
}
