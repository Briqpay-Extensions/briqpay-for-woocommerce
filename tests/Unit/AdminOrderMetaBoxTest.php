<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Admin_Order_Meta_Box;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class AdminOrderMetaBoxTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(25.00);

        WP_Mock::userFunction('esc_html__', array('return_arg' => 0));
        WP_Mock::userFunction('esc_html_e', array(
            'return' => function ($text) {
                echo $text;
            },
        ));
        WP_Mock::userFunction('esc_html', array('return_arg' => 0));
        WP_Mock::userFunction('esc_attr', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * _briqpay_item_reference / _briqpay_fee_reference are internal
     * bookkeeping used to pin captures/refunds to a stable reference. They
     * must not show up in the order items table as if they were
     * customer-facing product meta.
     */
    public function testHideInternalItemMetaAddsBriqpayReferenceKeys()
    {
        $box = new Admin_Order_Meta_Box();
        $hidden = $box->hide_internal_item_meta(array('_qty', '_tax_class'));

        $this->assertContains('_briqpay_item_reference', $hidden);
        $this->assertContains('_briqpay_fee_reference', $hidden);

        // Existing hidden keys must be preserved, not replaced.
        $this->assertContains('_qty', $hidden);
        $this->assertContains('_tax_class', $hidden);
    }

    /**
     * Build an order with exactly one remaining (uncaptured) item, so
     * render_capture_form() gets past the "Fully captured" early return.
     */
    private function makeOrderWithOneRemainingItem($auto_capture_meta = '')
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU1');
        $product->shouldReceive('get_id')->andReturn(1);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_meta')->with('_briqpay_item_reference')->andReturn('');
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Test product');
        $item->shouldReceive('get_subtotal')->andReturn(100.0);
        $item->shouldReceive('get_subtotal_tax')->andReturn(25.0);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.0)));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_auto_capture_enabled')->andReturn($auto_capture_meta);
        $order->shouldReceive('get_items')->andReturn(array($item));
        $order->shouldReceive('get_fees')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(0.0);
        $order->shouldReceive('get_coupons')->andReturn(array());

        return $order;
    }

    /**
     * get_option() is hard-defined once by tests/bootstrap.php with a fixed
     * settings array that never includes 'order_management_enabled', and
     * WP_Mock::userFunction() cannot override an already-real function - so
     * we go through the protected get_settings() seam instead, exactly like
     * Order_Management/Hosted_Payment_Page do for the same reason.
     */
    private function renderCaptureForm($order, array $settings = array('order_management_enabled' => 'yes'))
    {
        $box = Mockery::mock(Admin_Order_Meta_Box::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $box->shouldReceive('get_settings')->andReturn($settings);

        $reflection = new \ReflectionClass(Admin_Order_Meta_Box::class);
        $method = $reflection->getMethod('render_capture_form');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($box, $order);
        return ob_get_clean();
    }

    public function testRenderCaptureFormShowsAutoCaptureInProgressWhenEnabled()
    {
        $order = $this->makeOrderWithOneRemainingItem('yes');
        $output = $this->renderCaptureForm($order);

        $this->assertStringContainsString('Auto capture in progress.', $output);
        $this->assertStringNotContainsString('Manual Capture', $output);
    }

    public function testRenderCaptureFormShowsManualCaptureButtonWhenAutoCaptureDisabled()
    {
        $order = $this->makeOrderWithOneRemainingItem('no');
        $output = $this->renderCaptureForm($order);

        $this->assertStringContainsString('Manual Capture', $output);
        $this->assertStringNotContainsString('Auto capture in progress.', $output);
    }
}
