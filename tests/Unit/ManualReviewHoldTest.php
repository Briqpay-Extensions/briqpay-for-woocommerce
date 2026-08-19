<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Order_Management;
use Briqpay\WooCommerce\Order_Status_Manager;
use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Manual review, and respecting a hold the merchant put on.
 *
 * Two related rules, both about not silently overriding a decision that an order
 * needs a human:
 *
 *  1. Briqpay tags a session data.paymentTags.manual_review - the order goes to
 *     on-hold, and nothing afterwards advances it to processing.
 *  2. An order already on-hold for ANY reason - the merchant's own leasing logic, a
 *     capture failure, an amount mismatch - is left where it is. The webhook status
 *     hierarchy ranks on-hold below processing, so an approval event used to promote
 *     a held order straight past the hold.
 */
class ManualReviewHoldTest extends TestCase
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

    private function orderOnHold($on_hold, $id = 900)
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($id);
        $order->shouldReceive('has_status')->with('on-hold')->andReturn($on_hold);
        return $order;
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
    // Reading the tag
    // ──────────────────────────────────────────────────────────────────────

    public function testMapFormWithBooleanTrue(): void
    {
        $this->assertTrue(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('manual_review' => true)),
        )));
    }

    /**
     * @dataProvider truthyProvider
     */
    public function testMapFormAcceptsTruthyScalars($value): void
    {
        $this->assertTrue(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('manual_review' => $value)),
        )));
    }

    public function truthyProvider()
    {
        // JSON decoding and hand-built payloads produce all of these in practice.
        return array(
            array(true),
            array(1),
            array('1'),
            array('true'),
            array('True'),
            array('yes'),
        );
    }

    /**
     * @dataProvider falsyProvider
     */
    public function testMapFormRejectsFalsyValues($value): void
    {
        $this->assertFalse(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('manual_review' => $value)),
        )));
    }

    public function falsyProvider()
    {
        return array(
            array(false),
            array(0),
            array('0'),
            array('false'),
            array('no'),
            array(''),
        );
    }

    /**
     * paymentTags is a tag bag and also arrives as a plain list.
     */
    public function testListFormIsRecognised(): void
    {
        $this->assertTrue(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('fraud_check', 'manual_review')),
        )));

        $this->assertFalse(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('fraud_check')),
        )));
    }

    public function testTopLevelPaymentTagsAreAlsoRead(): void
    {
        // Webhook bodies are flatter than a full session payload.
        $this->assertTrue(Order_Management::session_requires_manual_review(array(
            'paymentTags' => array('manual_review' => true),
        )));
    }

    /**
     * A tag we cannot parse must never hold up a legitimate order.
     */
    public function testUnparseablePayloadsReadAsNoReview(): void
    {
        $this->assertFalse(Order_Management::session_requires_manual_review(array()));
        $this->assertFalse(Order_Management::session_requires_manual_review(array('data' => array())));
        $this->assertFalse(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => 'manual_review'),
        )));
        $this->assertFalse(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array('manual_review' => array('nested'))),
        )));
        $this->assertFalse(Order_Management::session_requires_manual_review(array(
            'data' => array('paymentTags' => array()),
        )));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Respecting an existing hold
    // ──────────────────────────────────────────────────────────────────────

    public function testAnOnHoldOrderIsReportedAsHeld(): void
    {
        $this->assertTrue(Order_Management::is_held_for_merchant($this->orderOnHold(true)));
    }

    public function testAnyOtherStatusIsNotHeld(): void
    {
        $this->assertFalse(Order_Management::is_held_for_merchant($this->orderOnHold(false)));
    }

    /**
     * Escape hatch for a merchant who wants the previous auto-advance behaviour.
     */
    public function testTheHoldCanBeOverriddenByFilter(): void
    {
        $order = $this->orderOnHold(true);

        WP_Mock::onFilter('briqpay_respect_on_hold_status')->with(true, $order)->reply(false);

        $this->assertFalse(Order_Management::is_held_for_merchant($order));
    }

    public function testAnObjectWithoutHasStatusIsNotHeld(): void
    {
        $this->assertFalse(Order_Management::is_held_for_merchant(new \stdClass()));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Every status-advancing path is guarded
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The hierarchy ranks on-hold (2) below processing (3), so the rank check alone
     * would let an approval promote a held order. The guard has to come before the
     * switch, not inside one branch of it.
     */
    public function testOrderStatusWebhookBailsBeforeTheSwitch(): void
    {
        $source = $this->methodSource(Webhooks::class, 'handle_order_status');

        $guard_pos = strpos($source, 'Order_Management::is_held_for_merchant($order)');
        $switch_pos = strpos($source, 'switch ($status)');

        $this->assertNotFalse($guard_pos, 'The status webhook must check for a hold.');
        $this->assertNotFalse($switch_pos);
        $this->assertLessThan(
            $switch_pos,
            $guard_pos,
            'The hold must be checked before any branch can change the status.'
        );
    }

    public function testApprovedWebhookHoldsInsteadOfProcessingWhenFlagged(): void
    {
        $source = $this->methodSource(Webhooks::class, 'handle_order_status');

        $review_pos = strpos($source, 'Order_Management::session_requires_manual_review($data)');
        $processing_pos = strpos($source, "update_status('processing'");

        $this->assertNotFalse($review_pos);
        $this->assertNotFalse($processing_pos);
        $this->assertLessThan(
            $processing_pos,
            $review_pos,
            'The review check must precede the promotion to processing.'
        );
        $this->assertStringContainsString(
            'Order approved but flagged for manual review',
            $source,
            'The order note must tell the merchant why it is held.'
        );
    }

    public function testCompletedSessionPathChecksBothRules(): void
    {
        $source = $this->methodSource(Webhooks::class, 'process_webhook_callback');

        $review_pos = strpos($source, 'session_requires_manual_review($session)');
        $held_pos = strpos($source, 'is_held_for_merchant($order)');
        $complete_pos = strpos($source, 'payment_complete($session_id)');

        $this->assertNotFalse($review_pos);
        $this->assertNotFalse($held_pos);
        $this->assertLessThan($complete_pos, $review_pos);
        $this->assertLessThan($complete_pos, $held_pos);
    }

    public function testGenericStatusMappingRespectsAHold(): void
    {
        $source = $this->methodSource(Webhooks::class, 'process_webhook_callback');

        $this->assertStringContainsString(
            'not changing its status to',
            $source,
            'The generic status mapping must also refuse to move a held order.'
        );
    }

    public function testCaptureWebhookDoesNotCompleteAHeldOrder(): void
    {
        $source = $this->methodSource(Webhooks::class, 'handle_capture_status');

        $held_pos = strpos($source, 'is_held_for_merchant($order)');
        $complete_pos = strpos($source, 'payment_complete($capture_id)');

        $this->assertNotFalse($held_pos, 'A capture must not complete a held order.');
        $this->assertLessThan($complete_pos, $held_pos);
    }

    public function testJanitorChecksBothRules(): void
    {
        $source = $this->methodSource(Order_Status_Manager::class, 'janitor_cleanup_task');

        $held_pos = strpos($source, 'is_held_for_merchant($order)');
        $review_pos = strpos($source, 'session_requires_manual_review($session)');
        $complete_pos = strpos($source, 'payment_complete($session_id)');

        $this->assertNotFalse($held_pos);
        $this->assertNotFalse($review_pos);
        $this->assertLessThan($complete_pos, $held_pos);
        $this->assertLessThan($complete_pos, $review_pos);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Return handler
    // ──────────────────────────────────────────────────────────────────────

    public function testReturnHandlerHoldsAFlaggedPendingOrder(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(901);
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);
        $order->shouldReceive('update_status')
            ->with('on-hold', Mockery::any())
            ->once();

        $this->invokeHold($order, array(
            'data' => array('paymentTags' => array('manual_review' => true)),
        ));

        $this->assertTrue(true);
    }

    /**
     * Only ever promotes from pending. Touching an order that another path already
     * resolved would be the silent status change this feature exists to prevent.
     */
    public function testReturnHandlerLeavesANonPendingOrderAlone(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(902);
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);
        $order->shouldReceive('update_status')->never();

        $this->invokeHold($order, array(
            'data' => array('paymentTags' => array('manual_review' => true)),
        ));

        $this->assertTrue(true);
    }

    public function testReturnHandlerDoesNothingWithoutTheTag(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(903);
        $order->shouldReceive('update_status')->never();

        $this->invokeHold($order, array('data' => array()));

        $this->assertTrue(true);
    }

    private function invokeHold($order, array $session)
    {
        $handler = new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, 'maybe_hold_for_manual_review');
        $ref->setAccessible(true);
        $ref->invokeArgs($handler, array($order, $session));
    }
}
