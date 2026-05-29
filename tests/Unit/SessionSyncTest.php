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

use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class SessionSyncTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private $session_json_full;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->session_json_full = '{
            "sessionId": "25a1a919-3627-4db5-9fbd-4aece01ff4cd",
            "data": {
                "order": {
                    "amountIncVat": 33934,
                    "cart": [
                        {
                            "productType": "physical",
                            "reference": "Abc123",
                            "name": "This is a test product",
                            "quantity": 1,
                            "unitPrice": 1247,
                            "taxRate": 2500,
                            "totalAmount": 1559,
                            "totalVatAmount": 312,
                            "unitPriceIncVat": 1559
                        },
                        {
                            "productType": "physical",
                            "reference": "159",
                            "name": "Test product no ref",
                            "quantity": 1,
                            "unitPrice": 16000,
                            "taxRate": 2500,
                            "totalAmount": 20000,
                            "totalVatAmount": 4000,
                            "unitPriceIncVat": 20000
                        },
                        {
                            "productType": "shipping_fee",
                            "reference": "shipping",
                            "name": "Shipping",
                            "quantity": 1,
                            "unitPrice": 9900,
                            "taxRate": 2500,
                            "totalAmount": 12375,
                            "totalVatAmount": 2475,
                            "unitPriceIncVat": 12375
                        }
                    ]
                },
                "captures": [
                    {
                        "captureId": "remote-cap-1",
                        "createdAt": "2026-05-04T13:14:02.753Z",
                        "status": "approved",
                        "amountIncVat": 33934,
                        "cart": [
                            {"reference": "Abc123", "quantity": 1, "totalAmount": 1559},
                            {"reference": "159", "quantity": 1, "totalAmount": 20000},
                            {"reference": "shipping", "quantity": 1, "totalAmount": 12375}
                        ]
                    }
                ],
                "refunds": [
                    {
                        "refundId": "remote-ref-1",
                        "status": "approved",
                        "createdAt": "2026-05-05T07:31:15.279Z",
                        "amountIncVat": 1559,
                        "captureId": "remote-cap-1",
                        "cart": [
                            {"reference": "Abc123", "quantity": 1, "totalAmount": 1559}
                        ]
                    }
                ]
            }
        }';

        // Mock WP functions
        WP_Mock::userFunction('is_wp_error', array(
            'return' => function($thing) {
                return $thing instanceof \WP_Error;
            }
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('wp_json_encode', array(
            'return' => function($data) { return json_encode($data); }
        ));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testSyncWithBriqpaySessionUpdatesHistory()
    {
        $order = Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('25a1a919-3627-4db5-9fbd-4aece01ff4cd');
        
        // Mock missing local history
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_refund_history')->andReturn(array());
        
        // Expect updates
        $order->shouldReceive('update_meta_data')->with('_briqpay_capture_history', Mockery::on(function($history) {
            return count($history) === 1 && $history[0]['captureId'] === 'remote-cap-1';
        }))->once();
        
        $order->shouldReceive('update_meta_data')->with('_briqpay_refund_history', Mockery::on(function($history) {
            return count($history) === 1 && $history[0]['captureId'] === 'remote-cap-1';
        }))->once();
        
        $order->shouldReceive('save')->twice();


        // Mock API
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->with('25a1a919-3627-4db5-9fbd-4aece01ff4cd')->andReturn(json_decode($this->session_json_full, true));

        $order_mgmt = Mockery::mock(Order_Management::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $order_mgmt->shouldReceive('get_api')->andReturn($api);

        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('sync_with_briqpay_session');

        $session = $method->invoke($order_mgmt, $order);

        $this->assertIsArray($session);
        $this->assertEquals('25a1a919-3627-4db5-9fbd-4aece01ff4cd', $session['sessionId']);
    }

    public function testGetCanonicalSessionItem()
    {
        $session = json_decode($this->session_json_full, true);
        $order_mgmt = new Order_Management();

        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_canonical_session_item');

        // Test finding product 159
        $item = $method->invoke($order_mgmt, $session, '159');
        $this->assertNotNull($item);
        $this->assertEquals(16000, $item['unitPrice']);
        $this->assertEquals(20000, $item['unitPriceIncVat']);

        // Test finding shipping
        $shipping = $method->invoke($order_mgmt, $session, 'shipping');
        $this->assertNotNull($shipping);
        $this->assertEquals(9900, $shipping['unitPrice']);
        $this->assertEquals(12375, $shipping['unitPriceIncVat']);

        // Test non-existent
        $none = $method->invoke($order_mgmt, $session, 'INVALID');
        $this->assertNull($none);
    }

    public function testRefundFailureReturnsWPError()
    {
        $order = Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('25a1a919-3627-4db5-9fbd-4aece01ff4cd');
        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_currency')->andReturn('SEK');

        // Mock WP functions
        WP_Mock::userFunction('wc_price', array('return_arg' => 0));
        WP_Mock::userFunction('current_time', array('return' => '2026-05-05 09:45:00'));

        // Mock sync
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array());
        $order->shouldReceive('get_meta')->with('_briqpay_refund_history')->andReturn(array());
        $order->shouldReceive('update_meta_data')->byDefault();
        $order->shouldReceive('save')->byDefault();

        // Mock API returning WP_Error
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn(json_decode($this->session_json_full, true));
        
        $error = new \WP_Error('REMAINING_UNREFUNDED_QUANTITY_TOO_LOW', 'Can not refund item with reference "19" because the remaining unrefunded quantity is too low');
        $api->shouldReceive('refund_order')->andReturn($error);

        $order_mgmt = Mockery::mock(Order_Management::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $order_mgmt->shouldReceive('get_api')->andReturn($api);
        $order_mgmt->shouldReceive('log')->byDefault();

        WP_Mock::userFunction('wc_price', array('return_arg' => 0));

        // Call execute_single_refund
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('execute_single_refund');

        $result = $method->invoke($order_mgmt, $order, 'sess_123', 'cap_123', array());

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('REMAINING_UNREFUNDED_QUANTITY_TOO_LOW', $result->get_error_code());
    }

    public function testAPIRequestFailureReturnsWPError()
    {
        $GLOBALS['wp_version'] = '6.5';
        $api = new \Briqpay\WooCommerce\API('mid', 'secret', true);
        
        // Mock wp_remote_request to return a "success" response but with 400 code
        WP_Mock::userFunction('wp_remote_request', array(
            'return' => array(
                'response' => array('code' => 400),
                'body' => json_encode(array(
                    'code' => 'BAD_REQUEST',
                    'message' => 'Something went wrong'
                ))
            )
        ));
        WP_Mock::userFunction('wp_remote_retrieve_response_code', array('return' => 400));
        WP_Mock::userFunction('wp_remote_retrieve_body', array('return' => json_encode(array(
            'code' => 'BAD_REQUEST',
            'message' => 'Something went wrong'
        ))));

        $result = $api->request('POST', '/test');
        
        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('BAD_REQUEST', $result->get_error_code());
        $this->assertEquals('Something went wrong', $result->get_error_message());
    }
}
}
