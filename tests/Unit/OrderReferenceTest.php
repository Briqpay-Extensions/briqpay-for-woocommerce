<?php
namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $code;
            public $message;
            public $data;
            public function __construct($code = '', $message = '', $data = '') {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }
            public function get_error_code() { return $this->code; }
            public function get_error_message() { return $this->message; }
            public function get_error_data() { return $this->data; }
        }
    }
}

namespace Briqpay\WooCommerce\Tests\Unit {

use Briqpay\WooCommerce\API;
use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * The reference the plugin stamps on a Briqpay session is the order NUMBER, not
 * the order ID - and it must be the number WooCommerce ends up showing.
 *
 * WC_Order::get_order_number() is what the merchant and the customer see on the
 * order; a sequential-order-number plugin changes it, and it falls back to the ID
 * by itself when no such plugin is installed. Some payment methods (Two) only
 * take the reference with the purchase, so it has to be right at the decision.
 */
class OrderReferenceTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('get_option', array('return' => array()));
        WP_Mock::userFunction('is_wp_error', array(
            'return' => function ($thing) {
                return $thing instanceof \WP_Error;
            },
        ));
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

    private function invoke($name, array $args)
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, $name);
        $method->setAccessible(true);

        return $method->invokeArgs(new Checkout_Handler(), $args);
    }

    /**
     * The order as the decision holds it. The test bootstrap's wc_get_order()
     * returns null for an ID, so current_order_number() falls back to this
     * object - its number is the current one in these tests. The fresh reload
     * itself is pinned by testTheNumberIsReadFromAFreshCopyOfTheOrder().
     */
    private function orders($current, $sent_meta = '')
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(432);
        $order->shouldReceive('get_order_number')->andReturn($current);
        $order->shouldReceive('get_meta')->with('_briqpay_reference1')->andReturn($sent_meta);

        return $order;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Where it is sent
    // ──────────────────────────────────────────────────────────────────────

    /**
     * SkyVerge's Sequential Order Numbers assigns the number on
     * woocommerce_checkout_update_order_meta - one of the data hooks. Sending the
     * reference before them sent the ID while WooCommerce showed the number.
     */
    public function testTheReferenceIsSentAfterTheDataHooksAndBeforeTheDecision(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_make_decision');

        $hooks = strpos($source, '$this->fire_checkout_data_hooks($order);');
        $send = strpos($source, '$this->send_order_reference($api, $session_id, $order);');
        $decision = strpos($source, "apply_filters('briqpay_decision_value'");

        $this->assertNotFalse($hooks);
        $this->assertNotFalse($send, 'The decision must send the order reference.');
        $this->assertNotFalse($decision);
        $this->assertGreaterThan($hooks, $send, 'After the hooks that may assign the order number.');
        $this->assertLessThan($decision, $send, 'Before the decision - some payment methods only take it with the purchase.');
        $this->assertSame(0, substr_count($source, 'update_metadata('), 'Only through send_order_reference(), so there is no second, earlier write.');
        $this->assertStringNotContainsString("'reference1' => (string) \$order->get_id()", $source);
    }

    public function testBothReturnHandlerExitsCheckForALaterNumber(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'handle_briqpay_return');

        $this->assertSame(2, substr_count($source, '$this->resend_order_reference_if_changed($api, $session_id, $order);'));
    }

    /**
     * Both entry points must agree, or one order carries two references.
     */
    public function testHostedPagesSendTheSameReference(): void
    {
        $source = $this->methodSource(Hosted_Payment_Page::class, 'build_session_payload');

        $this->assertStringContainsString("'reference1' => (string) \$order->get_order_number()", $source);
    }

    // ──────────────────────────────────────────────────────────────────────
    // What is sent, and what happens when it fails
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The plugin that assigns the number saves it through its own copy of the
     * order (SkyVerge does); the object the decision holds still answers with
     * the ID. Verified end to end against SkyVerge's plugin.
     */
    public function testTheNumberIsReadFromAFreshCopyOfTheOrder(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'current_order_number');

        $this->assertStringContainsString('wc_get_order($order->get_id())', $source);
        $this->assertStringContainsString('->get_order_number()', $source);
        $this->assertStringContainsString('$fresh ? $fresh : $order', $source, 'Falls back to the held order if it cannot be reloaded.');
    }

    public function testTheOrderNumberIsSentAndRecorded(): void
    {
        $order = $this->orders('1');
        $order->shouldReceive('update_meta_data')->once()->with('_briqpay_reference1', '1');
        $order->shouldReceive('save_meta_data')->once();

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->once()
            ->with('sess-1', array('references' => array('reference1' => '1')))
            ->andReturn(array());

        $this->assertTrue($this->invoke('send_order_reference', array($api, 'sess-1', $order)));
    }

    public function testAFailedUpdateIsRetriedOnce(): void
    {
        $order = $this->orders('SW-1');
        $order->shouldReceive('update_meta_data')->once()->with('_briqpay_reference1', 'SW-1');
        $order->shouldReceive('save_meta_data')->once();
        $order->shouldNotReceive('add_order_note');

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->twice()->andReturn(new \WP_Error('http', 'timeout'), array());

        $this->assertTrue($this->invoke('send_order_reference', array($api, 'sess-1', $order)));
    }

    /**
     * Never a reason to refuse the purchase: the customer has clicked buy. The
     * merchant is told on the order instead, and nothing claims it was sent.
     */
    /**
     * The customer is waiting for the decision: a timeout is not retried, so
     * the worst case stays one timeout, as before this version.
     */
    public function testATimeoutIsNotRetried(): void
    {
        $order = $this->orders('SW-1');
        $order->shouldReceive('add_order_note')->once();
        $order->shouldNotReceive('update_meta_data');

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->once()
            ->andReturn(new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'));

        $this->assertFalse($this->invoke('send_order_reference', array($api, 'sess-1', $order)));
    }

    public function testARefusedConnectionIsStillRetried(): void
    {
        $order = $this->orders('SW-1');
        $order->shouldReceive('update_meta_data', 'save_meta_data');
        $order->shouldNotReceive('add_order_note');

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->twice()
            ->andReturn(new \WP_Error('http_request_failed', 'cURL error 7: Failed to connect'), array());

        $this->assertTrue($this->invoke('send_order_reference', array($api, 'sess-1', $order)));
    }

    public function testAPersistentFailureIsNotedOnTheOrderAndDoesNotThrow(): void
    {
        $order = $this->orders('SW-1');
        $order->shouldReceive('add_order_note')->once()->with(Mockery::on(function ($note) {
            return strpos($note, 'SW-1') !== false && strpos($note, '503 from Briqpay') !== false;
        }));
        $order->shouldNotReceive('update_meta_data');

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->twice()->andReturn(new \WP_Error('http', '503 from Briqpay'));

        $this->assertFalse($this->invoke('send_order_reference', array($api, 'sess-1', $order)));
    }

    // ──────────────────────────────────────────────────────────────────────
    // After the purchase
    // ──────────────────────────────────────────────────────────────────────

    public function testNothingIsResentWhenTheNumberIsUnchanged(): void
    {
        $order = $this->orders('SW-1', 'SW-1');
        $order->shouldNotReceive('add_order_note');
        $api = Mockery::mock(API::class);
        $api->shouldNotReceive('update_metadata');

        $this->invoke('resend_order_reference_if_changed', array($api, 'sess-1', $order));
        $this->addToAssertionCount(1);
    }

    /**
     * Orders from before this version, and decisions whose update failed, have
     * nothing recorded - left alone rather than guessed at.
     */
    public function testNothingIsResentWithoutARecordOfWhatWasSent(): void
    {
        $order = $this->orders('5001', '');
        $api = Mockery::mock(API::class);
        $api->shouldNotReceive('update_metadata');

        $this->invoke('resend_order_reference_if_changed', array($api, 'sess-1', $order));
        $this->addToAssertionCount(1);
    }

    /**
     * A plugin that numbers the order only once it is paid: Briqpay is updated
     * and the merchant is told that a purchase-time-only method kept the old one.
     */
    public function testALaterNumberIsResentAndNoted(): void
    {
        $order = $this->orders('1', '432');
        $order->shouldReceive('update_meta_data')->once()->with('_briqpay_reference1', '1');
        $order->shouldReceive('save_meta_data')->once();
        $order->shouldReceive('add_order_note')->once()->with(Mockery::on(function ($note) {
            return strpos($note, 'from 432 to 1') !== false && strpos($note, 'Two') !== false;
        }));

        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->once()
            ->with('sess-1', array('references' => array('reference1' => '1')))
            ->andReturn(array());

        $this->invoke('resend_order_reference_if_changed', array($api, 'sess-1', $order));
    }

    /**
     * The return URL is hit twice in the same second; only one of them may send
     * the new reference and note it.
     */
    public function testALaterNumberIsResentOnlyOnceWhenTheReturnRunsTwice(): void
    {
        $api = Mockery::mock(API::class);
        $api->shouldReceive('update_metadata')->once()->andReturn(array());

        foreach (array(1, 2) as $run) {
            $order = $this->orders('LATE-435', '435');
            $order->shouldReceive('update_meta_data', 'save_meta_data');
            $order->shouldReceive('add_order_note')->times($run === 1 ? 1 : 0);
            $this->invoke('resend_order_reference_if_changed', array($api, 'sess-1', $order));
        }
    }

    public function testTheCheckNeverThrowsIntoTheConfirmationPage(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andThrow(new \RuntimeException('db gone'));
        $api = Mockery::mock(API::class);

        $this->invoke('resend_order_reference_if_changed', array($api, 'sess-1', $order));
        $this->addToAssertionCount(1);
    }
}
}
