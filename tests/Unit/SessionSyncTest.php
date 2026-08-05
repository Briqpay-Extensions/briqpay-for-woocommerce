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

/**
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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

    public function testGetCanonicalSessionItemPrefersAuthorizedTransactionOverDriftedOrderCart()
    {
        // Simulates a session whose top-level order.cart has drifted (e.g. re-PATCHed)
        // to a higher amount than what was actually authorized with the PSP. The
        // authorized transaction cart must win, otherwise captures/refunds can request
        // more than was ever authorized and get rejected with CAPTURE_NOT_ALLOWED.
        $session = array(
            'sessionId' => 'test-session',
            'data' => array(
                'order' => array(
                    'cart' => array(
                        array(
                            'reference' => '159',
                            'unitPrice' => 16000,
                            'unitPriceIncVat' => 20001, // drifted by 1 cent after authorization
                            'taxRate' => 2500,
                        ),
                    ),
                ),
                'transactions' => array(
                    array(
                        'cart' => array(
                            array(
                                'reference' => '159',
                                'unitPrice' => 16000,
                                'unitPriceIncVat' => 20000, // the amount actually authorized
                                'taxRate' => 2500,
                            ),
                        ),
                    ),
                ),
            ),
        );

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_canonical_session_item');
        $method->setAccessible(true);

        $item = $method->invoke($order_mgmt, $session, '159');
        $this->assertNotNull($item);
        $this->assertEquals(20000, $item['unitPriceIncVat']);
    }

    public function testApplyCanonicalTotalsUsesExactAuthorizedTotalForFullQuantity()
    {
        // Reproduces the reported bug: 2 units authorized for a combined 473.43 SEK
        // (47343 ore) do not divide evenly, so the canonical unitPriceIncVat is
        // itself rounded to 236.72 (23672 ore). Recomputing round(23672 * 2) yields
        // 47344 - one ore more than was ever authorized - and Klarna rejects the
        // capture with CAPTURE_NOT_ALLOWED. Capturing/refunding the full canonical
        // quantity must use the authorized totalAmount verbatim instead.
        $canonical = array(
            'quantity' => 2,
            'unitPrice' => 18938,
            'unitPriceIncVat' => 23672,
            'totalAmount' => 47343,
            'totalVatAmount' => 9467,
        );

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('apply_canonical_totals');
        $method->setAccessible(true);

        // Full-quantity capture: must use the exact authorized total, not
        // round(unitPriceIncVat * qty).
        $api_item = array();
        $method->invokeArgs($order_mgmt, array(&$api_item, $canonical, 2));
        $this->assertEquals(47343, $api_item['totalAmount']);
        $this->assertEquals(9467, $api_item['totalVatAmount']);

        // Partial capture of a subset of the line: no exact authorized total exists
        // for the subset, so a proportional calculation is the only option.
        $partial_item = array();
        $method->invokeArgs($order_mgmt, array(&$partial_item, $canonical, 1));
        $this->assertEquals(23672, $partial_item['totalAmount']);
    }

    public function testApplyCanonicalTotalsAcrossTwoPartialCapturesSumsToExactAuthorizedTotal()
    {
        // Reproduces the second reported bug: capturing 2 units one at a time. The
        // first partial capture has no exact total to use (nothing captured yet),
        // so it falls back to the proportional per-unit price (23672 = 236.72),
        // which is what actually got captured. The second capture must account for
        // that already-captured amount and use the true remainder (47343 - 23672 =
        // 23671), not round(unitPriceIncVat * 1) = 23672 again - which would total
        // 47344, one ore over the 47343 ever authorized, and get rejected with
        // CAPTURE_NOT_ALLOWED.
        $canonical = array(
            'quantity' => 2,
            'unitPrice' => 18938,
            'unitPriceIncVat' => 23672,
            'totalAmount' => 47343,
            'totalVatAmount' => 9467,
        );

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('apply_canonical_totals');
        $method->setAccessible(true);

        // First partial capture: nothing captured yet.
        $first_item = array();
        $method->invokeArgs($order_mgmt, array(&$first_item, $canonical, 1, array()));
        $this->assertEquals(23672, $first_item['totalAmount']);

        // Second capture: 1 unit already captured for 23672. This exhausts the
        // canonical quantity (1 + 1 >= 2), so it must use the true remainder.
        $already_captured = array('quantity' => 1, 'totalAmount' => 23672, 'totalVatAmount' => 4734);
        $second_item = array();
        $method->invokeArgs($order_mgmt, array(&$second_item, $canonical, 1, $already_captured));
        $this->assertEquals(23671, $second_item['totalAmount']);

        $this->assertEquals(47343, $first_item['totalAmount'] + $second_item['totalAmount']);
    }

    public function testGetAlreadyCapturedFromSessionSumsApprovedCaptures()
    {
        $session = array(
            'data' => array(
                'captures' => array(
                    array(
                        'status' => 'approved',
                        'cart' => array(
                            array('reference' => '4213asd', 'quantity' => 1, 'totalAmount' => 23672, 'totalVatAmount' => 4734),
                        ),
                    ),
                    array(
                        // Not yet approved - must be ignored.
                        'status' => 'pending',
                        'cart' => array(
                            array('reference' => '4213asd', 'quantity' => 1, 'totalAmount' => 23671, 'totalVatAmount' => 4734),
                        ),
                    ),
                ),
            ),
        );

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_already_captured_from_session');
        $method->setAccessible(true);

        $result = $method->invoke($order_mgmt, $session, '4213asd');

        $this->assertEquals(1, $result['quantity']);
        $this->assertEquals(23672, $result['totalAmount']);
        $this->assertEquals(4734, $result['totalVatAmount']);
    }

    public function testReconcileWcRefundAmountsCorrectsRefundTotalToMatchActualAmount()
    {
        // Reproduces the reported WooCommerce UI bug: WooCommerce's own refund
        // record was created for 236.71 (its own rounded per-unit guess), but the
        // exact-remaining-balance correction actually refunded 236.72 with Briqpay
        // to fully zero out the captured balance. WooCommerce's refund record must
        // be corrected by the +0.01 diff so "Refunded"/"Net Payment" reflect reality.
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn('');
        $product->shouldReceive('get_id')->andReturn(159);

        $refund_item = Mockery::mock('WC_Order_Item_Product');
        $refund_item->shouldReceive('get_product')->andReturn($product);
        $refund_item->shouldReceive('get_meta')->with('_briqpay_item_reference')->andReturn('4213asd');
        $refund_item->shouldReceive('get_total')->andReturn(-236.71);
        $refund_item->shouldReceive('set_total')->once()->with(-236.72);
        $refund_item->shouldReceive('save')->once();

        $refund = Mockery::mock('WC_Order_Refund');
        $refund->shouldReceive('get_items')->andReturn(array($refund_item));
        $refund->shouldReceive('get_total')->andReturn(-236.71);
        $refund->shouldReceive('set_total')->once()->with(-236.72);
        $refund->shouldReceive('save')->once();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_refunds')->andReturn(array($refund));

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('reconcile_wc_refund_amounts');
        $method->setAccessible(true);

        $method->invoke($order_mgmt, $order, array('4213asd' => 1));
    }

    public function testUnrefundedAmountBalanceAccountsForPreviousImpreciseRefund()
    {
        // Reproduces the reported refund bug: a single capture of 2 units totalling
        // 47343 ore is refunded one unit at a time. WooCommerce's own per-unit
        // refund amount (based on 473.43 / 2, displayed/rounded to 236.71) is used
        // for the first refund, leaving a true remaining balance of 47343 - 23671 =
        // 23672 for the second unit - one ore more than WooCommerce would again
        // suggest (236.71), which used to get stuck unrefunded forever.
        $capture_history = array(
            array(
                'captureId' => 'cap-1',
                'items' => array(
                    array('reference' => '4213asd', 'quantity' => 2, 'totalAmount' => 47343, 'totalVatAmount' => 9469),
                ),
            ),
        );

        // First refund already recorded: 1 unit, 23671 ore (WooCommerce's imprecise guess).
        $refund_history = array(
            array(
                'captureId' => 'cap-1',
                'items' => array(
                    array('reference' => '4213asd', 'quantity' => 1, 'totalAmount' => 23671, 'totalVatAmount' => 4735),
                ),
            ),
        );

        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('get_unrefunded_balances');
        $method->setAccessible(true);

        $balances = $method->invoke($order_mgmt, $capture_history, $refund_history);

        $this->assertEquals(1, $balances[0]['balances']['4213asd']);
        // Must be 23672 (the true remainder), not 23671 (WooCommerce's per-unit guess) -
        // this is what lets the second, exhausting refund use the exact remaining
        // amount instead of leaving 1 ore stuck forever.
        $this->assertEquals(23672, $balances[0]['amount_balances']['4213asd']);
        $this->assertEquals(4734, $balances[0]['vat_balances']['4213asd']);
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
        $method->setAccessible(true);

        $result = $method->invoke($order_mgmt, $order, 'sess_123', 'cap_123', array());

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('REMAINING_UNREFUNDED_QUANTITY_TOO_LOW', $result->get_error_code());
    }

    public function testResolveExactRefundTotalsKeepsUnitPriceIncVatConsistentWithTotalAmount()
    {
        // Reproduces the reported bug with the exact numbers from the failing
        // shipping refund: captured shipping balance is 99.41 SEK (9941 ore) incl.
        // 19.88 SEK (1988 ore) VAT, quantity 1. Overriding totalAmount/
        // totalVatAmount to this true balance while leaving unitPriceIncVat/
        // unitPrice at whatever WooCommerce's own refund line computed (e.g. 79.53
        // SEK with no VAT) produced unitPriceIncVat(7953) * quantity(1) = 7953,
        // which does not equal totalAmount(9941) - Briqpay rejected the entire
        // refund over exactly this mismatch: "unitPriceIncVat * quantity !=
        // totalAmount".
        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('resolve_exact_refund_totals');
        $method->setAccessible(true);

        $result = $method->invoke($order_mgmt, 9941, 1988, 1);

        $this->assertEquals(9941, $result['totalAmount']);
        $this->assertEquals(1988, $result['totalVatAmount']);
        $this->assertEquals(9941, $result['unitPriceIncVat']);
        $this->assertEquals(7953, $result['unitPrice']);

        // The invariant Briqpay rejects the whole refund over if it doesn't hold.
        $this->assertEquals(
            $result['totalAmount'],
            $result['unitPriceIncVat'] * 1,
            'unitPriceIncVat * quantity must equal totalAmount'
        );
    }

    public function testResolveExactRefundTotalsForMultiQuantityFullExhaustion()
    {
        // A multi-quantity line fully exhausting a captured balance that doesn't
        // divide evenly by quantity (473.43 SEK / 2 units). totalAmount must be
        // derived FROM the rounded unitPriceIncVat rather than sent verbatim
        // alongside it, or the same invariant violation reappears for
        // multi-quantity lines.
        $order_mgmt = new Order_Management();
        $reflection = new \ReflectionClass(Order_Management::class);
        $method = $reflection->getMethod('resolve_exact_refund_totals');
        $method->setAccessible(true);

        $result = $method->invoke($order_mgmt, 47343, 9467, 2);

        $this->assertEquals(
            $result['totalAmount'],
            $result['unitPriceIncVat'] * 2,
            'unitPriceIncVat * quantity must equal totalAmount even when the true balance does not divide evenly'
        );
        $this->assertEquals(
            $result['totalVatAmount'],
            ($result['unitPriceIncVat'] - $result['unitPrice']) * 2,
            'per-unit VAT, scaled by quantity, must equal totalVatAmount'
        );
    }

    public function testRefundOrderSendsInternallyConsistentShippingLineWhenExhaustingCapturedBalance()
    {
        // End-to-end reproduction of the reported bug via the public refund_order()
        // entry point: a merchant refunds only the shipping fee (cart: Test product
        // 1 at 236.71 SEK, Shipping at 99.41 SEK incl. 24.99% VAT). WooCommerce's
        // own refund line for shipping was entered as 79.53 with no VAT, which
        // doesn't match the captured shipping balance (99.41 SEK / 19.88 VAT).
        // Before the fix, the cart item actually sent to Briqpay had
        // unitPriceIncVat=7953 but totalAmount=9941, and the API rejected the
        // entire refund with INVALID_DATA. This asserts the ACTUAL payload sent to
        // the Briqpay API is internally consistent.
        $order = Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess-1');
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array(
            array(
                'captureId' => 'cap-1',
                'items' => array(
                    array('reference' => '4213asd-18937', 'quantity' => 1, 'totalAmount' => 23671, 'totalVatAmount' => 4734),
                    array('reference' => 'shipping', 'quantity' => 1, 'totalAmount' => 9941, 'totalVatAmount' => 1988),
                ),
            ),
        ));
        $order->shouldReceive('get_meta')->with('_briqpay_refund_history')->andReturn(array());
        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_currency')->andReturn('SEK');
        $order->shouldReceive('update_meta_data')->byDefault();
        $order->shouldReceive('save')->byDefault();
        $order->shouldReceive('add_order_note')->byDefault();

        // WooCommerce's own refund object: only the shipping line was selected,
        // entered as 79.53 with no VAT split - the actual production trigger.
        $shipping_refund_item = Mockery::mock('WC_Order_Item_Shipping');
        $shipping_refund_item->shouldReceive('get_total')->andReturn(-79.53);
        $shipping_refund_item->shouldReceive('get_total_tax')->andReturn(0.0);

        $refund = Mockery::mock('WC_Order_Refund');
        $refund->shouldReceive('get_items')->with()->andReturn(array());
        $refund->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $refund->shouldReceive('get_shipping_methods')->andReturn(array($shipping_refund_item));
        $order->shouldReceive('get_refunds')->andReturn(array($refund));

        $order_shipping_method = Mockery::mock('WC_Order_Item_Shipping');
        $order_shipping_method->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 19.88)));
        $order->shouldReceive('get_shipping_methods')->andReturn(array($order_shipping_method));

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(24.99);

        // tests/bootstrap.php defines a real global wc_get_order() (not a WP_Mock
        // stub): `return is_object($id) ? $id : null;`. WP_Mock::userFunction()
        // cannot override an already-defined real function, so passing the string
        // order ID '89' here would silently resolve to null instead of $order.
        // Passing $order itself works because the bootstrap shim returns objects
        // unchanged.
        WP_Mock::userFunction('wc_price', array('return_arg' => 0));
        WP_Mock::userFunction('current_time', array('return' => '2026-08-05 08:08:19'));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        $session = array(
            'sessionId' => 'sess-1',
            'data' => array(
                'order' => array('cart' => array()),
                'captures' => array(),
                'refunds' => array(),
            ),
        );

        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn($session);

        $captured_payload = null;
        $api->shouldReceive('refund_order')->once()->with('sess-1', Mockery::on(function ($payload) use (&$captured_payload) {
            $captured_payload = $payload;
            return true;
        }))->andReturn(array('refundId' => 'ref-1'));

        $order_mgmt = Mockery::mock(Order_Management::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $order_mgmt->shouldReceive('get_api')->andReturn($api);
        // tests/bootstrap.php defines a real global get_option() (not a WP_Mock
        // stub) that always returns a fixed settings array without
        // 'order_management_enabled', so WP_Mock::userFunction() cannot override it
        // here - stub the protected gate directly instead.
        $order_mgmt->shouldReceive('is_enabled')->andReturn(true);

        $result = $order_mgmt->refund_order(true, $order, 79.53, '');

        $this->assertTrue($result);
        $this->assertNotNull($captured_payload, 'The refund payload must have reached the API call');

        $shipping_line = null;
        foreach ($captured_payload['data']['order']['cart'] as $line) {
            if ('shipping' === $line['reference']) {
                $shipping_line = $line;
            }
        }
        $this->assertNotNull($shipping_line, 'Shipping line must be present in the refund payload');

        // The exact invariant Briqpay rejects the WHOLE refund over if it doesn't hold.
        $this->assertEquals(
            $shipping_line['totalAmount'],
            $shipping_line['unitPriceIncVat'] * $shipping_line['quantity'],
            'unitPriceIncVat * quantity must equal totalAmount or Briqpay rejects the refund with INVALID_DATA'
        );

        // And it must reflect the true captured balance (99.41 SEK / 19.88 VAT),
        // not whatever WooCommerce's own refund line happened to compute (79.53 / 0).
        $this->assertEquals(9941, $shipping_line['totalAmount']);
        $this->assertEquals(1988, $shipping_line['totalVatAmount']);
    }

    public function testRefundOrderSendsCanonicalTaxRateWhenExhaustingCapturedBalance()
    {
        // End-to-end reproduction of the second reported bug (after the first
        // INVALID_DATA fix): cart is 2x "Test product 1" at 236.72/unit (473.43
        // total) plus Shipping at 99.41 incl. 24.99% VAT (authorized taxRate 2499 -
        // ship_tax/ship_total doesn't divide evenly into a clean 25%, see
        // Session_Manager::get_cart_items()). Refunding only the shipping fee
        // (fully exhausting its captured balance, so it takes the "exact" branch)
        // used to send taxRate=2500 - WooCommerce's own nominal tax-class lookup
        // via WC_Tax::get_rate_percent_value() - instead of the 2499 actually
        // authorized with Briqpay, and the API rejected the refund with
        // CART_ITEM_NOT_FOUND: "mismatching taxRate". This asserts the taxRate
        // actually sent matches the canonical/authorized session data, not
        // WooCommerce's nominal rate.
        $order = Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_meta')->with('_briqpay_session_id')->andReturn('sess-2');
        $order->shouldReceive('get_meta')->with('_briqpay_capture_history')->andReturn(array(
            array(
                'captureId' => 'cap-2',
                'items' => array(
                    array('reference' => '4213asd-18937', 'quantity' => 2, 'totalAmount' => 47343, 'totalVatAmount' => 9469),
                    array('reference' => 'shipping', 'quantity' => 1, 'totalAmount' => 9941, 'totalVatAmount' => 1988),
                ),
            ),
        ));
        $order->shouldReceive('get_meta')->with('_briqpay_refund_history')->andReturn(array());
        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_currency')->andReturn('SEK');
        $order->shouldReceive('update_meta_data')->byDefault();
        $order->shouldReceive('save')->byDefault();
        $order->shouldReceive('add_order_note')->byDefault();

        // WooCommerce's own refund object: only the shipping line was selected,
        // entered at the full 99.41 / 19.88 split this time (amounts are correct -
        // only the tax RATE lookup is wrong).
        $shipping_refund_item = Mockery::mock('WC_Order_Item_Shipping');
        $shipping_refund_item->shouldReceive('get_total')->andReturn(-79.53);
        $shipping_refund_item->shouldReceive('get_total_tax')->andReturn(-19.88);

        $refund = Mockery::mock('WC_Order_Refund');
        $refund->shouldReceive('get_items')->with()->andReturn(array());
        $refund->shouldReceive('get_items')->with('coupon')->andReturn(array());
        $refund->shouldReceive('get_shipping_methods')->andReturn(array($shipping_refund_item));
        $order->shouldReceive('get_refunds')->andReturn(array($refund));

        // The order's shipping method resolves to WooCommerce's NOMINAL configured
        // tax-class rate (25.00%) via WC_Tax::get_rate_percent_value() - this is
        // the wrong rate that must NOT end up in the outgoing refund payload.
        $order_shipping_method = Mockery::mock('WC_Order_Item_Shipping');
        $order_shipping_method->shouldReceive('get_taxes')->andReturn(array('total' => array(1 => 19.88)));
        $order->shouldReceive('get_shipping_methods')->andReturn(array($order_shipping_method));

        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(25.00);

        WP_Mock::userFunction('wc_price', array('return_arg' => 0));
        WP_Mock::userFunction('current_time', array('return' => '2026-08-05 09:00:23'));
        WP_Mock::userFunction('__', array('return_arg' => 0));

        // The AUTHORITATIVE session Briqpay actually has on record: shipping was
        // authorized with taxRate 2499 (24.99%), not 2500.
        $session = array(
            'sessionId' => 'sess-2',
            'data' => array(
                'order' => array('cart' => array()),
                'transactions' => array(
                    array(
                        'cart' => array(
                            array(
                                'reference' => 'shipping',
                                'unitPrice' => 7953,
                                'unitPriceIncVat' => 9941,
                                'taxRate' => 2499,
                                'quantity' => 1,
                            ),
                        ),
                    ),
                ),
                'captures' => array(),
                'refunds' => array(),
            ),
        );

        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->andReturn($session);

        $captured_payload = null;
        $api->shouldReceive('refund_order')->once()->with('sess-2', Mockery::on(function ($payload) use (&$captured_payload) {
            $captured_payload = $payload;
            return true;
        }))->andReturn(array('refundId' => 'ref-2'));

        $order_mgmt = Mockery::mock(Order_Management::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $order_mgmt->shouldReceive('get_api')->andReturn($api);
        $order_mgmt->shouldReceive('is_enabled')->andReturn(true);

        $result = $order_mgmt->refund_order(true, $order, 99.41, '');

        $this->assertTrue($result);
        $this->assertNotNull($captured_payload, 'The refund payload must have reached the API call');

        $shipping_line = null;
        foreach ($captured_payload['data']['order']['cart'] as $line) {
            if ('shipping' === $line['reference']) {
                $shipping_line = $line;
            }
        }
        $this->assertNotNull($shipping_line, 'Shipping line must be present in the refund payload');

        // The invariant Briqpay rejects the refund over if it doesn't hold: taxRate
        // must match what was actually authorized (2499), not WooCommerce's nominal
        // tax-class rate (2500).
        $this->assertEquals(2499, $shipping_line['taxRate'], 'taxRate must match the canonical/authorized session data, not the nominal WC tax-class rate');

        // Amounts remain correct and internally consistent throughout.
        $this->assertEquals(9941, $shipping_line['totalAmount']);
        $this->assertEquals(1988, $shipping_line['totalVatAmount']);
        $this->assertEquals(
            $shipping_line['totalAmount'],
            $shipping_line['unitPriceIncVat'] * $shipping_line['quantity']
        );
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
