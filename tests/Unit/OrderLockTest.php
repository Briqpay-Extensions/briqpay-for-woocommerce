<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Lock;
use Briqpay\WooCommerce\Order_Management;
use Briqpay\WooCommerce\Order_Status_Manager;
use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * One status change per order at a time.
 *
 * A merchant saw an order flagged for manual review get two pending -> on-hold
 * transitions in the same second: the customer's return and Briqpay's webhook
 * each read the order as pending and each held it, so WooCommerce reduced stock
 * twice and sent the on-hold email twice. These tests pin the shared order lock,
 * the re-read under it, and the once-only hold.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class OrderLockTest extends TestCase
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
    // Lock::acquire_wait()
    // ──────────────────────────────────────────────────────────────────────

    public function testAcquireWaitTakesAFreeLock(): void
    {
        $this->assertTrue(Lock::acquire_wait('free', 30, 0));
        $this->assertTrue(Lock::is_held('free'));
    }

    public function testAcquireWaitGivesUpOnAHeldLock(): void
    {
        $this->assertTrue(Lock::acquire('busy', 30));
        $this->assertFalse(Lock::acquire_wait('busy', 30, 0));
    }

    public function testOrderKeyIsPerOrder(): void
    {
        $this->assertSame('briqpay_order_12', Lock::order_key(12));
        $this->assertNotSame(Lock::order_key(12), Lock::order_key(13));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Order_Management::hold_for_manual_review()
    // ──────────────────────────────────────────────────────────────────────

    public function testHoldPutsAPendingOrderOnHoldAndMarksIt(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('get_meta')->with(Order_Management::META_MANUAL_REVIEW_HELD)->andReturn('');
        $order->shouldReceive('update_meta_data')->with(Order_Management::META_MANUAL_REVIEW_HELD, Mockery::type('string'))->once();
        $order->shouldReceive('update_status')->with('on-hold', 'note')->once();

        $this->assertTrue(Order_Management::hold_for_manual_review($order, 'note'));
    }

    public function testHoldLeavesAnOrderAlreadyOnHold(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(2);
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(true);
        $order->shouldNotReceive('update_status');

        $this->assertFalse(Order_Management::hold_for_manual_review($order, 'note'));
    }

    /**
     * Held once and released by the merchant: a late webhook or the janitor must
     * not put it back.
     */
    public function testHoldIsNeverAppliedTwice(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(3);
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('get_meta')->with(Order_Management::META_MANUAL_REVIEW_HELD)->andReturn('2026-09-23 10:49:00');
        $order->shouldNotReceive('update_status');

        $this->assertFalse(Order_Management::hold_for_manual_review($order, 'note'));
    }

    /**
     * No path may put an order on hold for manual review any other way.
     */
    public function testEveryManualReviewHoldGoesThroughTheHelper(): void
    {
        $sites = array(
            array(Checkout_Handler::class, 'maybe_hold_for_manual_review'),
            array(Webhooks::class, 'process_webhook_callback'),
            array(Webhooks::class, 'handle_order_status'),
            array(Order_Status_Manager::class, 'janitor_cleanup_task'),
        );

        foreach ($sites as $site) {
            $source = $this->methodSource($site[0], $site[1]);
            $this->assertStringContainsString('Order_Management::hold_for_manual_review(', $source, $site[1]);
            $this->assertDoesNotMatchRegularExpression(
                "/update_status\\(\\s*'on-hold',\\s*__\\('Briqpay: (Order approved but )?[Ff]lagged for manual review/",
                $source,
                $site[1] . ' must not set on-hold for manual review directly.'
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Webhook
    // ──────────────────────────────────────────────────────────────────────

    private function webhookOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(500);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('get_meta')->andReturn('');
        WP_Mock::userFunction('wc_get_orders', array('return' => array($order)));
        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes', 'checkout_hooks_enabled' => 'no'),
        ));
        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array(
            'sessionId' => 'sess_lock',
            'status' => 'completed',
            'data' => array(
                'order' => array('amountIncVat' => 10000, 'currency' => 'SEK'),
                'paymentTags' => array('manual_review' => true),
            ),
        ));

        return $order;
    }

    /**
     * The customer's return holds the lock: the webhook must not touch the order,
     * and must reschedule itself rather than drop the event.
     */
    public function testWebhookBacksOffWhileTheOrderIsLocked(): void
    {
        $order = $this->webhookOrder();
        $order->shouldNotReceive('update_status');
        $order->shouldNotReceive('payment_complete');

        WP_Mock::onFilter('briqpay_order_lock_wait')->with(15, 'webhook')->reply(0);
        WP_Mock::userFunction('as_schedule_single_action', array('times' => 1));

        Lock::acquire(Lock::order_key(500), 30);

        (new Webhooks())->process_webhook_callback(array('sessionId' => 'sess_lock', 'action' => 'session'));

        $this->assertTrue(Lock::is_held(Lock::order_key(500)), 'The other holder keeps its lock.');
    }

    public function testWebhookReleasesTheLockWhenDone(): void
    {
        $order = $this->webhookOrder();
        $order->shouldReceive('has_status')->andReturn(false);
        $order->shouldReceive('get_total')->andReturn(100.0);
        $order->shouldReceive('get_currency')->andReturn('SEK');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('update_status')->with('on-hold', Mockery::any())->once();

        (new Webhooks())->process_webhook_callback(array('sessionId' => 'sess_lock', 'action' => 'session'));

        $this->assertFalse(Lock::is_held(Lock::order_key(500)));
    }

    public function testWebhookReReadsTheOrderUnderTheLock(): void
    {
        $source = $this->methodSource(Webhooks::class, 'process_webhook_callback');

        $lock_pos = strpos($source, 'Lock::acquire_wait($lock');
        $reload_pos = strpos($source, 'Order_Management::reload_order($order)');
        $route_pos = strpos($source, "do_action('briqpay_webhook_session_verified'");

        $this->assertNotFalse($lock_pos);
        $this->assertNotFalse($reload_pos);
        $this->assertLessThan($reload_pos, $lock_pos);
        $this->assertLessThan($route_pos, $reload_pos, 'Nothing may act on the order before it is re-read.');
        $this->assertStringContainsString('Lock::release($lock)', $source);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Return handler
    // ──────────────────────────────────────────────────────────────────────

    public function testReturnHandlerLocksAndReReadsBeforeDeciding(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'handle_briqpay_return');

        $lock_pos = strpos($source, 'Lock::acquire_wait($order_lock');
        $reload_pos = strpos($source, 'Order_Management::reload_order($order)');
        $first_status_pos = strpos($source, '$order->has_status(');

        $this->assertNotFalse($lock_pos);
        $this->assertLessThan($reload_pos, $lock_pos);
        $this->assertLessThan($first_status_pos, $reload_pos);
        // exit() skips finally, so the release has to be a shutdown function.
        $this->assertStringContainsString("register_shutdown_function(array(Lock::class, 'release'), \$order_lock)", $source);
    }

    /**
     * An order a webhook already held must not fall through to the branch that
     * resets it to pending.
     */
    public function testReturnHandlerKeepsAnOnHoldOrder(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'handle_briqpay_return');

        $this->assertStringContainsString(
            "if (\$order->has_status(array('pending', 'on-hold', 'processing', 'completed'))) {",
            $source
        );
        $this->assertStringContainsString(
            "if (!\$order->has_status(array('pending', 'on-hold', 'processing', 'completed'))) {",
            $source
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Janitor
    // ──────────────────────────────────────────────────────────────────────

    public function testJanitorSkipsAnOrderSomethingElseIsProcessing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(600);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_j');
        $order->shouldNotReceive('update_status');
        $order->shouldNotReceive('payment_complete');

        WP_Mock::userFunction('wc_get_orders', array('return' => array($order)));
        WP_Mock::userFunction('is_wp_error', array('return' => false));
        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array(
            'status' => 'completed',
            'data' => array('paymentTags' => array('manual_review' => true)),
        ));

        Lock::acquire(Lock::order_key(600), 30);

        (new Order_Status_Manager())->janitor_cleanup_task();

        $this->assertTrue(Lock::is_held(Lock::order_key(600)));
    }

    /**
     * Pending when listed, held by a webhook by the time the janitor has the lock.
     */
    public function testJanitorSkipsAnOrderThatIsNoLongerPending(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(601);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_j2');
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);
        $order->shouldNotReceive('update_status');

        WP_Mock::userFunction('wc_get_orders', array('return' => array($order)));
        WP_Mock::userFunction('is_wp_error', array('return' => false));
        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array('status' => 'expired'));

        (new Order_Status_Manager())->janitor_cleanup_task();

        $this->assertFalse(Lock::is_held(Lock::order_key(601)), 'The lock is released on the skip path too.');
    }
}
