<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Briqpay Session Reset on User Login
 * 
 * This ensures that when a user logs in, any existing guest session
 * is cleared so a fresh one can be created in the correct user context.
 */
class Session_Reset_Handler
{
    /**
     * Initialize Hooks
     */
    public function init()
    {
        add_action('wp_login', array($this, 'schedule_session_reset_on_login'), 10, 2);
        add_action('wp_loaded', array($this, 'process_scheduled_session_reset'));
    }

    /**
     * Schedule session reset when a user logs in.
     * 
     * Since the WC session might not be fully initialized during the login hook,
     * we set a user meta flag to process the reset on the next request.
     * 
     * @param string   $user_login User login name.
     * @param \WP_User $user       User object.
     */
    public function schedule_session_reset_on_login($user_login, $user)
    {
        if (isset($user->ID)) {
            update_user_meta($user->ID, '_briqpay_reset_session', '1');
        }
    }

    /**
     * Process the scheduled session reset on the next request.
     */
    public function process_scheduled_session_reset()
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        if ('1' !== get_user_meta($user_id, '_briqpay_reset_session', true)) {
            return;
        }

        // Clear the flag immediately to avoid multiple runs
        delete_user_meta($user_id, '_briqpay_reset_session');

        // Reset the Briqpay session
        Logger::log('Login detected. Resetting Briqpay session for user: ' . $user_id);

        // Clear WC session data
        if (null !== WC() && null !== WC()->session) {
            WC()->session->set('briqpay_session_id', null);
            WC()->session->set('briqpay_customer_type', null);
            WC()->session->set('briqpay_prev_b2b_active', null);
        }

        // Force clear the session cookie too
        if (isset($_COOKIE['briqpay_session_id'])) {
            setcookie('briqpay_session_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE['briqpay_session_id']);
        }
    }
}
