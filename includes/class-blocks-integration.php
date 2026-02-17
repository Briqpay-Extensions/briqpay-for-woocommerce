<?php
namespace Briqpay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Blocks Integration
 */
class Blocks_Integration extends AbstractPaymentMethodType
{
    /**
     * Name of the payment method.
     *
     * @var string
     */
    protected $name = 'briqpay';

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_briqpay_settings', array());
    }

    /**
     * Returns whether this payment method is active.
     *
     * @return boolean
     */
    public function is_active()
    {
        $active = !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
        error_log('[Briqpay] Blocks_Integration::is_active: ' . ($active ? 'yes' : 'no'));
        return $active;
    }

    /**
     * Returns an array of supported features.
     *
     * @return string[]
     */
    public function get_supported_features()
    {
        return array('products', 'refunds', 'shipping');
    }

    /**
     * Returns an array of scripts/plugin IDs to register as dependencies.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles()
    {
        // Script is enqueued in Gateway::payment_scripts, but we need it here too as dependency
        wp_register_script(
            'briqpay-checkout',
            BRIQPAY_WC_URL . 'assets/js/checkout.js',
            array('jquery'),
            BRIQPAY_WC_VERSION,
            true
        );

        wp_localize_script('briqpay-checkout', 'briqpayParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_nonce'),
        ));

        wp_register_script(
            'briqpay-blocks-integration',
            BRIQPAY_WC_URL . 'assets/js/blocks-checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
                'briqpay-checkout',
            ),
            BRIQPAY_WC_VERSION,
            true
        );

        return array('briqpay-blocks-integration');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment method client side.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return array(
            'title' => $this->get_setting('title', 'Briqpay'),
            'description' => $this->get_setting('description', 'Pay with Briqpay'),
            'supports' => $this->get_supported_features(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('briqpay_nonce'),
        );
    }

    /**
     * Get setting from settings array.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_setting($key, $default = '')
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
}
