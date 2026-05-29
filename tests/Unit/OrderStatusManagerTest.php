<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Order_Status_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJanitorCleanupTask()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(456);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_123');
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
        $api->shouldReceive('get_session')->with('sess_123')->andReturn(array('status' => 'pending'));

        WP_Mock::userFunction('__', array('return_arg' => 0));

        $osm->janitor_cleanup_task();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJanitorCleanupTaskRecovery()
    {
        $osm = new Order_Status_Manager();
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn(789);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess_rec');
        $order->shouldReceive('update_status')->with('processing', Mockery::any())->once();

        WP_Mock::userFunction('wc_get_orders', array(
            'return' => array($order)
        ));

        WP_Mock::userFunction('get_option', array(
            'args' => array('woocommerce_briqpay_settings'),
            'return' => array('merchant_id' => 'mid', 'shared_secret' => 'secret', 'testmode' => 'yes')
        ));

        // Use the same overloaded mock
        $api = Mockery::mock('overload:Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(array('status' => 'completed'));

        $osm->janitor_cleanup_task();
    }
}
