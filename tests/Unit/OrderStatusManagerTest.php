<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Status_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class OrderStatusManagerTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('is_wp_error', array(
            'return' => false
        ));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testJanitorCleanupTask()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(456);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_123');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('update_status')->with('cancelled', Mockery::any())->once();

        // Mock wc_get_orders
        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        // Mock settings
        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        // Mock API overload
        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->with('sess_123')->andReturn(array('status' => 'expired'));

        WP_Mock::userFunction('__', array('return_arg' => 0));

        $osm->janitor_cleanup_task();
    }

    /**
     * A completed session WITH an approved transaction is a real payment, so the
     * janitor records it through payment_complete() - which sets date_paid,
     * reduces stock and fires WooCommerce's payment hooks - rather than nudging
     * the status directly.
     */
    public function testJanitorRecordsPaymentWhenATransactionIsApproved()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(789);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_rec');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('payment_complete')->with('sess_rec')->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('update_status')->never();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array(
            'status' => 'completed',
            'data' => array(
                'transactions' => array(
                    array('status' => 'approved_not_captured'),
                ),
            ),
        ));

        $osm->janitor_cleanup_task();
    }

    /**
     * The bug this replaces: a 'completed' session only means the customer
     * finished the checkout. The transaction underneath can still be pending or
     * rejected, and the janitor used to mark such orders 'processing' - reporting
     * unpaid orders as paid. It must now leave them for the webhook to resolve.
     */
    public function testJanitorDoesNotRecordPaymentWhenNoTransactionIsApproved()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(790);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_pending_tx');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('payment_complete')->never();
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('add_order_note')->never();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array(
            'status' => 'completed',
            'data' => array(
                'transactions' => array(
                    array('status' => 'pending'),
                ),
            ),
        ));

        $osm->janitor_cleanup_task();
    }

    /**
     * No transactions at all on a completed session is also not payment.
     */
    public function testJanitorDoesNotRecordPaymentWithoutAnyTransactions()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(791);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_no_tx');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('payment_complete')->never();
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('add_order_note')->never();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array('status' => 'completed'));

        $osm->janitor_cleanup_task();
    }

    /**
     * An unrecognized transaction status must read as not approved, so a new
     * Briqpay state can never be mistaken for secured payment.
     */
    public function testJanitorTreatsUnknownTransactionStatusAsUnapproved()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(792);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_future');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('payment_complete')->never();
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('add_order_note')->never();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array(
            'status' => 'completed',
            'data' => array(
                'transactions' => array(
                    array('status' => 'some_new_briqpay_state'),
                ),
            ),
        ));

        $osm->janitor_cleanup_task();
    }

    /**
     * A hosted payment page link a merchant emailed to a customer may sit
     * unopened for hours - the Briqpay session stays 'pending' (not one of the
     * cancellable terminal states) the whole time. The janitor must leave that
     * order alone rather than cancelling it out from under the customer.
     */
    public function testJanitorDoesNotCancelAnHostedPageOrderWithALiveSession()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(999);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_hpp_1');
        // Not held by the merchant, so the janitor is free to act.
        $order->shouldReceive('has_status')->with('on-hold')->andReturn(false);
        $order->shouldReceive('update_status')->never();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->with('sess_hpp_1')->andReturn(array('status' => 'pending'));

        WP_Mock::userFunction('__', array('return_arg' => 0));

        $osm->janitor_cleanup_task();
    }
}
