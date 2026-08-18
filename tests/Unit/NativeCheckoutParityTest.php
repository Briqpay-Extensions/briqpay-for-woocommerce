<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Gateway;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Parity with WC_Checkout beyond the action hooks themselves.
 *
 * Covers the order properties and item metadata that WooCommerce's own checkout
 * sets and this flow used to drop, the safeguards it applies, and the two
 * previously-broken entry points (process_payment() and the unregistered
 * woocommerce_checkout_order_processed handler).
 */
class NativeCheckoutParityTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var array Meta written to the fake order. */
    private $order_meta = array();

    /** @var string Status the fake order reports. */
    private $status = 'checkout-draft';

    /** @var string Customer note on the fake order. */
    private $note = '';

    /** @var string|null Cart hash set on the fake order. */
    private $cart_hash = null;

    /** @var array Statuses set_status() was called with. */
    private $status_calls = array();

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Actions::reset();

        $this->order_meta = array();
        $this->status = 'checkout-draft';
        $this->note = '';
        $this->cart_hash = null;
        $this->status_calls = array();

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('current_time', array('return' => '2026-08-18 12:00:00'));
        WP_Mock::userFunction('sanitize_textarea_field', array('return_arg' => 0));
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

    private function mockOrder($id = 555, $payment_method = 'briqpay')
    {
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn($id);
        $order->shouldReceive('get_payment_method')->andReturn($payment_method);
        $order->shouldReceive('save')->andReturn(true);

        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) {
            return $this->order_meta[$key] ?? '';
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function ($key, $value) {
            $this->order_meta[$key] = $value;
        });

        $order->shouldReceive('get_customer_note')->andReturnUsing(function () {
            return $this->note;
        });
        $order->shouldReceive('set_customer_note')->andReturnUsing(function ($v) {
            $this->note = $v;
        });

        $order->shouldReceive('set_cart_hash')->andReturnUsing(function ($v) {
            $this->cart_hash = $v;
        });

        $order->shouldReceive('has_status')->andReturnUsing(function ($statuses) {
            return in_array($this->status, (array) $statuses, true);
        });
        $order->shouldReceive('set_status')->andReturnUsing(function ($status, $note = '') {
            $this->status_calls[] = $status;
            $this->status = $status;
        });

        return $order;
    }

    private function mockWc(array $session_store = array(), $cart_hash = 'abc123')
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->andReturnUsing(function ($key, $default = null) use (&$session_store) {
            return array_key_exists($key, $session_store) ? $session_store[$key] : $default;
        });
        $session->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$session_store) {
            $session_store[$key] = $value;
        });

        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_cart_hash')->andReturn($cart_hash);

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        $wc->cart = $cart;

        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    private function invoke($method, array $args = array())
    {
        $handler = new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($handler, $args);
    }

    private function methodSource($class, $name)
    {
        $method = new \ReflectionMethod($class, $name);
        $lines = file($method->getFileName());
        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Cart hash and customer note
    // ──────────────────────────────────────────────────────────────────────

    public function testCartHashIsSet(): void
    {
        $this->mockWc(array(), 'hash-xyz');
        $order = $this->mockOrder();

        $this->invoke('apply_native_order_properties', array($order));

        $this->assertSame('hash-xyz', $this->cart_hash);
    }

    public function testCustomerNoteComesFromOrderCommentsOnClassic(): void
    {
        $this->mockWc(array(
            Checkout_Handler::POSTED_DATA_KEY => array('order_comments' => 'Leave at the back door'),
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => 'classic',
        ));
        $order = $this->mockOrder();

        $this->invoke('apply_native_order_properties', array($order));

        $this->assertSame('Leave at the back door', $this->note);
    }

    /**
     * Blocks names the field differently, so reading only order_comments would
     * drop the note on every Blocks checkout.
     */
    public function testCustomerNoteComesFromCustomerNoteOnBlocks(): void
    {
        $this->mockWc(array(
            Checkout_Handler::POSTED_DATA_KEY => array('customer_note' => 'Ring the bell'),
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => 'blocks',
        ));
        $order = $this->mockOrder();

        $this->invoke('apply_native_order_properties', array($order));

        $this->assertSame('Ring the bell', $this->note);
    }

    /**
     * A reused Store API draft may already carry a note the customer typed in
     * this session; a replayed one must not overwrite it.
     */
    public function testExistingNoteIsNotOverwritten(): void
    {
        $this->mockWc(array(
            Checkout_Handler::POSTED_DATA_KEY => array('order_comments' => 'Replayed'),
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => 'classic',
        ));
        $order = $this->mockOrder();
        $this->note = 'Already on the order';

        $this->invoke('apply_native_order_properties', array($order));

        $this->assertSame('Already on the order', $this->note);
    }

    public function testNoNoteWhenTheFieldIsAbsent(): void
    {
        $this->mockWc(array(
            Checkout_Handler::POSTED_DATA_KEY => array('billing_city' => 'Stockholm'),
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => 'classic',
        ));
        $order = $this->mockOrder();

        $this->invoke('apply_native_order_properties', array($order));

        $this->assertSame('', $this->note);
    }

    /**
     * These are defect fixes, not new third-party behaviour, so they must apply
     * to stores that have not opted into the checkout actions.
     */
    public function testNativePropertiesAreNotGated(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'apply_native_order_properties');

        $this->assertStringNotContainsString('checkout_hooks_enabled', $source);
        $this->assertStringNotContainsString('hook_enabled', $source);

        $caller = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');
        $this->assertStringContainsString('apply_native_order_properties($order)', $caller);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Item metadata parity
    // ──────────────────────────────────────────────────────────────────────

    public function testLineItemsCarryTheProductTaxClass(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $this->assertStringContainsString(
            '$item->set_tax_class($product->get_tax_class());',
            $source,
            'Without the tax class an admin recalculation taxes reduced-rate products at the standard rate.'
        );
    }

    public function testShippingItemsCarryTaxStatusAndRateMeta(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'add_shipping_items_from_cart');

        $this->assertStringContainsString("\$props['tax_status'] = \$rate->tax_status;", $source);
        $this->assertStringContainsString('$rate->get_meta_data()', $source);
        $this->assertStringContainsString(
            '$item->add_meta_data($key, $value, true)',
            $source,
            'Table-rate and pickup-point plugins store the selection in rate meta.'
        );
    }

    public function testShippingRateGuardsForOlderWooCommerce(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'add_shipping_items_from_cart');

        $this->assertStringContainsString('isset($rate->tax_status)', $source);
        $this->assertStringContainsString("is_callable(array(\$rate, 'get_meta_data'))", $source);
    }

    public function testCouponItemsCarryCouponInfo(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'add_coupon_items_from_cart');

        $this->assertStringContainsString("add_meta_data('coupon_info'", $source);
        $this->assertStringContainsString(
            "is_callable(array(\$coupon, 'get_short_info'))",
            $source,
            'get_short_info() is WC 3.7+, so it must be guarded.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Safeguards
    // ──────────────────────────────────────────────────────────────────────

    public function testStockReservationIsAttemptedButNeverBlocks(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'reserve_stock');

        $this->assertStringContainsString("function_exists('wc_reserve_stock_for_order')", $source);
        $this->assertStringContainsString('wc_reserve_stock_for_order($order)', $source);
        $this->assertStringContainsString(
            'catch (\\Throwable',
            $source,
            'A reservation failure must be logged, not thrown at a customer mid-authorization.'
        );
    }

    public function testStockIsReleasedWhenTheDecisionIsRejected(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_make_decision');

        $this->assertStringContainsString(
            '$this->release_stock($order);',
            $source,
            'A refused purchase must not hold stock until the reservation expires.'
        );

        $invalid_pos = strpos($source, "if (!\$validation['valid']) {");
        $release_pos = strpos($source, '$this->release_stock($order);');

        $this->assertNotFalse($invalid_pos);
        $this->assertNotFalse($release_pos);
        $this->assertLessThan(
            $release_pos,
            $invalid_pos,
            'The release belongs on the validation-failed branch only.'
        );
    }

    public function testCogsIsRecalculatedWhenSupported(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'recalculate_cogs');

        $this->assertStringContainsString(
            "is_callable(array(\$order, 'calculate_cogs_total_value'))",
            $source,
            'COGS is WooCommerce 9.5+, so it must be guarded.'
        );
    }

    public function testTaxItemHookIsOfferedAndGated(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'fire_tax_item_hooks');

        $this->assertStringContainsString(
            "hook_enabled('woocommerce_checkout_create_order_tax_item')",
            $source
        );
        $this->assertStringContainsString("get_items('tax')", $source);
        $this->assertStringContainsString("do_action('woocommerce_checkout_create_order_tax_item'", $source);
    }

    public function testOrderExceptionActionIsFiredAndCannotMaskTheError(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_make_decision');

        $this->assertStringContainsString(
            "do_action('woocommerce_checkout_order_exception', \$e)",
            $source
        );
        $this->assertStringContainsString(
            "hook_enabled('woocommerce_checkout_order_exception')",
            $source,
            'A new third-party code path must sit behind the gate.'
        );

        $hook_pos = strpos($source, "do_action('woocommerce_checkout_order_exception'");
        $respond_pos = strpos($source, "wp_send_json_error(array('message' => \$e->getMessage()))");

        $this->assertLessThan(
            $respond_pos,
            $hook_pos,
            'The customer must still receive the original error after listeners run.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // The previously-unregistered handler
    // ──────────────────────────────────────────────────────────────────────

    public function testHandlerIsRegistered(): void
    {
        $handler = new Checkout_Handler();

        WP_Mock::expectActionAdded(
            'woocommerce_checkout_order_processed',
            array($handler, 'handle_checkout_order_processed'),
            10,
            3
        );

        // The rest of init()'s registrations are not under test here.
        WP_Mock::userFunction('add_shortcode', array('return' => true));

        $handler->init();

        $this->assertTrue(true);
    }

    public function testHandlerIgnoresNonBriqpayOrders(): void
    {
        $this->mockWc();
        $order = $this->mockOrder(555, 'stripe');

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame(array(), $this->order_meta);
    }

    /**
     * Guard 1: our own fire_commit_hooks() stamps the flag before running its
     * callback, so seeing it means we are the source and there is nothing to do.
     */
    public function testHandlerNoOpsWhenWeAreTheSourceOfTheAction(): void
    {
        $this->mockWc();
        $order = $this->mockOrder();
        $this->order_meta['_briqpay_hooks_commit'] = '2026-08-18 11:00:00';
        $this->status = 'processing';

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame(array(), $this->status_calls, 'Must not touch the status.');
        $this->assertSame(
            '2026-08-18 11:00:00',
            $this->order_meta['_briqpay_hooks_commit'],
            'Must not restamp the flag.'
        );
    }

    /**
     * Guard 2: when WooCommerce's pipeline fired the action, every listener has
     * already run, so our commit hooks must be suppressed for this order.
     */
    public function testHandlerSuppressesOurDuplicateCommitHooks(): void
    {
        $this->mockWc(array('briqpay_session_id' => 'sess_native'));
        $order = $this->mockOrder();

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertArrayHasKey(
            '_briqpay_hooks_commit',
            $this->order_meta,
            'Native-fired actions must not be fired again by fire_commit_hooks().'
        );
        $this->assertSame('sess_native', $this->order_meta['_briqpay_session_id']);
    }

    /**
     * Guard 3 - the regression that registering the old version of this method
     * would have introduced. The webhook fallback fires the same action on an
     * order already advanced to 'processing'.
     */
    public function testHandlerNeverDowngradesAProcessingOrder(): void
    {
        $this->mockWc(array('briqpay_session_id' => 'sess_native'));
        $order = $this->mockOrder();
        $this->status = 'processing';

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame(
            array(),
            $this->status_calls,
            'An order already at processing must never be pushed back to pending.'
        );
        $this->assertSame('processing', $this->status);
    }

    public function testHandlerPromotesADraftToPending(): void
    {
        $this->mockWc(array('briqpay_session_id' => 'sess_native'));
        $order = $this->mockOrder();
        $this->status = 'checkout-draft';

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame(array('pending'), $this->status_calls);
    }

    public function testHandlerDoesNotPromoteWithoutASession(): void
    {
        $this->mockWc();
        $order = $this->mockOrder();
        $this->status = 'checkout-draft';

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame(
            array(),
            $this->status_calls,
            'No Briqpay session means nothing to await; leave the draft alone.'
        );
    }

    public function testHandlerDoesNotOverwriteAnExistingSessionId(): void
    {
        $this->mockWc(array('briqpay_session_id' => 'sess_new'));
        $order = $this->mockOrder();
        $this->order_meta['_briqpay_session_id'] = 'sess_original';

        (new Checkout_Handler())->handle_checkout_order_processed(555, array(), $order);

        $this->assertSame('sess_original', $this->order_meta['_briqpay_session_id']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // process_payment()
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The core safety property: success requires Briqpay to confirm payment, and
     * anything ambiguous - missing session, API error, unexpected shape - reads as
     * unpaid.
     */
    public function testProcessPaymentOnlySucceedsOnAConfirmedPaidSession(): void
    {
        $source = $this->methodSource(Gateway::class, 'process_payment');

        $this->assertStringContainsString(
            '$this->session_is_paid_for_order($order)',
            $source,
            'Success must be conditional on verified payment.'
        );

        $success_pos = strpos($source, "'result' => 'success'");
        $failure_pos = strpos($source, "'result' => 'failure'");

        $this->assertNotFalse($success_pos, 'It must be possible to succeed.');
        $this->assertNotFalse($failure_pos, 'The bypass block must remain.');
        $this->assertLessThan(
            $failure_pos,
            $success_pos,
            'The verified-paid branch returns before the block.'
        );
    }

    public function testProcessPaymentReturnsARedirectOnSuccess(): void
    {
        $source = $this->methodSource(Gateway::class, 'process_payment');

        $this->assertStringContainsString("'redirect' =>", $source);
    }

    public function testPaidCheckTreatsEveryAmbiguityAsUnpaid(): void
    {
        $source = $this->methodSource(Gateway::class, 'session_is_paid_for_order');

        $this->assertStringContainsString('is_wp_error($session)', $source);
        $this->assertStringContainsString('!is_array($session)', $source);
        $this->assertStringContainsString(
            'return false;',
            $source,
            'The default must be unpaid.'
        );
        $this->assertStringContainsString(
            'in_array($status, $paid_statuses, true)',
            $source,
            'Status matching must be strict.'
        );
    }

    public function testPaidCheckDoesNotTrustLocalStateAlone(): void
    {
        $source = $this->methodSource(Gateway::class, 'session_is_paid_for_order');

        $this->assertStringContainsString(
            '$api->get_session($session_id)',
            $source,
            'Payment confirmation must come from Briqpay, never from local meta.'
        );
    }
}
