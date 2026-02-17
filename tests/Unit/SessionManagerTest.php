<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Session_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class SessionManagerTest extends TestCase
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
        parent::tearDown();
    }

    public function testGetCartItems()
    {
        // Mock WC() global
        $cart = Mockery::mock('WC_Cart');
        $wc = Mockery::mock('WooCommerce');
        $wc->cart = $cart;
        $wc->customer = Mockery::mock('WC_Customer');
        $wc->customer->shouldReceive('get_billing_country')->andReturn('SE');

        WP_Mock::userFunction('WC', array(
            'return' => $wc
        ));

        // Mock Static WC_Tax::get_rates
        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rates')->andReturn(array(
            array('rate' => 25.0000)
        ));

        // Mock Products
        $product1 = $this->mockProduct('SKU1', 'Test Product', 1);
        $cart_items = array(
            'key1' => array(
                'quantity' => 2,
                'line_subtotal' => 100.00,
                'line_subtotal_tax' => 25.00,
                'data' => $product1
            )
        );

        $cart->shouldReceive('get_cart')->andReturn($cart_items);
        $cart->shouldReceive('get_shipping_total')->andReturn(0.00);
        $cart->shouldReceive('get_fees')->andReturn(array());
        $cart->shouldReceive('get_applied_coupons')->andReturn(array());

        // Mock WordPress functions
        WP_Mock::userFunction('wp_get_attachment_image_url', array('return' => 'http://example.com/image.jpg'));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $session_manager = new Session_Manager();

        // Use reflection to call private method
        $reflection = new \ReflectionClass(get_class($session_manager));
        $method = $reflection->getMethod('get_cart_items');
        $method->setAccessible(true);

        $results = $method->invoke($session_manager);

        $this->assertCount(1, $results);
        $this->assertEquals('SKU1', $results[0]['reference']);
        $this->assertEquals(2, $results[0]['quantity']);
        $this->assertEquals(5000, $results[0]['unitPrice']); // (100 / 2) * 100
        $this->assertEquals(6250, $results[0]['unitPriceIncVat']); // (125 / 2) * 100
        $this->assertEquals(2500, $results[0]['taxRate']); // 25 / 100 * 10000
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
