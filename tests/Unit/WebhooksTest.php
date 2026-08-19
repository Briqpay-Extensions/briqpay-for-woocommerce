<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
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

        // Mock get_option for API settings
        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array(
                'merchant_id' => 'test_mid',
                'shared_secret' => 'test_secret',
                'testmode' => 'yes',
            )
        ));

        $session_data = array(
            'sessionId' => 'sess_123',
            'status' => 'completed',
            'captures' => array(
                array(
                    'captureId' => 'cap_1',
                    'status' => 'approved',
                    'amountIncVat' => 1000,
                    'cart' => array()
                )
            )
        );

        $api_mock = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api_mock->shouldReceive('get_session')
            ->with('sess_123')
            ->andReturn($session_data);

        // Mock Order expectations for Capture
        $order->shouldReceive('get_meta')->with('_briqpay_captures')->andReturn(array());
        $order->shouldReceive('add_order_note')->atLeast()->once();
        $order->shouldReceive('update_meta_data')->atLeast()->once();
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('save')->atLeast()->once();
        $order->shouldReceive('has_status')->andReturn(false);

        WP_Mock::userFunction('current_time', array('return' => '2026-02-12 12:00:00'));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('is_wp_error', array('return' => false));

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
        $order->shouldReceive('has_status')->andReturn(false);

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

        $session_data = array(
            'sessionId' => 'sess_123',
            'captures' => array(
                array(
                    'captureId' => 'cap_123',
                    'status' => 'approved',
                    'amountIncVat' => 1000,
                    'cart' => array()
                )
            )
        );

        $method->invoke($webhooks, $order, 'approved', $data, $session_data);
    }

    /**
     * Test that completed sessions call payment_complete() for WooCommerce Analytics integration.
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
                'order' => array(
                    'amountIncVat' => 10000,
                    'currency' => 'SEK'
                )
            ),
        );

        // Mock the API class
        $api_mock = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api_mock->shouldReceive('get_session')
            ->with('sess_completed_123')
            ->andReturn($session_data);

        // Order expectations
        // Not on hold, so the manual-review and merchant-hold guards let the
        // completion through. Held orders are covered in ManualReviewHoldTest.
        $order->shouldReceive('has_status')
            ->with('on-hold')
            ->andReturn(false);
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

        // Total/currency validation expectations
        $order->shouldReceive('get_total')->andReturn(100.0);
        $order->shouldReceive('get_currency')->andReturn('SEK');

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

    public function testOrderStatusWebhookRouting()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        // Mock wc_get_orders to return our order
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

        // Mock API get_session
        $session_data = array(
            'sessionId' => 'sess_os_123',
            'status' => 'completed',
            'paymentMethod' => array('name' => 'TestPSP'),
            'clientToken' => 'token_abc',
            'purchaseSession' => array(
                'pspIntegrationName' => 'integration_abc',
                'reservationId' => 'res_123'
            )
        );

        $api_mock = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api_mock->shouldReceive('get_session')
            ->with('sess_os_123')
            ->andReturn($session_data);

        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('checkout-draft');
        $order->shouldReceive('update_status')
            ->with('pending', Mockery::any())
            ->once();

        $order->shouldReceive('has_status')->andReturn(false);
        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $data = array(
            'sessionId' => 'sess_os_123',
            'action' => 'order_status',
            'status' => 'order_pending'
        );

        $webhooks->process_webhook_callback($data);
    }

    public function testOrderStatusApprovedWebhookRouting()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array(
                'merchant_id' => 'test_mid',
                'shared_secret' => 'test_secret',
                'testmode' => 'yes',
                // This test covers status routing only. The commit-hook fallback
                // that the approved transition also triggers is gated off here and
                // exercised on its own in CheckoutHookFallbackTest.
                'checkout_hooks_enabled' => 'no',
            )
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        $session_data = array(
            'sessionId' => 'sess_os_123',
            'status' => 'completed',
            'paymentMethod' => array('name' => 'TestPSP'),
            'clientToken' => 'token_abc',
            'purchaseSession' => array(
                'pspIntegrationName' => 'integration_abc',
                'reservationId' => 'res_123'
            )
        );

        $api_mock = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api_mock->shouldReceive('get_session')
            ->with('sess_os_123')
            ->andReturn($session_data);

        $order->shouldReceive('get_status')->andReturn('pending');
        // Expect order to receive update_status for processing status
        $order->shouldReceive('update_status')
            ->with('processing', Mockery::any())
            ->once();

        // Also expect payment method title details to be updated in handle_order_status
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_os_123');
        $order->shouldReceive('set_payment_method_title')->with('TestPSP')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_psp_name', 'TestPSP')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_auto_capture_enabled', 'no')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_client_token', 'token_abc')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_psp_integration_name', 'integration_abc')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_reservation_id', 'res_123')->once();
        $order->shouldReceive('save')->once();

        $order->shouldReceive('has_status')->andReturn(false);
        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $data = array(
            'sessionId' => 'sess_os_123',
            'action' => 'order_status',
            'status' => 'order_approved_not_captured'
        );

        $webhooks->process_webhook_callback($data);
    }

    /**
     * When any transaction on the session has autoCaptureEnabled, the order
     * meta the admin box reads to decide whether to show the "Manual Capture"
     * button must be set to 'yes'.
     */
    public function testUpdatePaymentMethodTitleSetsAutoCaptureEnabledMetaWhenTransactionHasIt()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('update_meta_data')->andReturn(null)->byDefault();
        $order->shouldReceive('update_meta_data')->with('_briqpay_auto_capture_enabled', 'yes')->once();
        $order->shouldReceive('set_payment_method_title')->andReturn(null);
        $order->shouldReceive('save')->andReturn(null);

        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $session = array(
            'paymentMethod' => array('name' => 'Invoice'),
            'data' => array(
                'transactions' => array(
                    array('pspDisplayName' => 'Invoice', 'autoCaptureEnabled' => true),
                ),
            ),
        );

        $reflection = new \ReflectionClass(Webhooks::class);
        $method = $reflection->getMethod('update_payment_method_title');
        $method->setAccessible(true);
        $method->invoke($webhooks, $order, $session);
    }

    public function testUpdatePaymentMethodTitleSetsAutoCaptureDisabledMetaWhenNoTransactionHasIt()
    {
        $webhooks = new Webhooks();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('update_meta_data')->andReturn(null)->byDefault();
        $order->shouldReceive('update_meta_data')->with('_briqpay_auto_capture_enabled', 'no')->once();
        $order->shouldReceive('set_payment_method_title')->andReturn(null);
        $order->shouldReceive('save')->andReturn(null);

        WP_Mock::userFunction('is_wp_error', array('return' => false));

        $session = array(
            'paymentMethod' => array('name' => 'Card'),
            'data' => array(
                'transactions' => array(
                    array('pspDisplayName' => 'Card', 'autoCaptureEnabled' => false),
                ),
            ),
        );

        $reflection = new \ReflectionClass(Webhooks::class);
        $method = $reflection->getMethod('update_payment_method_title');
        $method->setAccessible(true);
        $method->invoke($webhooks, $order, $session);
    }
}
