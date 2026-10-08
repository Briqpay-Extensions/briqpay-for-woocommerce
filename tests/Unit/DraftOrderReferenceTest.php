<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * A WooCommerce Blocks checkout creates a draft order before the shopper
 * reaches the Briqpay iframe. The plugin reuses that draft, but its items were
 * built by WooCommerce and carried no _briqpay_item_reference, so a later
 * capture fell back to the bare SKU while the session had been created with
 * "SKU-unitprice" - Briqpay refused the capture with CART_ITEM_NOT_FOUND.
 */
class DraftOrderReferenceTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function cartItem($sku, $id, $line_subtotal, $quantity)
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_id')->andReturn($id);

        return array('data' => $product, 'line_subtotal' => $line_subtotal, 'quantity' => $quantity);
    }

    public function testReferenceIsSkuAndUnitPriceInMinorUnits(): void
    {
        $this->assertSame('QA-REDUCED-9000', Checkout_Handler::cart_item_reference($this->cartItem('QA-REDUCED', 416, 90.0, 1)));
        $this->assertSame('159-20000', Checkout_Handler::cart_item_reference($this->cartItem('', 159, 400.0, 2)));
        // Ex-VAT unit prices repeat (84.905660…): rounded once, as the session does.
        $this->assertSame('QA-REDUCED-8491', Checkout_Handler::cart_item_reference($this->cartItem('QA-REDUCED', 416, 84.90566, 1)));
        $this->assertSame('159-0', Checkout_Handler::cart_item_reference($this->cartItem('', 159, 0.0, 0)));
    }

    private function methodSource($class, $name)
    {
        $method = new \ReflectionMethod($class, $name);
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    public function testReusedDraftItemsAreStampedWithTheSameReferenceAsNewItems(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $match_branch = substr($source, 0, strpos($source, 'Order items match cart exactly'));
        $this->assertStringContainsString(
            "add_meta_data('_briqpay_item_reference', self::cart_item_reference(\$cart_item), true)",
            $match_branch,
            'A reused Blocks draft item must get the Briqpay reference the session was created with.'
        );

        $new_item_loop = substr($source, strpos($source, 'Adding cart items to order'));
        $this->assertStringContainsString(
            "add_meta_data('_briqpay_item_reference', self::cart_item_reference(\$values))",
            $new_item_loop,
            'New items must derive the reference through the same helper, so the two paths cannot drift apart.'
        );
    }
}
