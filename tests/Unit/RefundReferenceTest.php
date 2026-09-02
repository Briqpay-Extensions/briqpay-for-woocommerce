<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Reference derivation for refund line items.
 *
 * Since 1.1.8 a product line's Briqpay reference is "<sku|id>-<unit price>", so
 * that the same product at two different prices in one cart stays two lines. The
 * session, the order item meta and the capture history all use that form.
 *
 * A refund line item is a fresh object WooCommerce creates when the refund is
 * made, and it does not carry _briqpay_item_reference - that lives on the parent
 * order item. Reading the meta straight off the refund line therefore always
 * missed, and the SKU/ID fallback rebuilt the pre-1.1.8 reference without the
 * suffix. On order 27 a refund went out as "14" where everything else said
 * "14-1915", which broke it twice: the per-capture balance lookup found no
 * quantity for the reference, and Briqpay rejected the payload with
 * CART_ITEM_NOT_FOUND.
 *
 * Capture was never affected - it iterates the order's own items, which do carry
 * the meta.
 */
class RefundReferenceTest extends TestCase
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

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A refund line item.
     *
     * @param string|null $own_ref     _briqpay_item_reference on the refund line.
     * @param int         $parent_id   _refunded_item_id, 0 for none.
     * @param mixed       $product     Product mock, or null.
     */
    private function refundItem($own_ref, $parent_id, $product = null)
    {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta')->with('_briqpay_item_reference')->andReturn($own_ref);
        $item->shouldReceive('get_meta')->with('_refunded_item_id')->andReturn($parent_id);
        $item->shouldReceive('get_product')->andReturn($product);

        return $item;
    }

    /**
     * The parent order item the refund line points back at.
     *
     * @param string|null $ref _briqpay_item_reference on the parent.
     */
    private function parentItem($ref)
    {
        $parent = Mockery::mock('WC_Order_Item_Product');
        $parent->shouldReceive('get_meta')->with('_briqpay_item_reference')->andReturn($ref);

        return $parent;
    }

    private function product($sku, $id)
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_id')->andReturn($id);

        return $product;
    }

    /**
     * @param array $items Map of parent item id => parent item mock.
     */
    private function order(array $items = array())
    {
        $order = Mockery::mock('WC_Order');
        foreach ($items as $id => $item) {
            $order->shouldReceive('get_item')->with($id)->andReturn($item);
        }
        $order->shouldReceive('get_item')->andReturn(false);

        return $order;
    }

    private function resolve($order, $item)
    {
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_refund_item_reference');
        $method->setAccessible(true);

        return $method->invoke(new Order_Management(), $order, $item);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The bug
    // ──────────────────────────────────────────────────────────────────────

    public function testResolvesThroughTheParentOrderItem(): void
    {
        $order = $this->order(array(42 => $this->parentItem('14-1915')));
        $item = $this->refundItem('', 42, $this->product('', 14));

        $this->assertSame(
            '14-1915',
            $this->resolve($order, $item),
            'The refund must use the same reference the session and capture history use.'
        );
    }

    public function testDoesNotFallBackToTheBareProductId(): void
    {
        // Exactly the shape that produced CART_ITEM_NOT_FOUND on order 27.
        $order = $this->order(array(42 => $this->parentItem('14-1915')));
        $item = $this->refundItem('', 42, $this->product('', 14));

        $this->assertNotSame(
            '14',
            $this->resolve($order, $item),
            'A bare product id is the pre-1.1.8 reference and Briqpay rejects it.'
        );
    }

    public function testAReferenceOnTheRefundLineItselfWins(): void
    {
        // Nothing writes this today, but if it is ever present it is the most
        // specific answer available and must not be overridden by the parent.
        $order = $this->order(array(42 => $this->parentItem('14-9999')));
        $item = $this->refundItem('14-1915', 42, $this->product('', 14));

        $this->assertSame('14-1915', $this->resolve($order, $item));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Legacy orders, where the bare reference is the correct one
    // ──────────────────────────────────────────────────────────────────────

    public function testFallsBackToSkuWhenTheParentHasNoReference(): void
    {
        // An order placed before the suffix existed: the parent carries no meta,
        // so the bare SKU is right - and is what capture derives for it too.
        $order = $this->order(array(42 => $this->parentItem('')));
        $item = $this->refundItem('', 42, $this->product('SKU1', 14));

        $this->assertSame('SKU1', $this->resolve($order, $item));
    }

    public function testFallsBackToProductIdWhenThereIsNoSku(): void
    {
        $order = $this->order(array(42 => $this->parentItem('')));
        $item = $this->refundItem('', 42, $this->product('', 14));

        $this->assertSame('14', $this->resolve($order, $item));
    }

    public function testFallsBackWhenTheParentItemIsGone(): void
    {
        // Parent id points at an item the order no longer has.
        $order = $this->order();
        $item = $this->refundItem('', 999, $this->product('SKU1', 14));

        $this->assertSame('SKU1', $this->resolve($order, $item));
    }

    public function testFallsBackWhenThereIsNoParentIdAtAll(): void
    {
        $order = $this->order();
        $item = $this->refundItem('', 0, $this->product('SKU1', 14));

        $this->assertSame('SKU1', $this->resolve($order, $item));
    }

    public function testReturnsEmptyWhenNothingCanBeResolved(): void
    {
        // No own meta, no parent, no product - deleted product on a legacy order.
        $order = $this->order();
        $item = $this->refundItem('', 0, null);

        $this->assertSame('', $this->resolve($order, $item));
    }
}
