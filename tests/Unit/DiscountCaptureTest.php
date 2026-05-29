<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class DiscountCaptureTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Mock Static WC_Tax for all tests
        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(25.00);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testDiscountsAreIncludedInRemainingItems()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_billing_country')->andReturn('SE');

        // Mock Item
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn(Mockery::mock('WC_Product', array(
            'get_sku' => 'PROD1',
            'get_id' => 101,
        )));
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Test Product');
        $item->shouldReceive('get_subtotal')->andReturn(100.00);
        $item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));

        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($item));

        // Mock Coupon
        $coupon = Mockery::mock('WC_Order_Item_Coupon');
        $coupon->shouldReceive('get_code')->andReturn('SAVE10');
        $coupon->shouldReceive('get_discount')->andReturn(10.00);
        $coupon->shouldReceive('get_discount_tax')->andReturn(2.50);

        $order->shouldReceive('get_items')->with('coupon')->andReturn(array($coupon));
        $order->shouldReceive('get_coupons')->andReturn(array($coupon));
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(0.00);
        $order->shouldReceive('get_fees')->andReturn(array());

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('order_management_enabled' => 'yes')
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_remaining_items_to_capture');
        $method->setAccessible(true);

        $results = $method->invoke($order_mgmt, $order);

        $discount_found = false;
        foreach ($results as $r) {
            if ($r['reference'] === 'discount_SAVE10') {
                $discount_found = true;
                $this->assertEquals('physical', $r['productType']);
                $this->assertEquals(-1000, $r['unitPrice']);
                $this->assertEquals(-1250, $r['unitPriceIncVat']);
                $this->assertEquals(2500, $r['taxRate']);
            }
        }

        $this->assertTrue($discount_found, 'Discount/Coupon should be found in remaining items');
    }
}
