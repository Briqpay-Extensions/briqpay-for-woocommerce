<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class ShippingCaptureTest extends TestCase
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

    public function testShippingIsIncludedInRemainingItems()
    {
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_billing_country')->andReturn('SE');

        // Products
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('PROD1');
        $product->shouldReceive('get_id')->andReturn(101);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Test Product');
        $item->shouldReceive('get_subtotal')->andReturn(100.00);
        $item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));

        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_coupons')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());

        // Shipping
        $order->shouldReceive('get_shipping_total')->andReturn(50.00);
        $order->shouldReceive('get_shipping_tax')->andReturn(12.50);

        $shipping_item = Mockery::mock('WC_Order_Item_Shipping');
        $shipping_item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 12.50)));
        $order->shouldReceive('get_fees')->andReturn(array());
        $order->shouldReceive('get_shipping_methods')->andReturn(array($shipping_item));

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

        $shipping_found = false;
        foreach ($results as $r) {
            if ($r['reference'] === 'shipping') {
                $shipping_found = true;
                $this->assertEquals('shipping_fee', $r['productType']);
                $this->assertEquals(5000, $r['unitPrice']);
                $this->assertEquals(6250, $r['unitPriceIncVat']);
                $this->assertEquals(6250, $r['totalAmount']);
            }
        }

        $this->assertTrue($shipping_found, 'Shipping should be found in remaining items');
    }
}
