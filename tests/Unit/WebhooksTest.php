<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class WebhooksTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testProcessWebhookCallbackRouting()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        // Mock wc_get_orders
        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        // Mock Order expectations for Capture
        $order->shouldReceive('get_meta')->with('_briqpay_captures')->andReturn(array());
        $order->shouldReceive('add_order_note')->atLeast()->once();
        $order->shouldReceive('update_meta_data')->atLeast()->once();
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('save')->atLeast()->once();

        WP_Mock::userFunction('current_time', array('return' => '2026-02-12 12:00:00'));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        // Test Capture Routing - Event
        $data_capture_event = array(
            'sessionId' => 'sess_123',
            'event' => 'capture_status',
            'status' => 'approved',
            'captureId' => 'cap_1',
            'capture' => array('amountIncVat' => 1000, 'cart' => array())
        );

        $webhooks->process_webhook_callback($data_capture_event);
    }

    public function testHandleCaptureStatusHistoryPopulation()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_meta')->with('_briqpay_captures')->andReturn(array());
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_captures', array('cap_123'))->once();

        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('update_meta_data')->with('_briqpay_capture_history', Mockery::on(function ($history) {
            return count($history) === 1 && $history[0]['captureId'] === 'cap_123' && $history[0]['amount'] === 1000;
        }))->once();

        $order->shouldReceive('save')->once();

        WP_Mock::userFunction('current_time', array('return' => '2026-02-12 12:00:00'));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        $reflection = new \ReflectionClass(Webhooks::class);
        $method = $reflection->getMethod('handle_capture_status');
        $method->setAccessible(true);

        $data = array(
            'captureId' => 'cap_123',
            'capture' => array(
                'amountIncVat' => 1000,
                'cart' => array()
            )
        );

        $method->invoke($webhooks, $order, 'approved', $data);
    }

    /**
     * Test that completed sessions call payment_complete() for WooCommerce Analytics integration.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCompletedSessionCallsPaymentComplete()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        // Mock wc_get_orders to find our order
        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        // Mock get_option for API settings
        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array(
                'merchant_id' => 'test_mid',
                'shared_secret' => 'test_secret',
                'testmode' => 'yes',
            )
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('do_action', array('return' => null));

        // Mock API to return a completed session
        $session_data = array(
            'sessionId' => 'sess_completed_123',
            'status' => 'completed',
            'order' => array('status' => 'completed'),
            'paymentMethod' => array('name' => 'TestPSP'),
            'data' => array(
                'billing' => array(),
                'shipping' => array(),
            ),
        );

        // Mock the API class
        $api_mock = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api_mock->shouldReceive('get_session')
            ->with('sess_completed_123')
            ->andReturn($session_data);

        // Order expectations
        $order->shouldReceive('has_status')
            ->with('processing')
            ->andReturn(false);
        $order->shouldReceive('has_status')
            ->with(Mockery::on(function ($arg) {
                return is_array($arg) ? in_array('processing', $arg) : $arg === 'processing';
            }))
            ->andReturn(false);
        $order->shouldReceive('has_status')
            ->with('completed')
            ->andReturn(false);
        $order->shouldReceive('has_status')
            ->with(Mockery::on(function ($arg) {
                return is_array($arg) ? in_array('completed', $arg) : $arg === 'completed';
            }))
            ->andReturn(false);

        // Key assertion: payment_complete() MUST be called, not update_status()
        $order->shouldReceive('payment_complete')
            ->with('sess_completed_123')
            ->once();

        // update_status should NOT be called for completed
        $order->shouldNotReceive('update_status');

        // Allow supporting calls
        $order->shouldReceive('get_meta')->andReturn('');
        $order->shouldReceive('set_payment_method_title')->andReturn(null);
        $order->shouldReceive('set_created_via')->andReturn(null);
        $order->shouldReceive('update_meta_data')->andReturn(null);
        $order->shouldReceive('save')->andReturn(null);
        $order->shouldReceive('add_order_note')->andReturn(null);
        $order->shouldReceive('get_id')->andReturn(999);

        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $data = array(
            'sessionId' => 'sess_completed_123',
            'action' => 'session',
        );

        $webhooks->process_webhook_callback($data);
    }
}
