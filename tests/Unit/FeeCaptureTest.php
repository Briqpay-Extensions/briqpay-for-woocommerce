<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class FeeCaptureTest extends TestCase
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

    public function testFeesAreIncludedInRemainingItems()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_billing_country')->andReturn('SE');

        // Mock Fee
        $fee = Mockery::mock('WC_Order_Item_Fee');
        $fee->shouldReceive('get_id')->andReturn(501);
        $fee->shouldReceive('get_name')->andReturn('Special Fee');
        $fee->shouldReceive('get_total')->andReturn(100.00);
        $fee->shouldReceive('get_total_tax')->andReturn(25.00);
        $fee->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 25.00)));

        $order->shouldReceive('get_fees')->andReturn(array($fee));
        $order->shouldReceive('get_items')->with()->andReturn(array());
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
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

        $fee_found = false;
        foreach ($results as $r) {
            if ($r['reference'] == 501) {
                $fee_found = true;
                $this->assertEquals('physical', $r['productType']);
                $this->assertEquals('Special Fee', $r['name']);
                $this->assertEquals(10000, $r['unitPrice']);
                $this->assertEquals(12500, $r['unitPriceIncVat']);
                $this->assertEquals(2500, $r['taxRate']);
            }
        }

        $this->assertTrue($fee_found, 'Fee should be found in remaining items');
    }
}
