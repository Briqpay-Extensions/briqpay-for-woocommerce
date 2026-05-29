<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class OrderManagementTest extends TestCase
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

    public function testGetRemainingItemsToCapture()
    {
        $order = Mockery::mock('WC_Order');

        // Mock items
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU1');
        $product->shouldReceive('get_id')->andReturn(123);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(2);
        $item->shouldReceive('get_name')->andReturn('Test Product');
        $item->shouldReceive('get_subtotal')->andReturn(100.00);
        $item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));
        $item->shouldReceive('get_product_id')->andReturn(123);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_code')->andReturn('shipping');

        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_coupons')->andReturn(array());
        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_fees')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(0.00);

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('order_management_enabled' => 'yes')
        ));

        WP_Mock::userFunction('__', array('return_arg' => 0));

        $order_mgmt = new Order_Management();

        // Call private method
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_remaining_items_to_capture');
        $method->setAccessible(true);

        $results = $method->invoke($order_mgmt, $order);

        $this->assertCount(1, $results);
        $this->assertEquals('SKU1-123', $results[0]['reference']);
        $this->assertEquals(5000, $results[0]['unitPrice']); // (100 / 2) * 100
        $this->assertEquals(6250, $results[0]['unitPriceIncVat']); // (125 / 2) * 100
        $this->assertEquals(2500, $results[0]['taxRate']);
    }

    public function testGetRefundItems()
    {
        $order = Mockery::mock('WC_Order');

        // Mock Parent Item
        $parent_item = Mockery::mock('WC_Order_Item_Product');
        $parent_item->shouldReceive('get_subtotal')->andReturn(100.00);
        $parent_item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $parent_item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));
        $parent_item->shouldReceive('get_product_id')->andReturn(123);
        $parent_item->shouldReceive('get_variation_id')->andReturn(0);
        $parent_item->shouldReceive('get_quantity')->andReturn(1);

        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_items')->with()->andReturn(array($parent_item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($parent_item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_item')->with(12345)->andReturn($parent_item);
        $order->shouldReceive('get_fees')->andReturn(array());

        // Mock Refund
        $refund = Mockery::mock('WC_Order_Refund');
        $refund_item = Mockery::mock('WC_Order_Item_Product');
        $refund_item->shouldReceive('get_meta')->with('_parent_line_item_id')->andReturn(12345);
        $refund_item->shouldReceive('get_product')->andReturn($this->mockProduct('SKU1', 'Test', 123));
        $refund_item->shouldReceive('get_product_id')->andReturn(123);
        $refund_item->shouldReceive('get_variation_id')->andReturn(0);
        $refund_item->shouldReceive('get_quantity')->andReturn(-1);
        $refund_item->shouldReceive('get_name')->andReturn('Test Product');
        $refund_item->shouldReceive('get_total')->andReturn(-50.00);
        $refund_item->shouldReceive('get_total_tax')->andReturn(-12.50);
        $refund_item->shouldReceive('get_subtotal')->andReturn(-50.00);
        $refund_item->shouldReceive('get_subtotal_tax')->andReturn(-12.50);
        $refund_item->shouldReceive('get_code')->andReturn('sku1');
        $refund_item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 12.50)));

        $refund->shouldReceive('get_items')->with()->andReturn(array($refund_item));
        $refund->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $refund->shouldReceive('get_shipping_methods')->andReturn(array());

        $order->shouldReceive('get_refunds')->andReturn(array($refund));

        $order_mgmt = new Order_Management();

        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_refund_items');
        $method->setAccessible(true);

        $results = $method->invoke($order_mgmt, $order);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['quantity']);
        $this->assertEquals(10000, $results[0]['unitPrice']); // From parent_item: 100 * 100
        $this->assertEquals(12500, $results[0]['unitPriceIncVat']); // From parent_item: 125 * 100
    }

    public function testCaptureRemainingItemsShippingProductType()
    {
        $order = Mockery::mock('WC_Order');

        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU1');
        $product->shouldReceive('get_id')->andReturn(123);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Test Product');
        $item->shouldReceive('get_subtotal')->andReturn(100.00);
        $item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));

        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_coupons')->andReturn(array());
        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(99.99);
        $order->shouldReceive('get_shipping_tax')->andReturn(25.00);
        $order->shouldReceive('get_fees')->andReturn(array());

        // Mock shipping tax rate via get_shipping_methods()
        $shipping_item = Mockery::mock('WC_Order_Item_Shipping');
        $shipping_item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));
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

        // Find the shipping item
        $shipping = null;
        foreach ($results as $r) {
            if ($r['reference'] === 'shipping') {
                $shipping = $r;
                break;
            }
        }

        $this->assertNotNull($shipping, 'Shipping item should be present in capture items');
        $this->assertEquals('shipping_fee', $shipping['productType'], 'Shipping productType must be shipping_fee, not physical');
        $this->assertEquals(12499, $shipping['totalAmount']);
    }

    public function testRefundItemsShippingProductType()
    {
        $order = Mockery::mock('WC_Order');

        $parent_item = Mockery::mock('WC_Order_Item_Product');
        $parent_item->shouldReceive('get_subtotal')->andReturn(100.00);
        $parent_item->shouldReceive('get_subtotal_tax')->andReturn(25.00);
        $parent_item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));
        $parent_item->shouldReceive('get_product_id')->andReturn(123);
        $parent_item->shouldReceive('get_variation_id')->andReturn(0);
        $parent_item->shouldReceive('get_quantity')->andReturn(1);

        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_items')->with()->andReturn(array($parent_item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($parent_item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_fees')->andReturn(array());

        // Mock Refund with shipping
        $refund = Mockery::mock('WC_Order_Refund');
        $refund_item = Mockery::mock('WC_Order_Item_Product');
        $refund_item->shouldReceive('get_product')->andReturn($this->mockProduct('SKU1', 'Test', 123));
        $refund_item->shouldReceive('get_product_id')->andReturn(123);
        $refund_item->shouldReceive('get_variation_id')->andReturn(0);
        $refund_item->shouldReceive('get_quantity')->andReturn(0);
        $refund_item->shouldReceive('get_name')->andReturn('Test');
        $refund_item->shouldReceive('get_total')->andReturn(0.00);
        $refund_item->shouldReceive('get_total_tax')->andReturn(0.00);
        $refund_item->shouldReceive('get_subtotal')->andReturn(0.00);
        $refund_item->shouldReceive('get_subtotal_tax')->andReturn(0.00);
        $refund_item->shouldReceive('get_taxes')->andReturn(array('total' => array()));
        $refund_item->shouldReceive('get_code')->andReturn('sku1');

        $refund->shouldReceive('get_items')->with()->andReturn(array($refund_item));
        $refund->shouldReceive('get_items')->with('coupon')->andReturn(array());

        $refund_shipping = Mockery::mock('WC_Order_Item_Shipping');
        $refund_shipping->shouldReceive('get_total')->andReturn(-99.99);
        $refund_shipping->shouldReceive('get_total_tax')->andReturn(-25.00);
        $refund->shouldReceive('get_shipping_methods')->andReturn(array($refund_shipping));

        $order->shouldReceive('get_refunds')->andReturn(array($refund));

        // Mock shipping tax rate via get_shipping_methods()
        $order_shipping = Mockery::mock('WC_Order_Item_Shipping');
        $order_shipping->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));
        $order->shouldReceive('get_shipping_methods')->andReturn(array($order_shipping));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('order_management_enabled' => 'yes')
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_refund_items');
        $method->setAccessible(true);

        $results = $method->invoke($order_mgmt, $order);

        $shipping = null;
        foreach ($results as $r) {
            if ($r['reference'] === 'shipping') {
                $shipping = $r;
                break;
            }
        }

        $this->assertNotNull($shipping, 'Shipping item should be present in refund items');
        $this->assertEquals('shipping_fee', $shipping['productType'], 'Refund shipping productType must be shipping_fee');
        $this->assertEquals(12499, $shipping['totalAmount']);
    }

    public function testProductItemsUsePhysicalProductType()
    {
        $order = Mockery::mock('WC_Order');

        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('PROD1');
        $product->shouldReceive('get_id')->andReturn(456);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Physical Product');
        $item->shouldReceive('get_subtotal')->andReturn(200.00);
        $item->shouldReceive('get_subtotal_tax')->andReturn(50.00);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 50.00)));

        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_coupons')->andReturn(array());
        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('line_item')->andReturn(array($item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_fees')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(0.00);

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

        $this->assertCount(1, $results);
        $this->assertEquals('physical', $results[0]['productType'], 'Regular products must use physical productType');
    }

    private function mockProduct($sku, $name, $id)
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_name')->andReturn($name);
        $product->shouldReceive('get_id')->andReturn($id);
        $product->shouldReceive('get_image_id')->andReturn(10);
        $product->shouldReceive('get_tax_class')->andReturn('');
        return $product;
    }
}
