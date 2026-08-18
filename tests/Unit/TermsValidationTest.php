<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Tests for Checkout_Handler::terms_validation_enabled().
 *
 * The "Validate Terms & Conditions" gateway setting lets merchants who collect
 * consent elsewhere (Briqpay's own terms module, a third-party consent plugin)
 * opt out of the native WooCommerce Terms & Conditions check performed in
 * validate_data_integrity().
 *
 * Note: get_option() is hard-defined once by tests/bootstrap.php with a fixed
 * settings array that never includes 'terms_validation_enabled', and
 * WP_Mock::userFunction() cannot override an already-real function (same
 * constraint documented in LegacyB2bMetaTest and AdminOrderMetaBoxTest). So the
 * branch exercised here is the one that actually matters for existing stores:
 * a missing key must read as ENABLED, so upgrading never silently drops a
 * purchase guard.
 */
class TermsValidationTest extends TestCase
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
        \Mockery::close();
        parent::tearDown();
    }

    public function testEnabledByDefaultWhenSettingAbsent(): void
    {
        // Opposite default to Legacy_B2b_Meta::is_enabled(): that one adds
        // behaviour and defaults off, this one removes a guard and defaults on.
        $this->assertTrue(Checkout_Handler::terms_validation_enabled());
    }

    public function testIsAStaticCallRequiringNoWooCommerceContext(): void
    {
        // validate_data_integrity() is not the only caller-to-be; keep this
        // callable from webhook/CLI contexts where WC() is unavailable.
        $this->assertTrue(is_callable(array(Checkout_Handler::class, 'terms_validation_enabled')));

        $method = new \ReflectionMethod(Checkout_Handler::class, 'terms_validation_enabled');
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }
}
