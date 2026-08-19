<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Behavioural webhook tests for manual review and merchant holds.
 *
 * ManualReviewHoldTest covers the tag parsing and asserts the guards sit in the
 * right places. This drives real webhook payloads through
 * process_webhook_callback() and asserts what actually happens to the order -
 * which status it is given, and crucially which calls are never made.
 *
 * Each test declares shouldNotReceive() on the thing that must not happen, so a
 * regression fails here rather than silently advancing a held order in production.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class ManualReviewWebhookTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('do_action', array('return' => null));
        WP_Mock::userFunction('is_wp_error', array('return' => false));
        WP_Mock::userFunction('current_time', array('return' => '2026-08-19 12:00:00'));
        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array(
                'merchant_id' => 'mid',
                'shared_secret' => 'secret',
                'testmode' => 'yes',
                // Keep the commit-hook fallback out of these tests; it has its own.
                'checkout_hooks_enabled' => 'no',
            ),
        ));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * An order mock with the incidental calls allowed, so each test only has to
     * declare the expectations it actually cares about.
     *
     * @param bool $on_hold What has_status('on-hold') reports.
     * @param int  $id      Order id.
     * @return \Mockery\MockInterface
     */
    private function mockOrder($on_hold, $id = 700)
    {
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn($id);
        $order->shouldReceive('get_status')->andReturn($on_hold ? 'on-hold' : 'pending');

        // One handler for every has_status() shape the webhook code uses. It must
        // answer the ARRAY forms honestly too, not just has_status('on-hold'):
        // handle_capture_status() gates its payment_complete() behind
        // has_status(array('pending','failed','on-hold','checkout-draft')), so a
        // blanket false made the capture test pass without ever reaching the guard
        // it was supposed to be testing.
        $order->shouldReceive('has_status')->andReturnUsing(function ($statuses) use ($on_hold) {
            return in_array('on-hold', (array) $statuses, true) ? $on_hold : false;
        });
        $order->shouldReceive('get_total')->andReturn(100.0);
        $order->shouldReceive('get_currency')->andReturn('SEK');
        $order->shouldReceive('get_meta')->andReturn('');
        $order->shouldReceive('update_meta_data')->andReturn(null);
        $order->shouldReceive('set_payment_method_title')->andReturn(null);
        $order->shouldReceive('save')->andReturn(null);
        $order->shouldReceive('add_order_note')->andReturn(null);

        WP_Mock::userFunction('wc_get_orders', array('return' => array($order)));

        return $order;
    }

    /**
     * @param array $extra Merged into the session payload.
     * @return array
     */
    private function session(array $extra = array())
    {
        return array_merge(array(
            'sessionId' => 'sess_mr',
            'status' => 'completed',
            'order' => array('status' => 'completed'),
            'paymentMethod' => array('name' => 'TestPSP'),
            'data' => array(
                'billing' => array(),
                'shipping' => array(),
                'order' => array('amountIncVat' => 10000, 'currency' => 'SEK'),
                'transactions' => array(array('status' => 'approved')),
            ),
        ), $extra);
    }

    private function mockApi(array $session)
    {
        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn($session);
        return $api;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Control case - the guards must not block a normal purchase
    // ──────────────────────────────────────────────────────────────────────

    public function testAnUnflaggedOrderStillCompletesNormally(): void
    {
        $order = $this->mockOrder(false);
        $order->shouldReceive('payment_complete')->once();
        $order->shouldNotReceive('update_status');

        $this->mockApi($this->session());

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rule 1 - manual_review holds instead of completing
    // ──────────────────────────────────────────────────────────────────────

    public function testCompletedSessionFlaggedForReviewIsHeldNotCompleted(): void
    {
        $order = $this->mockOrder(false);

        $order->shouldReceive('update_status')
            ->with('on-hold', Mockery::any())
            ->once();
        $order->shouldNotReceive('payment_complete');

        $this->mockApi($this->session(array(
            'data' => array(
                'billing' => array(),
                'shipping' => array(),
                'order' => array('amountIncVat' => 10000, 'currency' => 'SEK'),
                'transactions' => array(array('status' => 'approved')),
                'paymentTags' => array('manual_review' => true),
            ),
        )));

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }

    /**
     * The list form of the tag bag must behave identically.
     */
    public function testListFormTagAlsoHolds(): void
    {
        $order = $this->mockOrder(false);

        $order->shouldReceive('update_status')->with('on-hold', Mockery::any())->once();
        $order->shouldNotReceive('payment_complete');

        $this->mockApi($this->session(array(
            'data' => array(
                'billing' => array(),
                'shipping' => array(),
                'order' => array('amountIncVat' => 10000, 'currency' => 'SEK'),
                'paymentTags' => array('manual_review'),
            ),
        )));

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }

    /**
     * Already held AND flagged: nothing to do. Re-applying on-hold would spam the
     * order notes on every redelivered webhook.
     */
    public function testAFlaggedOrderAlreadyOnHoldIsLeftUntouched(): void
    {
        $order = $this->mockOrder(true);

        $order->shouldNotReceive('update_status');
        $order->shouldNotReceive('payment_complete');

        $this->mockApi($this->session(array(
            'data' => array(
                'billing' => array(),
                'shipping' => array(),
                'order' => array('amountIncVat' => 10000, 'currency' => 'SEK'),
                'paymentTags' => array('manual_review' => true),
            ),
        )));

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }

    public function testApprovedStatusWebhookFlaggedForReviewIsHeldNotProcessing(): void
    {
        $order = $this->mockOrder(false);

        $order->shouldReceive('update_status')
            ->with('on-hold', Mockery::any())
            ->once();
        // The whole point: it must not be promoted to processing.
        $order->shouldNotReceive('update_status')->with('processing', Mockery::any());

        $this->mockApi($this->session());

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'order_status',
            'status' => 'order_approved_not_captured',
            'paymentTags' => array('manual_review' => true),
        ));

        $this->assertTrue(true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rule 2 - an on-hold order is left alone
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The regression this closes: the status hierarchy ranks on-hold (2) below
     * processing (3), so an approval used to promote a held order straight past the
     * merchant's hold. The leasing case depends on this staying held.
     */
    public function testApprovedStatusWebhookDoesNotAdvanceAHeldOrder(): void
    {
        $order = $this->mockOrder(true);

        $order->shouldNotReceive('update_status');
        $order->shouldNotReceive('payment_complete');

        $this->mockApi($this->session());

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'order_status',
            'status' => 'order_approved_not_captured',
        ));

        $this->assertTrue(true);
    }

    public function testCompletedSessionDoesNotCompleteAHeldOrder(): void
    {
        $order = $this->mockOrder(true);

        $order->shouldNotReceive('payment_complete');
        $order->shouldNotReceive('update_status');

        $this->mockApi($this->session());

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }

    /**
     * A held order must not be cancelled out from under the merchant either - they
     * may be holding it precisely because they are resolving something with the
     * customer.
     */
    public function testRejectedStatusWebhookDoesNotCancelAHeldOrder(): void
    {
        $order = $this->mockOrder(true);

        $order->shouldNotReceive('update_status');

        $this->mockApi($this->session(array('status' => 'rejected')));

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'order_status',
            'status' => 'order_rejected',
        ));

        $this->assertTrue(true);
    }

    /**
     * The session must carry a matching capture entry, or handle_capture_status()
     * computes an amount of 0, trips its own amount-mismatch guard and returns
     * before it ever reaches the hold check - which made an earlier version of this
     * test pass without exercising anything. Verified by mutation: disabling the
     * hold guard now fails this test.
     */
    public function testCaptureWebhookDoesNotRecordPaymentOnAHeldOrder(): void
    {
        $order = $this->mockOrder(true);

        $order->shouldNotReceive('payment_complete');

        $this->mockApi($this->session(array(
            'captures' => array(
                array(
                    'captureId' => 'cap_held',
                    // handle_capture_status() reads the status from the SESSION's
                    // capture entry, not the webhook body - without it the whole
                    // approved block is skipped.
                    'status' => 'approved',
                    'amountIncVat' => 10000,
                    'currency' => 'SEK',
                    'cart' => array(),
                ),
            ),
        )));

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'event' => 'capture_status',
            'status' => 'approved',
            'captureId' => 'cap_held',
            'capture' => array('amountIncVat' => 10000, 'cart' => array()),
        ));

        $this->assertTrue(true);
    }

    /**
     * The escape hatch has to actually work, or a merchant who needs the old
     * behaviour is stuck.
     */
    public function testTheFilterRestoresTheOldAutoAdvanceBehaviour(): void
    {
        $order = $this->mockOrder(true);

        WP_Mock::onFilter('briqpay_respect_on_hold_status')
            ->with(true, $order)
            ->reply(false);

        $order->shouldReceive('payment_complete')->once();

        $this->mockApi($this->session());

        (new Webhooks())->process_webhook_callback(array(
            'sessionId' => 'sess_mr',
            'action' => 'session',
        ));

        $this->assertTrue(true);
    }
}
