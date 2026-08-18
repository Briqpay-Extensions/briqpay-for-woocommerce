<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version-Aware Upgrade Routine
 *
 * Runs once per plugin version change and stamps defaults for settings that
 * must NOT change behaviour for stores upgrading from an earlier version.
 *
 * Why this exists rather than register_activation_hook(): WordPress does not
 * fire the activation hook when a plugin is updated in place (manual upload,
 * auto-update, WP-CLI, or the plugins screen), only on a fresh activation. So
 * an upgrade-time migration has to be driven lazily from a request hook.
 */
class Upgrade
{
    /**
     * Option holding the version the migration last ran for.
     */
    const VERSION_OPTION = 'briqpay_wc_version';

    /**
     * Initialize Hooks
     */
    public function init()
    {
        // admin_init rather than plugins_loaded: the migration writes options,
        // so there is no reason to run it on every front-end request.
        add_action('admin_init', array(__CLASS__, 'maybe_migrate'));
    }

    /**
     * Run the migration if the stored version differs from the running one.
     *
     * @return void
     */
    public static function maybe_migrate()
    {
        $stored = get_option(self::VERSION_OPTION);

        if (BRIQPAY_WC_VERSION === $stored) {
            return;
        }

        Logger::log(sprintf(
            'Running upgrade migration. Stored version: %s, running version: %s',
            $stored ?: 'NONE',
            BRIQPAY_WC_VERSION
        ));

        self::stamp_checkout_hooks_default();

        update_option(self::VERSION_OPTION, BRIQPAY_WC_VERSION);
    }

    /**
     * Decide the initial value of the "WooCommerce checkout actions" setting.
     *
     * Firing WooCommerce's standard checkout actions makes third-party code run
     * during Briqpay checkouts where none ran before. For a store that is
     * already live that is a behaviour change we must not impose:
     *
     *  - merchants may have custom code compensating for the missing actions,
     *    which would then run alongside the plugins it stood in for, and
     *  - we do not control what any installed plugin does on those hooks.
     *
     * So an existing install is stamped 'no' and opts in deliberately, while a
     * fresh install - which has no workarounds to collide with - gets 'yes' and
     * behaves like every other payment gateway from the start.
     *
     * An existing install is identified by already-configured credentials.
     * A store that has never been given a merchant ID cannot have taken a
     * payment, so it has no behaviour worth preserving.
     *
     * @return void
     */
    private static function stamp_checkout_hooks_default()
    {
        $settings = get_option('woocommerce_briqpay_settings', array());

        if (!is_array($settings)) {
            return;
        }

        // Never overwrite an explicit choice - including one made by an earlier
        // run of this migration.
        if (isset($settings['checkout_hooks_enabled'])) {
            return;
        }

        $is_existing_install = !empty($settings['merchant_id']);
        $settings['checkout_hooks_enabled'] = $is_existing_install ? 'no' : 'yes';

        update_option('woocommerce_briqpay_settings', $settings);

        Logger::log(sprintf(
            'Stamped checkout_hooks_enabled=%s (%s install).',
            $settings['checkout_hooks_enabled'],
            $is_existing_install ? 'existing' : 'fresh'
        ));
    }
}
