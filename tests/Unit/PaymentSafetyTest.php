<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Gateway;
use Briqpay\WooCommerce\Order_Management;
use Briqpay\WooCommerce\Order_Status_Manager;
use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Transaction-level payment verification, and the capture/refund defects a PR
 * review surfaced.
 *
 * The unifying point: a Briqpay session status of 'completed' means the customer
 * finished the checkout, not that the money is secured. Three call sites acted on
 * that status; only one checked the transaction underneath.
 */
class PaymentSafetyTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Options::reset();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
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
    // transaction_approval_state()
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider approvedStatusProvider
     */
    public function testApprovedStatusesReportApproved($status): void
    {
        $this->assertSame('approved', Order_Management::transaction_approval_state(array(
            'status' => 'completed',
            'data' => array('transactions' => array(array('status' => $status))),
        )));
    }

    public function approvedStatusProvider()
    {
        return array(
            array('approved'),
            array('approved_not_captured'),
            array('order_approved_not_captured'),
            array('captured'),
            array('completed'),
        );
    }

    /**
     * @dataProvider unapprovedStatusProvider
     */
    public function testUnapprovedStatusesReportUnapproved($status): void
    {
        $this->assertSame('unapproved', Order_Management::transaction_approval_state(array(
            'status' => 'completed',
            'data' => array('transactions' => array(array('status' => $status))),
        )));
    }

    public function unapprovedStatusProvider()
    {
        return array(
            array('pending'),
            array('rejected'),
            array('failed'),
            array('cancelled'),
            // An allowlist, so a state Briqpay adds later is never read as paid.
            array('some_future_briqpay_state'),
        );
    }

    /**
     * The distinction the whole design rests on: no transactions is NOT a
     * negative. Sites that already mark orders paid must keep doing so here, or a
     * flow whose payload omits transactions would stop completing.
     */
    public function testNoTransactionsReportsUnknownNotUnapproved(): void
    {
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array(
            'status' => 'completed',
        )));
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array(
            'status' => 'completed',
            'data' => array('transactions' => array()),
        )));
    }

    public function testTransactionsWithNoRecognisedStatusFieldReportUnknown(): void
    {
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array(
            'data' => array('transactions' => array(array('amount' => 100))),
        )));
    }

    public function testOneApprovedAmongSeveralIsEnough(): void
    {
        $this->assertSame('approved', Order_Management::transaction_approval_state(array(
            'data' => array('transactions' => array(
                array('status' => 'rejected'),
                array('status' => 'captured'),
            )),
        )));
    }

    public function testAlternateStatusKeysAreRead(): void
    {
        foreach (array('state', 'transactionStatus') as $key) {
            $this->assertSame(
                'approved',
                Order_Management::transaction_approval_state(array(
                    'data' => array('transactions' => array(array($key => 'approved'))),
                )),
                'Briqpay has used more than one key name for this.'
            );
        }
    }

    public function testMalformedPayloadsDoNotFatal(): void
    {
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array()));
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array(
            'data' => array('transactions' => 'corrupt'),
        )));
        $this->assertSame('unknown', Order_Management::transaction_approval_state(array(
            'data' => array('transactions' => array('corrupt', 42)),
        )));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The three call sites, and their different strictness
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The janitor is a recovery path: no signal means take no action.
     */
    public function testJanitorActsOnlyOnExplicitApproval(): void
    {
        $source = $this->methodSource(Order_Status_Manager::class, 'janitor_cleanup_task');

        $this->assertStringContainsString(
            "'approved' === Order_Management::transaction_approval_state(\$session)",
            $source,
            'The janitor must require approval, not merely the absence of a refusal.'
        );
    }

    /**
     * The webhook already marks orders paid today, so it must block only on a
     * known-bad transaction. Requiring approval here would be a regression for any
     * flow whose payload omits transactions.
     */
    public function testWebhookBlocksOnlyOnKnownBadTransactions(): void
    {
        $source = $this->methodSource(Webhooks::class, 'process_webhook_callback');

        $this->assertStringContainsString(
            "'unapproved' === Order_Management::transaction_approval_state(\$session)",
            $source,
            'The webhook must block on unapproved, and still proceed on unknown.'
        );

        $check_pos = strpos($source, 'transaction_approval_state');
        $complete_pos = strpos($source, 'payment_complete($session_id)');

        $this->assertNotFalse($check_pos);
        $this->assertNotFalse($complete_pos);
        $this->assertLessThan(
            $complete_pos,
            $check_pos,
            'The check must precede recording payment.'
        );
    }

    public function testGatewayPaidCheckBlocksOnKnownBadTransactions(): void
    {
        $source = $this->methodSource(Gateway::class, 'session_is_paid_for_order');

        $this->assertStringContainsString(
            "'unapproved' === Order_Management::transaction_approval_state(\$session)",
            $source
        );

        $check_pos = strpos($source, 'transaction_approval_state');
        $true_pos = strpos($source, 'return true;');

        $this->assertLessThan(
            $true_pos,
            $check_pos,
            'A status alone must never short-circuit past the transaction check.'
        );
    }

    /**
     * One definition, three consumers - the duplicate that started in
     * Order_Status_Manager is gone.
     */
    public function testApprovalLogicIsNotDuplicated(): void
    {
        $osm = file_get_contents((new \ReflectionClass(Order_Status_Manager::class))->getFileName());

        $this->assertStringNotContainsString(
            'function session_has_approved_transaction',
            $osm,
            'The janitor must use the shared helper, not its own copy.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Capture retry scheduling - the blocker
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The defect: capture_order_locked() referenced $order_id, which only the
     * outer capture_order() had. Every failed capture scheduled its retry with
     * array(null), so wc_get_order(0) returned false and no retry ever ran.
     */
    public function testCaptureRetryIsScheduledWithARealOrderId(): void
    {
        $ref = new \ReflectionMethod(Order_Management::class, 'capture_order_locked');

        $params = array();
        foreach ($ref->getParameters() as $param) {
            $params[] = $param->getName();
        }

        $this->assertContains(
            'order_id',
            $params,
            'The order id must be a parameter, not inherited from the caller scope.'
        );

        $source = $this->methodSource(Order_Management::class, 'capture_order_locked');
        $this->assertStringContainsString(
            "as_schedule_single_action(time() + 300, 'briqpay_retry_capture', array(\$order_id), 'briqpay')",
            $source
        );

        $caller = $this->methodSource(Order_Management::class, 'capture_order');
        $this->assertStringContainsString(
            '$this->capture_order_locked($order, $session_id, $order_id)',
            $caller,
            'The caller must pass it through.'
        );
    }

    public function testCaptureRetryRespectsTheThreeAttemptCap(): void
    {
        $source = $this->methodSource(Order_Management::class, 'capture_order_locked');

        $this->assertStringContainsString("get_meta('_briqpay_capture_retry_count')", $source);
        $this->assertStringContainsString('$retry_count < 3', $source);
        $this->assertStringContainsString('exhausted all 3 retries', $source);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Refund reason - the should-fix
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The defect: execute_single_refund() built the adjustment name from $reason,
     * which was never in its signature - so every amount-only refund silently fell
     * back to the generic label and dropped the merchant's text.
     */
    public function testRefundReasonReachesTheAdjustmentLine(): void
    {
        $ref = new \ReflectionMethod(Order_Management::class, 'execute_single_refund');

        $params = array();
        foreach ($ref->getParameters() as $param) {
            $params[] = $param->getName();
        }

        $this->assertContains('reason', $params, '$reason must be a declared parameter.');

        $source = $this->methodSource(Order_Management::class, 'execute_single_refund');
        $this->assertStringContainsString("'reference' => 'refund-adjustment'", $source);
        $this->assertStringContainsString('$reason', $source);
    }

    public function testEveryRefundCallSitePassesTheReason(): void
    {
        $class_source = file_get_contents((new \ReflectionClass(Order_Management::class))->getFileName());

        preg_match_all('/execute_single_refund\(([^;]*?)\);/s', $class_source, $matches);

        $this->assertNotEmpty($matches[1], 'Sanity: call sites found.');

        foreach ($matches[1] as $args) {
            $this->assertStringContainsString(
                '$reason',
                $args,
                'Every call site must thread the reason through: ' . trim($args)
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Manual capture locking - the should-fix
    // ──────────────────────────────────────────────────────────────────────

    /**
     * capture_order() was locked but manual_capture() - which serves the admin
     * AJAX endpoint - was not, so a double-click could run two captures in
     * parallel against the same capture history.
     */
    public function testManualCaptureTakesTheSameLockAsAutomaticCapture(): void
    {
        $manual = $this->methodSource(Order_Management::class, 'manual_capture');
        $automatic = $this->methodSource(Order_Management::class, 'capture_order');

        $this->assertStringContainsString("Lock::acquire(\$lock, 120)", $manual);
        $this->assertStringContainsString("'briqpay_capture_'", $manual);
        $this->assertStringContainsString("'briqpay_capture_'", $automatic);

        $this->assertStringContainsString(
            'Lock::release($lock)',
            $manual,
            'And release it, in a finally.'
        );
        $this->assertStringContainsString('} finally {', $manual);
    }

    public function testManualCaptureRefusesWhenTheLockIsHeld(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(4242);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_1');

        // Simulate an in-flight capture for the same order.
        \Briqpay\WooCommerce\Lock::acquire('briqpay_capture_4242', 120);

        $result = $this->enabledOrderManagement()->manual_capture($order, array());

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame(
            'capture_in_progress',
            $result->get_error_code(),
            'A second concurrent capture must be refused rather than run in parallel.'
        );
    }

    public function testManualCaptureProceedsPastTheLockWhenFree(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(4243);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('');

        // No lock held. The no-session guard inside the locked body is the next
        // thing to fire, which proves the lock let us through.
        $result = $this->enabledOrderManagement()->manual_capture($order, array());

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('no_session', $result->get_error_code());
    }

    /**
     * The lock must not stay held after a refusal deeper in the body, or one
     * failed capture would block the order for the full TTL.
     */
    public function testTheLockIsReleasedEvenWhenTheBodyReturnsEarly(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(4244);
        // No session, so the guard inside the locked body returns early - the
        // scenario that would leave a lock held without a finally.
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('');

        $this->enabledOrderManagement()->manual_capture($order, array());

        $this->assertFalse(
            \Briqpay\WooCommerce\Lock::is_held('briqpay_capture_4244'),
            'The finally block must release the lock.'
        );
    }

    /**
     * Order_Management::is_enabled() reads a setting the test bootstrap's fixed
     * get_option() does not include, and it is protected - so override it rather
     * than short-circuiting every capture test on 'disabled'.
     */
    private function enabledOrderManagement()
    {
        return new class extends Order_Management {
            protected function is_enabled()
            {
                return true;
            }
        };
    }

    public function testManualCaptureBodyRunsUnderTheLock(): void
    {
        $source = $this->methodSource(Order_Management::class, 'manual_capture');

        $lock_pos = strpos($source, 'Lock::acquire');
        $body_pos = strpos($source, 'manual_capture_locked');

        $this->assertNotFalse($lock_pos);
        $this->assertNotFalse($body_pos);
        $this->assertLessThan($body_pos, $lock_pos, 'The lock must be taken first.');
    }
}
