<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Tests for Checkout_Handler's capture/apply of WooCommerce's native Order
 * Attribution fields. Our decision-based order creation never goes through
 * WC_Checkout::process_checkout() (the only place WooCommerce itself
 * captures this data), so without this the admin Origin column always shows
 * "Unknown" for orders placed through the storefront.
 */
class OrderAttributionTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function invokePrivate($method, array $args)
    {
        $handler = new Checkout_Handler();
        $reflection = new \ReflectionClass(Checkout_Handler::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($handler, $args);
    }

    // -----------------------------------------------------------------
    // capture_order_attribution()
    // -----------------------------------------------------------------

    public function testCaptureOrderAttributionStashesKnownFieldsInWcSession()
    {
        $captured = null;
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('set')->with('briqpay_order_attribution', Mockery::on(function ($value) use (&$captured) {
            $captured = $value;
            return true;
        }))->once();

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));

        $this->invokePrivate('capture_order_attribution', array(array(
            'wc_order_attribution_source_type' => 'organic',
            'wc_order_attribution_utm_source' => 'google',
            'billing_first_name' => 'Jane', // unrelated checkout field, must be ignored
        )));

        $this->assertEquals('organic', $captured['source_type']);
        $this->assertEquals('google', $captured['utm_source']);
        $this->assertArrayNotHasKey('billing_first_name', $captured);
    }

    public function testCaptureOrderAttributionTreatsNoneAndEmptyAsAbsent()
    {
        $wc = Mockery::mock('WooCommerce');
        WP_Mock::userFunction('WC', array('return' => $wc));

        // No session->set() expectation at all - it must never be called.
        $this->invokePrivate('capture_order_attribution', array(array(
            'wc_order_attribution_source_type' => '(none)',
            'wc_order_attribution_utm_source' => '',
        )));

        $this->assertTrue(true); // Reaching here without a Mockery exception is the assertion.
    }

    public function testCaptureOrderAttributionDoesNothingWhenNoFieldsPresent()
    {
        $wc = Mockery::mock('WooCommerce');
        WP_Mock::userFunction('WC', array('return' => $wc));

        $this->invokePrivate('capture_order_attribution', array(array(
            'billing_first_name' => 'Jane',
        )));

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // capture_order_attribution_from_blocks()
    // -----------------------------------------------------------------

    public function testCaptureOrderAttributionFromBlocksStashesUnprefixedFieldsInWcSession()
    {
        // Blocks checkout sends fields already unprefixed (read client-side
        // via wc_order_attribution.getAttributionData() / the checkout
        // store), unlike classic checkout's wc_order_attribution_* form fields.
        $captured = null;
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('set')->with('briqpay_order_attribution', Mockery::on(function ($value) use (&$captured) {
            $captured = $value;
            return true;
        }))->once();

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));

        $this->invokePrivate('capture_order_attribution_from_blocks', array(array(
            'source_type' => 'organic',
            'utm_source' => 'google',
        )));

        $this->assertEquals('organic', $captured['source_type']);
        $this->assertEquals('google', $captured['utm_source']);
    }

    public function testCaptureOrderAttributionFromBlocksTreatsNoneAndEmptyAsAbsent()
    {
        $wc = Mockery::mock('WooCommerce');
        WP_Mock::userFunction('WC', array('return' => $wc));

        // No session->set() expectation - it must never be called.
        $this->invokePrivate('capture_order_attribution_from_blocks', array(array(
            'source_type' => '(none)',
            'utm_source' => '',
        )));

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // apply_order_attribution_to_order()
    // -----------------------------------------------------------------

    public function testApplyOrderAttributionSetsMetaUsingWooCommerceNativeKeys()
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_order_attribution')->andReturn(array(
            'source_type' => 'referral',
            'utm_source' => 'partner-network',
        ));

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_wc_order_attribution_source_type')->andReturn('');
        $order->shouldReceive('update_meta_data')->with('_wc_order_attribution_source_type', 'referral')->once();
        $order->shouldReceive('update_meta_data')->with('_wc_order_attribution_utm_source', 'partner-network')->once();

        $this->invokePrivate('apply_order_attribution_to_order', array($order));
    }

    public function testApplyOrderAttributionDoesNotOverwriteExistingAttribution()
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_order_attribution')->andReturn(array(
            'source_type' => 'referral',
        ));

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_wc_order_attribution_source_type')->andReturn('admin');
        $order->shouldReceive('update_meta_data')->never();

        $this->invokePrivate('apply_order_attribution_to_order', array($order));
    }

    public function testApplyOrderAttributionDoesNothingWhenNothingWasCaptured()
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_order_attribution')->andReturn(null);

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->never();
        $order->shouldReceive('update_meta_data')->never();

        $this->invokePrivate('apply_order_attribution_to_order', array($order));
    }
}
