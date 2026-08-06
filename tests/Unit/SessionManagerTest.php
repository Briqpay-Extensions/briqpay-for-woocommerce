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
        // The reference is suffixed with the unit price in minor units so that the
        // same SKU at two different prices cannot be consolidated into one line.
        $this->assertEquals('SKU1-5000', $results[0]['reference']);
        $this->assertEquals(2, $results[0]['quantity']);
        $this->assertEquals(5000, $results[0]['unitPrice']); // (100 / 2) * 100
        $this->assertEquals(6250, $results[0]['unitPriceIncVat']); // (125 / 2) * 100
        $this->assertEquals(2500, $results[0]['taxRate']); // 25 / 100 * 10000
    }

    /**
     * Regression test: shipping's taxRate must reflect the store's actual
     * configured rate (25%), not a value derived by dividing tax/total. WC's own
     * shipping total (99.41) and tax (19.88) are themselves already rounded to 2
     * decimals, so dividing them back out (19.88 / 99.41 * 10000 = 1999.598...,
     * i.e. ship_tax / ship_total, previously truncated with (int)) reported
     * 24.99% instead of the real 25.00% - a cosmetic-looking but real
     * authorization-time bug: it also became the rate Briqpay validates future
     * captures/refunds against.
     */
    public function testGetCartItemsUsesNominalTaxRateForShipping()
    {
        $cart = Mockery::mock('WC_Cart');
        $wc = Mockery::mock('WooCommerce');
        $wc->cart = $cart;
        $wc->customer = Mockery::mock('WC_Customer');
        $wc->customer->shouldReceive('get_billing_country')->andReturn('SE');

        WP_Mock::userFunction('WC', array('return' => $wc));

        $cart->shouldReceive('get_cart')->andReturn(array());
        $cart->shouldReceive('get_shipping_total')->andReturn(79.53);
        $cart->shouldReceive('get_shipping_tax')->andReturn(19.88);
        // [tax_rate_id => amount] - the shape WC_Cart::get_shipping_taxes() returns.
        $cart->shouldReceive('get_shipping_taxes')->andReturn(array(1 => 19.88));
        $cart->shouldReceive('get_fees')->andReturn(array());
        $cart->shouldReceive('get_applied_coupons')->andReturn(array());

        // The store's actual configured tax rate for this rate ID is a clean 25%.
        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->with(1)->andReturn(25.00);

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $session_manager = new Session_Manager();
        $reflection = new \ReflectionClass(get_class($session_manager));
        $method = $reflection->getMethod('get_cart_items');
        $method->setAccessible(true);

        $results = $method->invoke($session_manager);

        $shipping = null;
        foreach ($results as $line) {
            if ('shipping' === $line['reference']) {
                $shipping = $line;
            }
        }
        $this->assertNotNull($shipping, 'Shipping line must be present');
        $this->assertEquals(2500, $shipping['taxRate'], 'taxRate must be the nominal 25.00%, not a value derived from dividing tax/total');

        // Amounts are unaffected by this fix - only the reported rate changes.
        $this->assertEquals(9941, $shipping['totalAmount']);
        $this->assertEquals(1988, $shipping['totalVatAmount']);
    }

    /**
     * Same bug, same fix, for cart fees.
     */
    public function testGetCartItemsUsesNominalTaxRateForFees()
    {
        $cart = Mockery::mock('WC_Cart');
        $wc = Mockery::mock('WooCommerce');
        $wc->cart = $cart;
        $wc->customer = Mockery::mock('WC_Customer');
        $wc->customer->shouldReceive('get_billing_country')->andReturn('SE');

        WP_Mock::userFunction('WC', array('return' => $wc));

        $fee = new \stdClass();
        $fee->id = 'handling-fee';
        $fee->name = 'Handling fee';
        $fee->total = 79.53;
        $fee->tax = 19.88;
        $fee->tax_data = array(1 => 19.88);

        $cart->shouldReceive('get_cart')->andReturn(array());
        $cart->shouldReceive('get_shipping_total')->andReturn(0.00);
        $cart->shouldReceive('get_fees')->andReturn(array($fee));
        $cart->shouldReceive('get_applied_coupons')->andReturn(array());

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->with(1)->andReturn(25.00);

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $session_manager = new Session_Manager();
        $reflection = new \ReflectionClass(get_class($session_manager));
        $method = $reflection->getMethod('get_cart_items');
        $method->setAccessible(true);

        $results = $method->invoke($session_manager);

        $fee_line = null;
        foreach ($results as $line) {
            if ('handling-fee' === $line['reference']) {
                $fee_line = $line;
            }
        }
        $this->assertNotNull($fee_line, 'Fee line must be present');
        $this->assertEquals(2500, $fee_line['taxRate'], 'taxRate must be the nominal 25.00%, not a value derived from dividing tax/total');
    }

    /**
     * Regression test: two cart lines for the SAME product at DIFFERENT unit prices
     * (add-ons, bundles, personalization, role-based pricing) must stay separate
     * lines. Consolidating them by SKU alone kept only the first line's unit price
     * and produced an invalid cart total towards Briqpay.
     */
    public function testGetCartItemsKeepsSameSkuAtDifferentPricesSeparate()
    {
        $cart = Mockery::mock('WC_Cart');
        $wc = Mockery::mock('WooCommerce');
        $wc->cart = $cart;
        $wc->customer = Mockery::mock('WC_Customer');
        $wc->customer->shouldReceive('get_billing_country')->andReturn('SE');

        WP_Mock::userFunction('WC', array('return' => $wc));

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rates')->andReturn(array(
            array('rate' => 25.0000)
        ));

        // Same SKU/product, two cart lines, two different unit prices.
        $product = $this->mockProduct('SKU1', 'Test Product', 1);
        $cart_items = array(
            'key1' => array(
                'quantity' => 1,
                'line_subtotal' => 100.00,
                'line_subtotal_tax' => 25.00,
                'data' => $product
            ),
            'key2' => array(
                'quantity' => 1,
                'line_subtotal' => 150.00,
                'line_subtotal_tax' => 37.50,
                'data' => $product
            ),
        );

        $cart->shouldReceive('get_cart')->andReturn($cart_items);
        $cart->shouldReceive('get_shipping_total')->andReturn(0.00);
        $cart->shouldReceive('get_fees')->andReturn(array());
        $cart->shouldReceive('get_applied_coupons')->andReturn(array());

        WP_Mock::userFunction('wp_get_attachment_image_url', array('return' => 'http://example.com/image.jpg'));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $session_manager = new Session_Manager();
        $reflection = new \ReflectionClass(get_class($session_manager));
        $method = $reflection->getMethod('get_cart_items');
        $method->setAccessible(true);

        $results = $method->invoke($session_manager);

        // Two distinct lines, each retaining its own unit price.
        $this->assertCount(2, $results);

        $by_reference = array();
        foreach ($results as $line) {
            $by_reference[$line['reference']] = $line;
        }

        $this->assertArrayHasKey('SKU1-10000', $by_reference);
        $this->assertArrayHasKey('SKU1-15000', $by_reference);

        $this->assertEquals(10000, $by_reference['SKU1-10000']['unitPrice']);
        $this->assertEquals(1, $by_reference['SKU1-10000']['quantity']);
        $this->assertEquals(12500, $by_reference['SKU1-10000']['totalAmount']);

        $this->assertEquals(15000, $by_reference['SKU1-15000']['unitPrice']);
        $this->assertEquals(1, $by_reference['SKU1-15000']['quantity']);
        $this->assertEquals(18750, $by_reference['SKU1-15000']['totalAmount']);

        // The summed cart total must reflect both prices, not double the first one.
        $this->assertEquals(31250, $by_reference['SKU1-10000']['totalAmount'] + $by_reference['SKU1-15000']['totalAmount']);
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

    /**
     * config.realTimeProcessing (top-level, distinct from modules.config)
     * delivers webhooks/status updates immediately instead of in batches.
     * Shared by Session_Manager and Hosted_Payment_Page, both of which only
     * ever call this at session creation time.
     */
    public function testGetRealtimeSessionConfigEnablesRealTimeProcessing()
    {
        $this->assertEquals(
            array('realTimeProcessing' => true),
            Session_Manager::get_realtime_session_config()
        );
    }
}
