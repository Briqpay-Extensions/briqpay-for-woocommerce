<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Legacy_B2b_Meta;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * T1 - The gate.
 *
 * Firing WooCommerce's standard checkout actions makes third-party code run
 * during Briqpay checkouts where none ran before. Existing stores must be able
 * to upgrade with no behavioural change at all, so everything added for hook
 * parity sits behind one setting plus a per-hook filter.
 *
 * Note on get_option(): tests/bootstrap.php hard-defines it with a fixed
 * settings array, and WP_Mock::userFunction() cannot override an already-real
 * function (documented in LegacyB2bMetaTest and AdminOrderMetaBoxTest). That
 * array has no 'checkout_hooks_enabled' key, so the absent-key default is what
 * is exercised directly here. The explicit 'yes'/'no' branches are covered in
 * UpgradeMigrationTest, which drives the decision logic through arrays it owns.
 */
class CheckoutHookGateTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1.2 - the absent-key default
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The key is only absent before Upgrade::maybe_migrate() has run. That
     * migration stamps 'no' for stores with credentials configured, so this
     * default is what a fresh install sees, never a live store mid-upgrade.
     */
    public function testAbsentSettingReadsAsEnabled(): void
    {
        $this->assertTrue(Checkout_Handler::checkout_hooks_enabled());
    }

    /**
     * The opposite default to Legacy_B2b_Meta::is_enabled(), on purpose.
     * Documented so neither is later "corrected" to match the other.
     */
    public function testDefaultDiffersFromLegacyMetaMappingOnPurpose(): void
    {
        // legacy_b2b_meta_mapping only adds order meta, so it defaults OFF.
        $this->assertFalse(Legacy_B2b_Meta::is_enabled());
        // checkout_hooks_enabled runs third-party code, so the migration decides
        // per store; the raw reader's fallback is ON, for fresh installs.
        $this->assertTrue(Checkout_Handler::checkout_hooks_enabled());
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1.6 - per-hook escape hatch
    // ──────────────────────────────────────────────────────────────────────

    public function testFilterCanDisableASingleHookWhileOthersStillFire(): void
    {
        WP_Mock::onFilter('briqpay_fire_checkout_hook')
            ->with(true, 'woocommerce_checkout_update_order_meta')
            ->reply(false);

        $this->assertFalse(
            Checkout_Handler::hook_enabled('woocommerce_checkout_update_order_meta'),
            'The named hook must be individually disableable.'
        );
        $this->assertTrue(
            Checkout_Handler::hook_enabled('woocommerce_checkout_create_order'),
            'Silencing one hook must not silence the others.'
        );
    }

    public function testFilterCanDisableEveryHookIndividually(): void
    {
        $hooks = array(
            'woocommerce_checkout_create_order',
            'woocommerce_checkout_update_order_meta',
            'woocommerce_checkout_order_created',
            'woocommerce_checkout_order_processed',
            'woocommerce_checkout_create_order_line_item_object',
            'woocommerce_store_api_checkout_update_order_meta',
            'woocommerce_store_api_checkout_order_processed',
        );

        foreach ($hooks as $hook) {
            WP_Mock::onFilter('briqpay_fire_checkout_hook')
                ->with(true, $hook)
                ->reply(false);
        }

        foreach ($hooks as $hook) {
            $this->assertFalse(
                Checkout_Handler::hook_enabled($hook),
                $hook . ' must be disableable via briqpay_fire_checkout_hook.'
            );
        }
    }

    /**
     * A truthy non-boolean filter return must not leak out of the accessor -
     * callers branch on it directly.
     */
    public function testFilterReturnIsCastToBool(): void
    {
        WP_Mock::onFilter('briqpay_fire_checkout_hook')
            ->with(true, 'woocommerce_checkout_create_order')
            ->reply(1);

        $this->assertSame(true, Checkout_Handler::hook_enabled('woocommerce_checkout_create_order'));
    }

    /**
     * The master switch must be checked before the filter, so a third-party
     * filter can never opt a store in that did not opt itself in.
     */
    public function testMasterSwitchIsCheckedBeforeTheFilter(): void
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, 'hook_enabled');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $switch_pos = strpos($body, 'checkout_hooks_enabled()');
        $filter_pos = strpos($body, 'briqpay_fire_checkout_hook');

        $this->assertNotFalse($switch_pos, 'hook_enabled() must consult the setting.');
        $this->assertNotFalse($filter_pos, 'hook_enabled() must apply the per-hook filter.');
        $this->assertLessThan(
            $filter_pos,
            $switch_pos,
            'The setting must be checked before the filter, and returned on early.'
        );
        $this->assertStringContainsString(
            'return false;',
            substr($body, $switch_pos, $filter_pos - $switch_pos),
            'The setting check must return early rather than fall through to the filter.'
        );
    }

    /**
     * Both accessors are static and free of WC dependencies, because the webhook
     * fallback consults the gate with no WC session, cart or user available.
     */
    public function testAccessorsAreStaticForWebhookContext(): void
    {
        foreach (array('checkout_hooks_enabled', 'hook_enabled') as $name) {
            $method = new \ReflectionMethod(Checkout_Handler::class, $name);
            $this->assertTrue($method->isStatic(), $name . '() must be static.');
            $this->assertTrue($method->isPublic(), $name . '() must be public.');
        }
    }
}
