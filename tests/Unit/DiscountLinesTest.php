<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Discount_Lines;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Coupon lines carry the rate of what they discount.
 *
 * Briqpay validates every line on its own - taxRate × unitPrice × quantity must
 * equal totalVatAmount - and refused a whole session over a coupon stamped with
 * the first cart line's rate:
 *
 *   Cart item (name: Rabattkod: premiumkund övrigt, ...) -
 *   taxRate * unitPrice * quantity != totalVatAmount
 *
 * The merchant's cart: 25 swabs at 20 kr (25%) and nutrition products at 6%
 * totalling 1 800 kr. Coupon "förbrukning" takes 30% of the swabs (−150 kr,
 * VAT 37.50 at 25%) and happened to pass because the swabs come first; coupon
 * "övrigt" takes 10% of the nutrition (−180 kr, VAT 10.80 at 6%) and was sent as
 * 25% with 6% VAT. WooCommerce's own per-item, per-rate arithmetic is right; the
 * plugin's single-rate label was the bug.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class DiscountLinesTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('wc_tax_enabled', array('return' => true));
        // WooCommerce keeps discount shares in "precision" integers (cents for a
        // two-decimal currency); this is the inverse.
        WP_Mock::userFunction('wc_remove_number_precision', array('return' => function ($v) {
            return $v / 100;
        }));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fixtures
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> A cart item as WC()->cart->get_cart() yields it. */
    private function cartItem($name, $rate_percent, $line_subtotal, $line_total, $taxable = true)
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('is_taxable')->andReturn($taxable);
        $product->shouldReceive('get_tax_class')->andReturn($rate_percent == 6 ? 'reduced-rate' : '');
        $product->shouldReceive('get_name')->andReturn($name);

        $rate = $rate_percent / 100;
        return array(
            'data' => $product,
            'quantity' => 1,
            'line_subtotal' => $line_subtotal,
            'line_total' => $line_total,
            'line_subtotal_tax' => round($line_subtotal * $rate, 2),
            'line_tax' => round($line_total * $rate, 2),
            'line_tax_data' => array('subtotal' => array($rate_percent => round($line_subtotal * $rate, 2))),
            '_rate_bp' => (int) ($rate_percent * 100), // what the resolver returns
        );
    }

    /**
     * The resolver the session manager passes in: the rate actually applied to
     * the line. Here it is pinned on the fixture.
     */
    private function rateResolver()
    {
        return function ($cart_item, $product) {
            return $cart_item['_rate_bp'];
        };
    }

    /**
     * A cart with the merchant's two coupons, and a WC_Discounts replay that
     * attributes each coupon's share per item exactly as WooCommerce did.
     *
     * @param bool  $prices_include_tax
     * @param array $recorded_override  Lets a test make the recorded totals disagree with the replay.
     */
    private function merchantCart($prices_include_tax = false, array $recorded_override = array())
    {
        WP_Mock::userFunction('wc_prices_include_tax', array('return' => $prices_include_tax));

        $items = array(
            'swabs' => $this->cartItem('Minisvabbgarn Anna', 25, 500.00, 350.00),
            'nutri1' => $this->cartItem('Nutridrink Jucy Äpple', 6, 1000.00, 900.00),
            'nutri2' => $this->cartItem('Cubitan vanilj', 6, 800.00, 720.00),
        );

        $recorded = array_merge(array(
            'premiumkund förbrukning' => array('ex' => 150.00, 'tax' => 37.50),
            'premiumkund övrigt' => array('ex' => 180.00, 'tax' => 10.80),
        ), $recorded_override);

        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_applied_coupons')->andReturn(array_keys($recorded));
        $cart->shouldReceive('get_cart')->andReturn($items);
        $cart->shouldReceive('get_customer')->andReturn(null);
        $cart->shouldReceive('get_coupons')->andReturn(array(Mockery::mock('WC_Coupon'), Mockery::mock('WC_Coupon')));
        foreach ($recorded as $code => $amounts) {
            $cart->shouldReceive('get_coupon_discount_amount')->with($code)->andReturn($amounts['ex']);
            $cart->shouldReceive('get_coupon_discount_tax_amount')->with($code)->andReturn($amounts['tax']);
        }

        // Shares in precision cents, ex VAT (prices ex tax) - WooCommerce's shape.
        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array(
            'premiumkund förbrukning' => array('swabs' => 15000),
            'premiumkund övrigt' => array('nutri1' => 10000, 'nutri2' => 8000),
        ));

        $tax = Mockery::mock('alias:WC_Tax');
        $tax->shouldReceive('get_rates')->andReturnUsing(function ($class) {
            return 'reduced-rate' === $class ? array(2 => array('rate' => 6.0)) : array(1 => array('rate' => 25.0));
        });
        $tax->shouldReceive('calc_tax')->andReturnUsing(function ($cents, $rates, $inc) {
            $rate = reset($rates)['rate'] / 100;
            return array($inc ? $cents - $cents / (1 + $rate) : $cents * $rate);
        });

        return $cart;
    }

    private function byReference(array $lines)
    {
        $out = array();
        foreach ($lines as $l) {
            $out[$l['reference']] = $l;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // The reported cart
    // ──────────────────────────────────────────────────────────────────────

    public function testEachCouponCarriesTheRateOfWhatItDiscounts(): void
    {
        $lines = $this->byReference(Discount_Lines::from_cart($this->merchantCart(), $this->rateResolver()));

        $this->assertCount(2, $lines);

        // The one that already passed: 25% swabs.
        $this->assertSame(2500, $lines['discount_premiumkund förbrukning']['rate']);
        $this->assertEqualsWithDelta(150.00, $lines['discount_premiumkund förbrukning']['ex'], 0.001);
        $this->assertEqualsWithDelta(37.50, $lines['discount_premiumkund förbrukning']['tax'], 0.001);

        // The one Briqpay refused: 6% nutrition, used to be sent as 2500.
        $this->assertSame(600, $lines['discount_premiumkund övrigt']['rate']);
        $this->assertEqualsWithDelta(180.00, $lines['discount_premiumkund övrigt']['ex'], 0.001);
        $this->assertEqualsWithDelta(10.80, $lines['discount_premiumkund övrigt']['tax'], 0.001);
    }

    /**
     * Briqpay's own check, applied to every line we would send.
     */
    public function testEveryLinePassesBriqpaysPerLineCheck(): void
    {
        foreach (Discount_Lines::from_cart($this->merchantCart(), $this->rateResolver()) as $line) {
            $this->assertEqualsWithDelta(
                $line['ex'] * $line['rate'] / 10000,
                $line['tax'],
                0.011,
                $line['reference'] . ': taxRate * unitPrice != totalVatAmount'
            );
        }
    }

    /**
     * A coupon touching one rate keeps exactly the reference and name every
     * merchant has today - this must not rename anything for them.
     */
    public function testASingleRateCouponKeepsItsReferenceAndName(): void
    {
        $lines = $this->byReference(Discount_Lines::from_cart($this->merchantCart(), $this->rateResolver()));

        $this->assertArrayHasKey('discount_premiumkund övrigt', $lines);
        $this->assertSame('Coupon: premiumkund övrigt', $lines['discount_premiumkund övrigt']['name']);
        // Same unit price as 1.1.20 sent (the coupon's recorded ex-VAT total), so an
        // order whose session was created before this version still captures.
        $this->assertEqualsWithDelta(180.00, $lines['discount_premiumkund övrigt']['ex'], 0.001);
        $this->assertEqualsWithDelta(10.80, $lines['discount_premiumkund övrigt']['tax'], 0.001);
        $this->assertSame(600, $lines['discount_premiumkund övrigt']['rate']);
    }

    /**
     * A coupon spanning both rates is split, one line per rate, each labelled.
     */
    public function testACouponSpanningTwoRatesIsSplitPerRate(): void
    {
        WP_Mock::userFunction('wc_prices_include_tax', array('return' => false));

        $items = array(
            'swabs' => $this->cartItem('Swabs', 25, 500.00, 450.00),
            'nutri' => $this->cartItem('Nutri', 6, 1000.00, 900.00),
        );
        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_applied_coupons')->andReturn(array('tenoff'));
        $cart->shouldReceive('get_cart')->andReturn($items);
        $cart->shouldReceive('get_customer')->andReturn(null);
        $cart->shouldReceive('get_coupons')->andReturn(array(Mockery::mock('WC_Coupon')));
        $cart->shouldReceive('get_coupon_discount_amount')->with('tenoff')->andReturn(150.00);
        $cart->shouldReceive('get_coupon_discount_tax_amount')->with('tenoff')->andReturn(18.50); // 12.50 + 6.00

        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array('tenoff' => array('swabs' => 5000, 'nutri' => 10000)));
        $tax = Mockery::mock('alias:WC_Tax');
        $tax->shouldReceive('get_rates')->andReturnUsing(function ($class) {
            return 'reduced-rate' === $class ? array(2 => array('rate' => 6.0)) : array(1 => array('rate' => 25.0));
        });
        $tax->shouldReceive('calc_tax')->andReturnUsing(function ($cents, $rates, $inc) {
            return array($cents * reset($rates)['rate'] / 100);
        });

        $lines = $this->byReference(Discount_Lines::from_cart($cart, $this->rateResolver()));

        $this->assertSame(array('discount_tenoff_600', 'discount_tenoff_2500'), array_keys($lines));
        $this->assertSame('Coupon: tenoff (6%)', $lines['discount_tenoff_600']['name']);
        $this->assertEqualsWithDelta(100.00, $lines['discount_tenoff_600']['ex'], 0.001);
        $this->assertEqualsWithDelta(6.00, $lines['discount_tenoff_600']['tax'], 0.001);
        $this->assertSame('Coupon: tenoff (25%)', $lines['discount_tenoff_2500']['name']);
        $this->assertEqualsWithDelta(50.00, $lines['discount_tenoff_2500']['ex'], 0.001);
        $this->assertEqualsWithDelta(12.50, $lines['discount_tenoff_2500']['tax'], 0.001);
    }

    /**
     * Prices entered inc VAT: WooCommerce's shares are inc VAT and it subtracts
     * the tax to get the ex amount. So must we.
     */
    public function testIncTaxPricesAreReducedToExBeforeGrouping(): void
    {
        // Recorded ex amounts are what WooCommerce reports after its own subtraction.
        $cart = $this->merchantCart(true, array(
            'premiumkund förbrukning' => array('ex' => 120.00, 'tax' => 30.00),   // 150 inc at 25%
            'premiumkund övrigt' => array('ex' => 169.81, 'tax' => 10.19),        // 180 inc at 6%
        ));

        $lines = $this->byReference(Discount_Lines::from_cart($cart, $this->rateResolver()));

        $this->assertEqualsWithDelta(120.00, $lines['discount_premiumkund förbrukning']['ex'], 0.011);
        $this->assertEqualsWithDelta(30.00, $lines['discount_premiumkund förbrukning']['tax'], 0.011);
        $this->assertEqualsWithDelta(169.81, $lines['discount_premiumkund övrigt']['ex'], 0.011);
        $this->assertSame(600, $lines['discount_premiumkund övrigt']['rate']);
    }

    /**
     * Inc-tax prices AND a coupon spanning two rates: only the replay can
     * attribute the shares per coupon, so this pins that the inc->ex reduction
     * happens inside the replay (a wrong reduction would fail the totals check
     * and silently fall back to per-rate lines without the coupon's name).
     */
    public function testIncTaxCouponSpanningTwoRatesIsSplitFromTheReplay(): void
    {
        WP_Mock::userFunction('wc_prices_include_tax', array('return' => true));

        $items = array(
            'swabs' => $this->cartItem('Swabs', 25, 500.00, 450.00),
            'nutri' => $this->cartItem('Nutri', 6, 1000.00, 900.00),
        );
        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_applied_coupons')->andReturn(array('tenoff'));
        $cart->shouldReceive('get_cart')->andReturn($items);
        $cart->shouldReceive('get_customer')->andReturn(null);
        $cart->shouldReceive('get_coupons')->andReturn(array(Mockery::mock('WC_Coupon')));
        // WooCommerce's recorded ex totals: 62.50 inc -> 50 ex at 25%, 106 inc -> 100 ex at 6%.
        $cart->shouldReceive('get_coupon_discount_amount')->with('tenoff')->andReturn(150.00);
        $cart->shouldReceive('get_coupon_discount_tax_amount')->with('tenoff')->andReturn(18.50);

        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->andReturn(true);
        // Shares are INC tax when prices include tax.
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array('tenoff' => array('swabs' => 6250, 'nutri' => 10600)));
        $tax = Mockery::mock('alias:WC_Tax');
        $tax->shouldReceive('get_rates')->andReturnUsing(function ($class) {
            return 'reduced-rate' === $class ? array(2 => array('rate' => 6.0)) : array(1 => array('rate' => 25.0));
        });
        $tax->shouldReceive('calc_tax')->andReturnUsing(function ($cents, $rates, $inc) {
            $rate = reset($rates)['rate'] / 100;
            return array($inc ? $cents - $cents / (1 + $rate) : $cents * $rate);
        });

        $lines = $this->byReference(Discount_Lines::from_cart($cart, $this->rateResolver()));

        $this->assertSame(array('discount_tenoff_600', 'discount_tenoff_2500'), array_keys($lines), 'Per coupon AND rate - only the replay can do that.');
        $this->assertEqualsWithDelta(100.00, $lines['discount_tenoff_600']['ex'], 0.011);
        $this->assertEqualsWithDelta(6.00, $lines['discount_tenoff_600']['tax'], 0.011);
        $this->assertEqualsWithDelta(50.00, $lines['discount_tenoff_2500']['ex'], 0.011);
        $this->assertEqualsWithDelta(12.50, $lines['discount_tenoff_2500']['tax'], 0.011);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fallbacks
    // ──────────────────────────────────────────────────────────────────────

    /**
     * If the replay does not reproduce WooCommerce's recorded totals, the lines
     * are aggregated per rate from what WooCommerce already settled - still
     * exact against the totals, just not per coupon.
     */
    public function testAReplayThatDisagreesWithWooCommerceFallsBackToPerRateLines(): void
    {
        // Recorded totals say the coupons are worth more than the replay found.
        $cart = $this->merchantCart(false, array(
            'premiumkund förbrukning' => array('ex' => 150.00, 'tax' => 37.50),
            'premiumkund övrigt' => array('ex' => 180.00, 'tax' => 10.80),
        ));
        // Make the replay come out wrong by returning different shares.
        Mockery::close();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('wc_tax_enabled', array('return' => true));
        WP_Mock::userFunction('wc_prices_include_tax', array('return' => false));
        WP_Mock::userFunction('wc_remove_number_precision', array('return' => function ($v) {
            return $v / 100;
        }));
        $items = array(
            'swabs' => $this->cartItem('Swabs', 25, 500.00, 350.00),
            'nutri' => $this->cartItem('Nutri', 6, 1800.00, 1620.00),
        );
        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_applied_coupons')->andReturn(array('a', 'b'));
        $cart->shouldReceive('get_cart')->andReturn($items);
        $cart->shouldReceive('get_customer')->andReturn(null);
        $cart->shouldReceive('get_coupons')->andReturn(array());
        $cart->shouldReceive('get_coupon_discount_amount')->with('a')->andReturn(150.00);
        $cart->shouldReceive('get_coupon_discount_tax_amount')->with('a')->andReturn(37.50);
        $cart->shouldReceive('get_coupon_discount_amount')->with('b')->andReturn(180.00);
        $cart->shouldReceive('get_coupon_discount_tax_amount')->with('b')->andReturn(10.80);
        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array('a' => array('swabs' => 1)));
        Mockery::mock('alias:WC_Tax')->shouldReceive('get_rates', 'calc_tax')->andReturn(array());

        $lines = $this->byReference(Discount_Lines::from_cart($cart, $this->rateResolver()));

        $this->assertSame(array('discount_600', 'discount_2500'), array_keys($lines));
        $this->assertSame('Discount (6%)', $lines['discount_600']['name']);
        $this->assertEqualsWithDelta(180.00, $lines['discount_600']['ex'], 0.001);
        $this->assertEqualsWithDelta(10.80, $lines['discount_600']['tax'], 0.001);
        $this->assertEqualsWithDelta(150.00, $lines['discount_2500']['ex'], 0.001);
        $this->assertEqualsWithDelta(37.50, $lines['discount_2500']['tax'], 0.001);
    }

    /**
     * The last resort: the coupon's own VAT / amount ratio, snapped to a rate in
     * use - which is right for every coupon that covers one rate, i.e. the
     * merchant's case, and never produces the "25.01%" that dividing rounded
     * amounts can.
     */
    public function testRateFromAmountsSnapsToARateInUse(): void
    {
        $this->assertSame(600, Discount_Lines::rate_from_amounts(180.00, 10.80, array(2500, 600)));
        $this->assertSame(2500, Discount_Lines::rate_from_amounts(150.00, 37.50, array(2500, 600)));
        // 9.99 at 25% is 2.4975, stored as 2.50 -> 2503 by division; snapped.
        $this->assertSame(2500, Discount_Lines::rate_from_amounts(9.99, 2.50, array(2500, 1200)));
        // Far from any known rate: trust the arithmetic rather than snap wrongly.
        $this->assertSame(1200, Discount_Lines::rate_from_amounts(100.00, 12.00, array(2500)));
        $this->assertSame(0, Discount_Lines::rate_from_amounts(0.0, 0.0, array(2500)));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Orders (capture, refund, admin, hosted pages)
    // ──────────────────────────────────────────────────────────────────────

    private function orderItem($id, $rate_bp, $subtotal, $total)
    {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn($id);
        $item->shouldReceive('get_subtotal')->andReturn($subtotal);
        $item->shouldReceive('get_total')->andReturn($total);
        $item->shouldReceive('get_subtotal_tax')->andReturn(round($subtotal * $rate_bp / 10000, 2));
        $item->shouldReceive('get_total_tax')->andReturn(round($total * $rate_bp / 10000, 2));
        $item->_rate_bp = $rate_bp;
        return $item;
    }

    private function couponItem($code, $ex, $tax, $coupon_info = '')
    {
        $c = Mockery::mock('WC_Order_Item_Coupon');
        $c->shouldReceive('get_code')->andReturn($code);
        $c->shouldReceive('get_discount')->andReturn($ex);
        $c->shouldReceive('get_discount_tax')->andReturn($tax);
        $c->shouldReceive('get_meta')->with('coupon_info', true)->andReturn($coupon_info);
        $c->shouldReceive('get_meta')->with('coupon_data', true)->andReturn('');
        return $c;
    }

    private function orderWith(array $coupon_items, array $items, $include_tax = false)
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(900);
        $order->shouldReceive('get_prices_include_tax')->andReturn($include_tax);
        $order->shouldReceive('get_items')->with('coupon')->andReturn($coupon_items);
        $order->shouldReceive('get_items')->with()->andReturn($items);
        return $order;
    }

    /**
     * Briqpay matches capture lines to the session by reference, name and unit
     * price, so the order side must rebuild exactly the lines the cart side
     * produced. Discount Rules for WooCommerce applies its cart rules as virtual
     * coupons - WC_Coupon knows nothing about them in the admin. WooCommerce's
     * own recalculate_coupons() rebuilds such a coupon from the coupon_info
     * snapshot it stored at checkout; the replay here does the same.
     */
    public function testAVirtualCouponIsRebuiltFromItsCouponInfoLikeWooCommerceDoes(): void
    {
        WP_Mock::userFunction('wc_get_coupon_id_by_code', array('return' => 0));
        $order = $this->orderWith(
            array($this->couponItem('regelrabatt qa', 29.00, 5.54, '[0,"regelrabatt qa","percent",10]')),
            array(
                11 => $this->orderItem(11, 2500, 200.00, 180.00),
                12 => $this->orderItem(12, 600, 90.00, 81.00),
            )
        );

        $coupon = Mockery::mock('overload:WC_Coupon');
        $coupon->shouldReceive('set_short_info')->once()->with('[0,"regelrabatt qa","percent",10]');
        $coupon->shouldReceive('set_code')->once()->with('regelrabatt qa');
        $coupon->shouldReceive('set_virtual')->once()->with(true);
        $coupon->shouldReceive('get_amount')->andReturn(10);
        $coupon->shouldNotReceive('set_amount');

        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->once()->with(Mockery::type('WC_Coupon'), false)->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array(
            'regelrabatt qa' => array(11 => 2000, 12 => 900),
        ));

        $lines = $this->byReference(Discount_Lines::from_order($order, function ($item) {
            return $item->_rate_bp;
        }));

        $this->assertEqualsCanonicalizing(array('discount_regelrabatt qa_2500', 'discount_regelrabatt qa_600'), array_keys($lines));
        $this->assertSame('Coupon: regelrabatt qa (25%)', $lines['discount_regelrabatt qa_2500']['name']);
        $this->assertEqualsWithDelta(20.00, $lines['discount_regelrabatt qa_2500']['ex'], 0.001);
        $this->assertEqualsWithDelta(5.00, $lines['discount_regelrabatt qa_2500']['tax'], 0.001);
        $this->assertEqualsWithDelta(9.00, $lines['discount_regelrabatt qa_600']['ex'], 0.001);
        $this->assertEqualsWithDelta(0.54, $lines['discount_regelrabatt qa_600']['tax'], 0.001);
    }

    public function testACouponThatStillExistsIsLoadedByItsId(): void
    {
        WP_Mock::userFunction('wc_get_coupon_id_by_code', array('return' => 417));
        $order = $this->orderWith(
            array($this->couponItem('qa10', 20.00, 5.00, '[417,"qa10","percent",10]')),
            array(11 => $this->orderItem(11, 2500, 200.00, 180.00))
        );
        $coupon = Mockery::mock('overload:WC_Coupon');
        $coupon->shouldReceive('__construct')->once()->with(417);
        $coupon->shouldNotReceive('set_short_info', 'set_code', 'set_virtual', 'set_amount');

        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->once()->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array('qa10' => array(11 => 2000)));

        $lines = Discount_Lines::from_order($order, function ($item) {
            return $item->_rate_bp;
        });
        $this->assertSame('discount_qa10', $lines[0]['reference']);
    }

    /**
     * A dynamic coupon stored without an amount replays as a fixed amount equal
     * to what it took off - inc VAT when the order's prices include tax.
     */
    public function testADynamicCouponWithoutAnAmountReplaysWhatItTookOff(): void
    {
        WP_Mock::userFunction('wc_get_coupon_id_by_code', array('return' => 0));
        foreach (array(false => 180.00, true => 190.80) as $include_tax => $expected_amount) {
            $order = $this->orderWith(
                array($this->couponItem('dyn', 180.00, 10.80, '[0,"dyn","fixed_cart",0]')),
                array(12 => $this->orderItem(12, 600, 1800.00, 1620.00)),
                (bool) $include_tax
            );
            $coupon = Mockery::mock('overload:WC_Coupon');
            $coupon->shouldReceive('set_short_info', 'set_code', 'set_virtual');
            $coupon->shouldReceive('get_amount')->andReturn(0);
            $coupon->shouldReceive('set_amount')->once()->with($expected_amount);
            $coupon->shouldReceive('set_discount_type')->once()->with('fixed_cart');

            $discounts = Mockery::mock('overload:WC_Discounts');
            $discounts->shouldReceive('apply_coupon')->once()->andReturn(true);
            $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array('dyn' => array(12 => $include_tax ? 19080 : 18000)));

            $lines = Discount_Lines::from_order($order, function ($item) {
                return $item->_rate_bp;
            });
            $this->assertSame('discount_dyn', $lines[0]['reference'], 'include_tax=' . var_export((bool) $include_tax, true));
            $this->assertEqualsWithDelta(180.00, $lines[0]['ex'], 0.011);
            Mockery::close();
        }
    }

    public function testTheOrderSideAppliesWooCommercesRecalculateCouponFilter(): void
    {
        $method = new \ReflectionMethod(Discount_Lines::class, 'order_coupon');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $this->assertStringContainsString("apply_filters('woocommerce_order_recalculate_coupons_coupon_object', \$coupon, \$code, \$coupon_item, \$order)", $source);
    }

    public function testOrderLinesAreSplitTheSameWayFromTheReplay(): void
    {
        WP_Mock::userFunction('wc_get_coupon_id_by_code', array('return' => 417));
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(900);
        $order->shouldReceive('get_prices_include_tax')->andReturn(false);
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array(
            $this->couponItem('förbrukning', 150.00, 37.50),
            $this->couponItem('övrigt', 180.00, 10.80),
        ));
        $order->shouldReceive('get_items')->with()->andReturn(array(
            11 => $this->orderItem(11, 2500, 500.00, 350.00),
            12 => $this->orderItem(12, 600, 1800.00, 1620.00),
        ));

        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array(
            'förbrukning' => array(11 => 15000),
            'övrigt' => array(12 => 18000),
        ));
        Mockery::mock('overload:WC_Coupon');

        $lines = $this->byReference(Discount_Lines::from_order($order, function ($item) {
            return $item->_rate_bp;
        }));

        $this->assertSame(2500, $lines['discount_förbrukning']['rate']);
        $this->assertSame(600, $lines['discount_övrigt']['rate']);
        $this->assertEqualsWithDelta(10.80, $lines['discount_övrigt']['tax'], 0.011);
    }

    /**
     * In the admin, long after checkout, a virtual coupon from a discount plugin
     * cannot be re-evaluated: WC_Coupon knows nothing about it and the replay
     * finds no discount. The lines then come from the stored line totals.
     */
    public function testAnOrderWhoseCouponsCannotBeReplayedUsesItsStoredLineTotals(): void
    {
        WP_Mock::userFunction('wc_get_coupon_id_by_code', array('return' => 417));
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(901);
        $order->shouldReceive('get_prices_include_tax')->andReturn(false);
        $order->shouldReceive('get_items')->with('coupon')->andReturn(array(
            $this->couponItem('premiumkund övrigt', 180.00, 10.80),
        ));
        $order->shouldReceive('get_items')->with()->andReturn(array(
            12 => $this->orderItem(12, 600, 1800.00, 1620.00),
        ));
        $discounts = Mockery::mock('overload:WC_Discounts');
        $discounts->shouldReceive('apply_coupon')->andReturn(true);
        $discounts->shouldReceive('get_discounts')->with(true)->andReturn(array()); // the virtual coupon is gone
        Mockery::mock('overload:WC_Coupon');

        $lines = Discount_Lines::from_order($order, function ($item) {
            return $item->_rate_bp;
        });

        $this->assertCount(1, $lines);
        $this->assertSame('discount_600', $lines[0]['reference']);
        $this->assertSame(600, $lines[0]['rate']);
        $this->assertEqualsWithDelta(180.00, $lines[0]['ex'], 0.001);
        $this->assertEqualsWithDelta(10.80, $lines[0]['tax'], 0.001);
    }

    /**
     * A refund of a split coupon is split the same way, so each refund line
     * reverses a line that exists with the same rate.
     */
    public function testARefundOfASplitCouponIsSplitProportionally(): void
    {
        $order_lines = array(
            array('reference' => 'discount_tenoff_600', 'name' => 'Coupon: tenoff (6%)', 'code' => 'tenoff', 'rate' => 600, 'ex' => 100.00, 'tax' => 6.00),
            array('reference' => 'discount_tenoff_2500', 'name' => 'Coupon: tenoff (25%)', 'code' => 'tenoff', 'rate' => 2500, 'ex' => 50.00, 'tax' => 12.50),
        );

        // Half of the coupon refunded.
        $lines = $this->byReference(Discount_Lines::split_refund($order_lines, 'tenoff', 75.00, 9.25));

        $this->assertEqualsWithDelta(50.00, $lines['discount_tenoff_600']['ex'], 0.001);
        $this->assertEqualsWithDelta(25.00, $lines['discount_tenoff_2500']['ex'], 0.001);
        $this->assertEqualsWithDelta(75.00, $lines['discount_tenoff_600']['ex'] + $lines['discount_tenoff_2500']['ex'], 0.001, 'Nothing lost to rounding.');
        $this->assertEqualsWithDelta(9.25, $lines['discount_tenoff_600']['tax'] + $lines['discount_tenoff_2500']['tax'], 0.001);
    }

    public function testARefundOfACouponTheOrderDoesNotKnowUsesItsOwnAmounts(): void
    {
        $lines = Discount_Lines::split_refund(array(), 'mystery', 180.00, 10.80);

        $this->assertCount(1, $lines);
        $this->assertSame('discount_mystery', $lines[0]['reference']);
        $this->assertSame(600, $lines[0]['rate']);
    }

    public function testRateLabels(): void
    {
        $this->assertSame('25%', Discount_Lines::rate_label(2500));
        $this->assertSame('6%', Discount_Lines::rate_label(600));
        $this->assertSame('25.5%', Discount_Lines::rate_label(2550));
        $this->assertSame('0%', Discount_Lines::rate_label(0));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Every site that builds coupon lines goes through the helper
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Six places built coupon lines, each stamping the first line's rate. If
     * any of them is wired back to its own arithmetic, a session and its later
     * capture or refund disagree - so all six are pinned here.
     *
     * @dataProvider couponSiteProvider
     */
    public function testEveryCouponSiteUsesTheHelper($class, $method, $needle): void
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $this->assertStringContainsString($needle, $body, $class . '::' . $method . '() must build coupon lines through Discount_Lines.');
        $this->assertStringNotContainsString('get_coupon_tax_rate', $body, 'The first-line-rate lookup must be gone.');
        $this->assertStringNotContainsString("'discount_' . \$code", $body, 'No site may assemble a coupon reference on its own.');
    }

    public function couponSiteProvider()
    {
        return array(
            'session cart' => array(\Briqpay\WooCommerce\Session_Manager::class, 'get_cart_items', 'Discount_Lines::from_cart('),
            'capture basis' => array(\Briqpay\WooCommerce\Order_Management::class, 'get_capture_basis', '$this->discount_lines($order)'),
            'remaining to capture' => array(\Briqpay\WooCommerce\Order_Management::class, 'get_remaining_items_to_capture', '$this->discount_lines($order)'),
            'order cart (hosted pages too)' => array(\Briqpay\WooCommerce\Order_Management::class, 'get_order_cart', '$this->discount_lines($order)'),
            'refund' => array(\Briqpay\WooCommerce\Order_Management::class, 'get_refund_items', 'Discount_Lines::split_refund('),
            'admin capture form' => array(\Briqpay\WooCommerce\Admin_Order_Meta_Box::class, 'get_remaining_items', 'Discount_Lines::from_order('),
        );
    }
}
