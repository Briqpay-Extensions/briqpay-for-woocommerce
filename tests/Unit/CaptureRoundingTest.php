<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Manual capture amounts, and the rounding that used to skew them.
 *
 * The admin capture box posted a unitPriceIncVat per row and the server took it
 * at face value, computing the line total as (rounded unit price) x quantity.
 * Rounding before multiplying is not the same as rounding the line total once,
 * so any line whose per-unit price does not land on a whole minor unit was
 * captured adrift from what the order authorized - in either direction, by as
 * much as three minor units at quantity six. Automatic capture never had the
 * bug, since it multiplies the full-precision unit price and rounds once, so the
 * two paths could disagree about the same order.
 *
 * Quantity-1 lines - shipping, fees, coupons - were never affected: there is
 * nothing to multiply.
 */
class CaptureRoundingTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(25.5);

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Real lines that the old arithmetic got wrong, in both directions.
     *
     * The first is the case found on the playground shop. The second drifts the
     * other way, which is the dangerous one - it captures more than the customer
     * authorized.
     *
     * @return array [line ex VAT, stored line tax, quantity, authorized, old]
     */
    public function driftCases(): array
    {
        return array(
            'under-captured: 2 x 19,15 at 25.5%' => array(38.30, 9.77, 2, 4807, 4806),
            'over-captured: 2 x 5,01 at 25.5%' => array(5.01, 1.28, 2, 629, 630),
            // Taken from a real classic-checkout purchase on the playground shop.
            // Briqpay held unitPriceIncVat 2403 against totalAmount 9613, so the
            // old arithmetic would have captured 2403 x 4 = 9612.
            'order 27: 4 x 19,15 at 25.5%' => array(76.60, 19.53, 4, 9613, 9612),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * An order holding a single product line.
     *
     * @param float $line_ex         Line total excluding VAT.
     * @param float $line_tax        Line tax as WooCommerce stores it.
     * @param int   $qty             Line quantity.
     * @param array $capture_history Prior captures, as stored in order meta.
     */
    private function order($line_ex, $line_tax, $qty, array $capture_history = array())
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('SKU1');
        $product->shouldReceive('get_id')->andReturn(14);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_meta')->andReturn('');
        $item->shouldReceive('get_name')->andReturn('Test product 1');
        $item->shouldReceive('get_quantity')->andReturn($qty);
        $item->shouldReceive('get_subtotal')->andReturn($line_ex);
        $item->shouldReceive('get_subtotal_tax')->andReturn($line_tax);
        $item->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => $line_tax)));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_billing_country')->andReturn('FI');
        $order->shouldReceive('get_items')->with()->andReturn(array($item));
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $order->shouldReceive('get_fees')->andReturn(array());
        $order->shouldReceive('get_shipping_total')->andReturn(0.00);
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn($capture_history);

        return $order;
    }

    /** The shop's line: 2 x 19,15 EUR at Finnish 25.5% VAT, authorizing 4807. */
    private function shopOrder(array $capture_history = array())
    {
        return $this->order(38.30, 9.77, 2, $capture_history);
    }

    /**
     * Invoke the capture payload builder.
     *
     * @param mixed $order    Mock order.
     * @param array $selected Items as the admin form posts them.
     * @return array Detailed capture items.
     */
    private function build($order, array $selected)
    {
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('build_manual_capture_items');
        $method->setAccessible(true);

        return $method->invoke(new Order_Management(), $order, $selected);
    }

    /**
     * A posted row, carrying the amounts the browser used to be trusted for.
     *
     * @param int $qty            Quantity requested.
     * @param int $unit_price_inc Amount the form claims, which must now be ignored.
     */
    private function posted($qty, $unit_price_inc = 2403)
    {
        return array(
            'reference' => 'SKU1',
            'name' => 'Test product 1',
            'quantity' => $qty,
            'unitPriceIncVat' => $unit_price_inc,
            'taxRate' => 2550,
            'productType' => 'physical',
        );
    }

    private function allocate($line_total, $total_qty, $already_qty, $take_qty)
    {
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('allocate_minor');
        $method->setAccessible(true);

        return $method->invoke(null, $line_total, $total_qty, $already_qty, $take_qty);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The bug
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider driftCases
     * @param float $line_ex    Line total excluding VAT.
     * @param float $line_tax   Stored line tax.
     * @param int   $qty        Line quantity.
     * @param int   $authorized What the order authorizes, in minor units.
     * @param int   $old        What round-then-multiply sent instead.
     */
    public function testFullCaptureMatchesWhatTheOrderAuthorized($line_ex, $line_tax, $qty, $authorized, $old): void
    {
        $items = $this->build($this->order($line_ex, $line_tax, $qty), array($this->posted($qty)));

        $this->assertCount(1, $items);
        $this->assertSame($qty, $items[0]['quantity']);
        $this->assertSame(
            $authorized,
            $items[0]['totalAmount'],
            'Capturing the whole line must send exactly what the order authorized.'
        );
        $this->assertNotSame(
            $old,
            $items[0]['totalAmount'],
            'Rounding the unit price before multiplying drifts off the order total.'
        );
    }

    /**
     * @dataProvider driftCases
     * @param float $line_ex  Line total excluding VAT.
     * @param float $line_tax Stored line tax.
     * @param int   $qty      Line quantity.
     */
    public function testBriqpayLineInvariantHoldsExactly($line_ex, $line_tax, $qty): void
    {
        $items = $this->build($this->order($line_ex, $line_tax, $qty), array($this->posted($qty)));
        $item = $items[0];

        // Briqpay validates unitPrice x quantity == totalAmount - totalVatAmount.
        $this->assertSame(
            $item['unitPrice'] * $item['quantity'],
            $item['totalAmount'] - $item['totalVatAmount'],
            'The ex-VAT side of the line must reconcile exactly.'
        );
    }

    public function testShopCaseSendsTheExpectedBreakdown(): void
    {
        $item = $this->build($this->shopOrder(), array($this->posted(2)))[0];

        $this->assertSame(4807, $item['totalAmount']);
        $this->assertSame(1915, $item['unitPrice']);
        $this->assertSame(977, $item['totalVatAmount']);
        $this->assertSame(2550, $item['taxRate']);
        $this->assertSame('SKU1', $item['reference']);
    }

    public function testPartialCapturesSumToTheOrderTotal(): void
    {
        $first = $this->build($this->shopOrder(), array($this->posted(1)));

        $history = array(
            array('items' => array(array('reference' => 'SKU1', 'quantity' => 1))),
        );
        $second = $this->build($this->shopOrder($history), array($this->posted(1)));

        $this->assertSame(
            4807,
            $first[0]['totalAmount'] + $second[0]['totalAmount'],
            'Two half captures must add back up to the order total, not drift.'
        );

        foreach (array($first[0], $second[0]) as $item) {
            $this->assertSame(
                $item['unitPrice'] * $item['quantity'],
                $item['totalAmount'] - $item['totalVatAmount'],
                'Each partial capture must satisfy the invariant on its own.'
            );
        }
    }

    public function testAllocationTelescopesSoNothingIsLostOrCreated(): void
    {
        // 10,00 over three units divides into 333 / 334 / 333, never 333 x 3.
        $slices = array(
            $this->allocate(10.00, 3, 0, 1),
            $this->allocate(10.00, 3, 1, 1),
            $this->allocate(10.00, 3, 2, 1),
        );

        $this->assertSame(1000, array_sum($slices));
        $this->assertSame(array(333, 334, 333), $slices);
    }

    public function testAllocatingTheWholeLineAtOnceEqualsTheLineTotal(): void
    {
        $this->assertSame(1000, $this->allocate(10.00, 3, 0, 3));
        $this->assertSame(4807, $this->allocate(48.07, 2, 0, 2));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The request is no longer trusted for money
    // ──────────────────────────────────────────────────────────────────────

    public function testPostedAmountsAreIgnored(): void
    {
        $items = $this->build($this->shopOrder(), array($this->posted(2, 999999)));

        $this->assertSame(
            4807,
            $items[0]['totalAmount'],
            'The captured amount must come from the order, never from the request.'
        );
    }

    public function testQuantityIsClampedToWhatIsStillOutstanding(): void
    {
        $items = $this->build($this->shopOrder(), array($this->posted(5)));

        $this->assertSame(2, $items[0]['quantity'], 'Cannot capture more units than the order holds.');
        $this->assertSame(4807, $items[0]['totalAmount']);
    }

    public function testAlreadyCapturedQuantityCannotBeCapturedAgain(): void
    {
        $history = array(
            array('items' => array(array('reference' => 'SKU1', 'quantity' => 2))),
        );

        $items = $this->build($this->shopOrder($history), array($this->posted(2)));

        $this->assertSame(array(), $items, 'A fully captured line has nothing left to capture.');
    }

    public function testUnknownReferenceIsRefused(): void
    {
        $rogue = $this->posted(1);
        $rogue['reference'] = 'NOT-ON-THIS-ORDER';

        $items = $this->build($this->shopOrder(), array($rogue));

        $this->assertSame(array(), $items, 'A reference with no order line must not be captured.');
    }

    public function testZeroQuantityRowsAreSkipped(): void
    {
        $this->assertSame(array(), $this->build($this->shopOrder(), array($this->posted(0))));
    }
}
