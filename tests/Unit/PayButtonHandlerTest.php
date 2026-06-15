<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Pay_Button_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class PayButtonHandlerTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /**
     * @var Pay_Button_Handler
     */
    private $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $this->handler = new Pay_Button_Handler();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // is_briqpay_order_awaiting_webhook()
    // -----------------------------------------------------------------------

    public function testIsBriqpayOrderAwaitingWebhookReturnsTrueForPendingBriqpayOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_123');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);

        $this->assertTrue($this->handler->is_briqpay_order_awaiting_webhook($order));
    }

    public function testIsBriqpayOrderAwaitingWebhookReturnsFalseForNonBriqpayOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('stripe');

        $this->assertFalse($this->handler->is_briqpay_order_awaiting_webhook($order));
    }

    public function testIsBriqpayOrderAwaitingWebhookReturnsFalseWithoutSessionId()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('');

        $this->assertFalse($this->handler->is_briqpay_order_awaiting_webhook($order));
    }

    public function testIsBriqpayOrderAwaitingWebhookReturnsFalseForProcessingOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_123');
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);

        $this->assertFalse($this->handler->is_briqpay_order_awaiting_webhook($order));
    }

    public function testIsBriqpayOrderAwaitingWebhookReturnsFalseForCompletedOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_123');
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);

        $this->assertFalse($this->handler->is_briqpay_order_awaiting_webhook($order));
    }

    // -----------------------------------------------------------------------
    // filter_order_needs_payment()
    // -----------------------------------------------------------------------

    public function testFilterOrderNeedsPaymentReturnsFalseForBriqpayPendingOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_456');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);

        $result = $this->handler->filter_order_needs_payment(true, $order, array('pending', 'failed'));

        $this->assertFalse($result);
    }

    public function testFilterOrderNeedsPaymentPreservesNonBriqpayOrders()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('paypal');

        $result = $this->handler->filter_order_needs_payment(true, $order, array('pending', 'failed'));

        $this->assertTrue($result);
    }

    public function testFilterOrderNeedsPaymentPreservesFalseResult()
    {
        $order = Mockery::mock('WC_Order');

        // If WooCommerce already says it doesn't need payment, we should return false immediately
        $result = $this->handler->filter_order_needs_payment(false, $order, array('pending', 'failed'));

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // filter_my_orders_actions()
    // -----------------------------------------------------------------------

    public function testFilterMyOrdersActionsRemovesPayForBriqpayPendingOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_789');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);

        $actions = array(
            'pay' => array(
                'url' => 'https://example.com/checkout/order-pay/123/',
                'name' => 'Pay',
            ),
            'view' => array(
                'url' => 'https://example.com/my-account/view-order/123/',
                'name' => 'View',
            ),
            'cancel' => array(
                'url' => 'https://example.com/my-account/cancel-order/123/',
                'name' => 'Cancel',
            ),
        );

        $result = $this->handler->filter_my_orders_actions($actions, $order);

        $this->assertArrayNotHasKey('pay', $result);
        $this->assertArrayHasKey('view', $result);
        $this->assertArrayHasKey('cancel', $result);
    }

    public function testFilterMyOrdersActionsKeepsPayForNonBriqpayOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('stripe');

        $actions = array(
            'pay' => array(
                'url' => 'https://example.com/checkout/order-pay/123/',
                'name' => 'Pay',
            ),
            'view' => array(
                'url' => 'https://example.com/my-account/view-order/123/',
                'name' => 'View',
            ),
        );

        $result = $this->handler->filter_my_orders_actions($actions, $order);

        $this->assertArrayHasKey('pay', $result);
    }

    public function testFilterMyOrdersActionsKeepsPayWhenNoPayActionExists()
    {
        $order = Mockery::mock('WC_Order');
        // No pay action in the array, so is_briqpay_order_awaiting_webhook should not be called
        $actions = array(
            'view' => array(
                'url' => 'https://example.com/my-account/view-order/123/',
                'name' => 'View',
            ),
        );

        $result = $this->handler->filter_my_orders_actions($actions, $order);

        $this->assertArrayHasKey('view', $result);
        $this->assertArrayNotHasKey('pay', $result);
    }

    public function testFilterMyOrdersActionsKeepsPayForProcessingBriqpayOrder()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_payment_method')->andReturn('briqpay');
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_789');
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);

        $actions = array(
            'pay' => array(
                'url' => 'https://example.com/checkout/order-pay/123/',
                'name' => 'Pay',
            ),
        );

        $result = $this->handler->filter_my_orders_actions($actions, $order);

        // Processing orders should keep the pay button (they shouldn't have one anyway,
        // but we don't interfere with non-pending statuses)
        $this->assertArrayHasKey('pay', $result);
    }
}
