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

use Briqpay\WooCommerce\Hosted_Payment_Page;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Marker exception thrown by our wp_send_json_success()/wp_send_json_error()
 * stubs, mirroring how wp_die() halts execution in real WordPress so AJAX
 * control flow can be asserted in tests.
 */
class Hpp_Ajax_Response_Exception extends \Exception
{
    public $success;
    public $data;

    public function __construct($success, $data)
    {
        $this->success = $success;
        $this->data = $data;
        parent::__construct($success ? 'json_success' : 'json_error');
    }
}

class HostedPaymentPageTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Mock Static WC_Tax for every test (Order_Management::get_order_cart()
        // reads the nominal tax rate through it).
        $wc_tax = Mockery::mock('alias:WC_Tax');
        $wc_tax->shouldReceive('get_rate_percent_value')->andReturn(25.00);

        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('esc_html__', array('return_arg' => 0));
        WP_Mock::userFunction('esc_html', array('return_arg' => 0));
        WP_Mock::userFunction('esc_attr', array('return_arg' => 0));
        WP_Mock::userFunction('esc_url', array('return_arg' => 0));
        WP_Mock::userFunction('selected', array('return' => ''));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));
        WP_Mock::userFunction('do_action', array('return' => null));
        WP_Mock::userFunction('is_wp_error', array(
            'return' => function ($thing) {
                return $thing instanceof \WP_Error;
            },
        ));
        WP_Mock::userFunction('get_locale', array('return' => 'sv_SE'));
        WP_Mock::userFunction('wc_get_page_id', array('return' => 1));
        WP_Mock::userFunction('get_permalink', array('return' => 'https://example.com/terms/'));
        WP_Mock::userFunction('get_home_url', array('return' => 'https://example.com'));
        WP_Mock::userFunction('home_url', array('return' => 'https://example.com/wc-api/briqpay_webhook'));
        WP_Mock::userFunction('wc_get_base_location', array('return' => array('country' => 'SE')));
        WP_Mock::userFunction('wp_parse_url', array(
            'return' => function ($url) {
                return parse_url($url);
            },
        ));
        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_key', array('return_arg' => 0));
        WP_Mock::userFunction('wp_unslash', array('return_arg' => 0));

        // absint() is NOT hard-defined by tests/bootstrap.php (unlike get_option
        // and wc_get_order), so it's freely mockable. Stubbing it as identity lets
        // AJAX tests pass the order MOCK OBJECT itself as $_POST['order_id'] and
        // have wc_get_order()'s real bootstrap shim (`is_object($id) ? $id : null`)
        // resolve it correctly - passing a numeric ID would always resolve to null.
        WP_Mock::userFunction('absint', array('return_arg' => 0));

        WP_Mock::userFunction('check_ajax_referer', array('return' => true));
        WP_Mock::userFunction('wp_send_json_success', array(
            'return' => function ($data = null) {
                throw new Hpp_Ajax_Response_Exception(true, $data);
            },
        ));
        WP_Mock::userFunction('wp_send_json_error', array(
            'return' => function ($data = null) {
                throw new Hpp_Ajax_Response_Exception(false, $data);
            },
        ));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
        $_POST = array();
        $_GET = array();
    }

    /**
     * Build a WC_Order mock with sensible defaults for a single-line-item,
     * complete-address SE order. Pass $overrides to customize.
     */
    private function makeOrder(array $overrides = array())
    {
        $defaults = array(
            'id' => 123,
            'order_number' => '1123',
            'currency' => 'SEK',
            'total' => 125.00,
            'status' => 'pending',
            'billing_country' => 'SE',
            'billing_company' => '',
            'billing_first_name' => 'Jane',
            'billing_last_name' => 'Doe',
            'billing_email' => 'jane@example.com',
            'billing_address_1' => 'Main St 1',
            'billing_address_2' => '',
            'billing_city' => 'Stockholm',
            'billing_postcode' => '11122',
            'billing_state' => '',
            'billing_phone' => '',
            'shipping_first_name' => 'Jane',
            'shipping_last_name' => 'Doe',
            'shipping_address_1' => 'Main St 1',
            'shipping_address_2' => '',
            'shipping_city' => 'Stockholm',
            'shipping_postcode' => '11122',
            'shipping_state' => '',
            'shipping_country' => 'SE',
            'checkout_order_received_url' => 'https://example.com/order-received/123/',
            'items' => null,
            'coupon_items' => array(),
            'fees' => array(),
            'shipping_total' => 0.0,
            'shipping_tax' => 0.0,
            'shipping_methods' => array(),
            'meta' => array(),
        );

        $config = array_merge($defaults, $overrides);
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn($config['id']);
        $order->shouldReceive('get_order_number')->andReturn($config['order_number']);
        $order->shouldReceive('get_currency')->andReturn($config['currency']);
        $order->shouldReceive('get_total')->andReturn($config['total']);
        $order->shouldReceive('get_billing_country')->andReturn($config['billing_country']);
        $order->shouldReceive('get_billing_company')->andReturn($config['billing_company']);
        $order->shouldReceive('get_billing_first_name')->andReturn($config['billing_first_name']);
        $order->shouldReceive('get_billing_last_name')->andReturn($config['billing_last_name']);
        $order->shouldReceive('get_billing_email')->andReturn($config['billing_email']);
        $order->shouldReceive('get_billing_address_1')->andReturn($config['billing_address_1']);
        $order->shouldReceive('get_billing_address_2')->andReturn($config['billing_address_2']);
        $order->shouldReceive('get_billing_city')->andReturn($config['billing_city']);
        $order->shouldReceive('get_billing_postcode')->andReturn($config['billing_postcode']);
        $order->shouldReceive('get_billing_state')->andReturn($config['billing_state']);
        $order->shouldReceive('get_billing_phone')->andReturn($config['billing_phone']);
        $order->shouldReceive('get_shipping_first_name')->andReturn($config['shipping_first_name']);
        $order->shouldReceive('get_shipping_last_name')->andReturn($config['shipping_last_name']);
        $order->shouldReceive('get_shipping_address_1')->andReturn($config['shipping_address_1']);
        $order->shouldReceive('get_shipping_address_2')->andReturn($config['shipping_address_2']);
        $order->shouldReceive('get_shipping_city')->andReturn($config['shipping_city']);
        $order->shouldReceive('get_shipping_postcode')->andReturn($config['shipping_postcode']);
        $order->shouldReceive('get_shipping_state')->andReturn($config['shipping_state']);
        $order->shouldReceive('get_shipping_country')->andReturn($config['shipping_country']);
        $order->shouldReceive('get_checkout_order_received_url')->andReturn($config['checkout_order_received_url']);

        $status = $config['status'];
        $order->shouldReceive('has_status')->andReturnUsing(function ($statuses) use ($status) {
            return in_array($status, (array) $statuses, true);
        })->byDefault();

        $items = null !== $config['items'] ? $config['items'] : array($this->makeLineItem());
        $order->shouldReceive('get_items')->with()->andReturn($items);
        $order->shouldReceive('get_items')->with('coupon')->andReturn($config['coupon_items']);
        $order->shouldReceive('get_fees')->andReturn($config['fees']);
        $order->shouldReceive('get_shipping_total')->andReturn($config['shipping_total']);
        if ($config['shipping_total'] > 0) {
            $order->shouldReceive('get_shipping_tax')->andReturn($config['shipping_tax']);
            $order->shouldReceive('get_shipping_methods')->andReturn($config['shipping_methods']);
        }

        foreach ($config['meta'] as $key => $value) {
            $order->shouldReceive('get_meta')->with($key)->andReturn($value);
        }
        $order->shouldReceive('get_meta')->andReturn('')->byDefault();

        $order->shouldReceive('update_meta_data')->byDefault();
        $order->shouldReceive('set_payment_method')->byDefault();
        $order->shouldReceive('set_payment_method_title')->byDefault();
        $order->shouldReceive('set_status')->byDefault();
        $order->shouldReceive('add_order_note')->byDefault();
        $order->shouldReceive('save')->byDefault();

        return $order;
    }

    private function makeLineItem(array $overrides = array())
    {
        $defaults = array(
            'id' => 1,
            'name' => 'Test product',
            'quantity' => 1,
            'subtotal' => 100.00,
            'subtotal_tax' => 25.00,
            'taxes' => array('total' => array(1 => 25.00)),
            'item_reference_meta' => '',
            'no_product' => false,
            'sku' => 'SKU1',
            'product_id' => 42,
        );
        $config = array_merge($defaults, $overrides);

        $product = $config['no_product'] ? null : $this->makeProduct($config['sku'], $config['product_id']);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn($config['id']);
        $item->shouldReceive('get_name')->andReturn($config['name']);
        $item->shouldReceive('get_quantity')->andReturn($config['quantity']);
        $item->shouldReceive('get_subtotal')->andReturn($config['subtotal']);
        $item->shouldReceive('get_subtotal_tax')->andReturn($config['subtotal_tax']);
        $item->shouldReceive('get_taxes')->andReturn($config['taxes']);
        $item->shouldReceive('get_product')->andReturn($product);
        $item->shouldReceive('get_meta')->with('_briqpay_item_reference')->andReturn($config['item_reference_meta']);
        $item->shouldReceive('add_meta_data')->byDefault();
        $item->shouldReceive('save')->byDefault();

        return $item;
    }

    private function makeProduct($sku = 'SKU1', $id = 42)
    {
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_sku')->andReturn($sku);
        $product->shouldReceive('get_id')->andReturn($id);
        return $product;
    }

    /**
     * Build a Hosted_Payment_Page with get_settings()/get_api() stubbed - the
     * seams the class exposes specifically so tests never touch get_option()
     * or the real API HTTP layer.
     */
    private function makeHpp(array $settings = array(), $api = null)
    {
        $default_settings = array(
            'hpp_enabled' => 'yes',
            'merchant_id' => 'mid',
            'shared_secret' => 'secret',
            'testmode' => 'yes',
            'hpp_default_flow' => 'b2c',
            'hpp_page_title' => '',
            'hpp_logo_url' => '',
            'hpp_show_cart' => 'yes',
        );
        $settings = array_merge($default_settings, $settings);

        $hpp = Mockery::mock(Hosted_Payment_Page::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $hpp->shouldReceive('get_settings')->andReturn($settings);
        if (null !== $api) {
            $hpp->shouldReceive('get_api')->andReturn($api);
        }

        return $hpp;
    }

    private function makeSuccessApi($sessionId = 'sess-1', $hostedPageId = 'hpp-1', $url = 'https://hp.briqpay.com/payment/x/y')
    {
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(array('sessionId' => $sessionId));
        $api->shouldReceive('create_hosted_page')->andReturn(array('hostedPageId' => $hostedPageId, 'url' => $url));
        return $api;
    }

    // -----------------------------------------------------------------
    // Payload shape
    // -----------------------------------------------------------------

    public function testB2cFlowUsesConsumerAndPaymentModuleOnly()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals('consumer', $payload['customerType']);
        $this->assertEquals(array('payment'), $payload['modules']['loadModules']);
    }

    public function testB2bPaymentModuleFlowUsesBusinessAndPaymentModuleOnly()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2B_PAYMENT);

        $this->assertEquals('business', $payload['customerType']);
        $this->assertEquals(array('payment'), $payload['modules']['loadModules']);
    }

    public function testB2bCheckoutFlowLoadsCompanyLookupBillingShippingAndPayment()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2B_CHECKOUT);

        $this->assertEquals('business', $payload['customerType']);
        $this->assertEquals(array('company_lookup', 'billing', 'shipping', 'payment'), $payload['modules']['loadModules']);
    }

    public function testDecisionIsAlwaysDisabledOnHostedPageSessions()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();

        foreach (array(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::FLOW_B2B_PAYMENT, Hosted_Payment_Page::FLOW_B2B_CHECKOUT) as $flow) {
            $payload = $hpp->build_session_payload($order, $flow);
            $this->assertFalse(
                $payload['modules']['config']['payment']['decision']['enabled'],
                'Decision must be disabled for flow: ' . $flow . ' - nothing calls make_decision() for a hosted page.'
            );
        }
    }

    public function testRealTimeProcessingIsAlwaysEnabledOnHostedPageSessions()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();

        foreach (array(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::FLOW_B2B_PAYMENT, Hosted_Payment_Page::FLOW_B2B_CHECKOUT) as $flow) {
            $payload = $hpp->build_session_payload($order, $flow);
            $this->assertTrue(
                $payload['config']['realTimeProcessing'],
                'realTimeProcessing must be enabled for flow: ' . $flow
            );
        }
    }

    public function testProductIsPaymentOneTime()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals(array('type' => 'payment', 'intent' => 'payment_one_time'), $payload['product']);
    }

    public function testAmountsAreSummedFromCartLinesNotOrderTotal()
    {
        $hpp = $this->makeHpp();
        // Order total deliberately differs from what the default single line item sums to.
        $order = $this->makeOrder(array('total' => 999.99));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        // Default line item: subtotal 100.00, subtotal_tax 25.00, qty 1 => totalAmount 12500.
        $this->assertEquals(12500, $payload['data']['order']['amountIncVat']);
        $this->assertEquals(10000, $payload['data']['order']['amountExVat']);
    }

    public function testSalesTaxLineContributesToIncVatOnly()
    {
        $hpp = $this->makeHpp();
        $item = $this->makeLineItem(array('subtotal' => 100.00, 'subtotal_tax' => 8.00));
        $order = $this->makeOrder(array(
            'billing_country' => 'US',
            'items' => array($item),
            'total' => 108.00,
        ));

        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);
        $cart = $payload['data']['order']['cart'];

        $sales_tax_line = null;
        foreach ($cart as $line) {
            if ('sales_tax' === $line['productType']) {
                $sales_tax_line = $line;
            }
        }
        $this->assertNotNull($sales_tax_line, 'Sales tax line must be present for a US order');
        $this->assertEquals(800, $sales_tax_line['totalTaxAmount']);

        $this->assertEquals(10800, $payload['data']['order']['amountIncVat']);
        $this->assertEquals(10000, $payload['data']['order']['amountExVat']);
    }

    public function testCartLineInvariantHolds()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        foreach ($payload['data']['order']['cart'] as $line) {
            if ('sales_tax' === $line['productType']) {
                continue;
            }
            $this->assertEquals(
                $line['totalAmount'] - $line['totalVatAmount'],
                $line['unitPrice'] * $line['quantity'],
                'unitPrice * quantity must equal totalAmount - totalVatAmount for line: ' . $line['reference']
            );
        }
    }

    public function testBillingAddressIsPassedWhenComplete()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertArrayHasKey('billing', $payload['data']);
        $this->assertEquals('Jane', $payload['data']['billing']['firstName']);
        $this->assertEquals('Main St 1', $payload['data']['billing']['streetAddress']);
        $this->assertEquals('SE', $payload['data']['billing']['country']);
    }

    public function testIncompleteBillingAddressIsOmitted()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('billing_postcode' => ''));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertArrayNotHasKey('billing', $payload['data']);
    }

    public function testShippingAddressIsPassedWhenComplete()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertArrayHasKey('shipping', $payload['data']);
        $this->assertEquals('Main St 1', $payload['data']['shipping']['streetAddress']);
    }

    public function testShippingAddressIsOmittedWhenAbsent()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('shipping_address_1' => ''));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertArrayNotHasKey('shipping', $payload['data']);
    }

    public function testCompanyIsPassedForBothB2bFlows()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('billing_company' => 'Acme AB'));

        foreach (array(Hosted_Payment_Page::FLOW_B2B_PAYMENT, Hosted_Payment_Page::FLOW_B2B_CHECKOUT) as $flow) {
            $payload = $hpp->build_session_payload($order, $flow);
            $this->assertEquals('Acme AB', $payload['data']['company']['name']);
        }
    }

    public function testCompanyIsOmittedForB2c()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('billing_company' => 'Acme AB'));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertArrayNotHasKey('company', $payload['data']);
    }

    public function testCompanyCinIsIncludedWhenStoredOnOrder()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array(
            'billing_company' => 'Acme AB',
            'meta' => array('_briqpay_company_cin' => '556677-8899'),
        ));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2B_CHECKOUT);

        $this->assertEquals('556677-8899', $payload['data']['company']['cin']);
    }

    public function testRedirectUrlIsTheOrderReceivedUrl()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('checkout_order_received_url' => 'https://example.com/order-received/123/?key=abc'));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals('https://example.com/order-received/123/?key=abc', $payload['urls']['redirect']);
    }

    public function testTermsUrlIsIncluded()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals('https://example.com/terms/', $payload['urls']['terms']);
    }

    public function testReferencesIncludeOrderNumberAsReference1()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('order_number' => '1123'));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals('1123', $payload['references']['reference1']);
    }

    public function testHooksMatchTheSubscribedWebhookEvents()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder();
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $event_types = array();
        foreach ($payload['hooks'] as $hook) {
            $event_types[$hook['eventType']] = $hook['statuses'];
        }

        $this->assertEqualsCanonicalizing(array('order_status', 'capture_status', 'refund_status'), array_keys($event_types));
        $this->assertEqualsCanonicalizing(array('order_pending', 'order_rejected', 'order_cancelled', 'order_approved_not_captured'), $event_types['order_status']);
        $this->assertEqualsCanonicalizing(array('pending', 'approved', 'rejected'), $event_types['capture_status']);
        $this->assertEqualsCanonicalizing(array('pending', 'approved', 'rejected'), $event_types['refund_status']);
    }

    public function testCountryFallsBackToStoreBaseWhenOrderHasNone()
    {
        $hpp = $this->makeHpp();
        $order = $this->makeOrder(array('billing_country' => ''));
        $payload = $hpp->build_session_payload($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEquals('SE', $payload['country']);
    }

    // -----------------------------------------------------------------
    // Hosted page config
    // -----------------------------------------------------------------

    public function testShowCartIsTrueByDefaultAndBooleanTyped()
    {
        $hpp = $this->makeHpp();
        $config = $hpp->build_hosted_page_config();

        $this->assertIsBool($config['showCart']);
        $this->assertTrue($config['showCart']);
    }

    public function testShowCartIsFalseWhenSettingIsNo()
    {
        $hpp = $this->makeHpp(array('hpp_show_cart' => 'no'));
        $config = $hpp->build_hosted_page_config();

        $this->assertIsBool($config['showCart']);
        $this->assertFalse($config['showCart']);
    }

    public function testPageTitleShorterThanThreeCharsIsOmitted()
    {
        $hpp = $this->makeHpp(array('hpp_page_title' => 'ab'));
        $config = $hpp->build_hosted_page_config();

        $this->assertArrayNotHasKey('pageTitle', $config);
    }

    public function testPageTitleIsTruncatedTo256Chars()
    {
        $hpp = $this->makeHpp(array('hpp_page_title' => str_repeat('a', 300)));
        $config = $hpp->build_hosted_page_config();

        $this->assertEquals(256, strlen($config['pageTitle']));
    }

    public function testLogoUrlWithUnsupportedExtensionIsOmitted()
    {
        $hpp = $this->makeHpp(array('hpp_logo_url' => 'https://example.com/logo.gif'));
        $config = $hpp->build_hosted_page_config();
        $this->assertArrayNotHasKey('logoUrl', $config);

        $hpp2 = $this->makeHpp(array('hpp_logo_url' => 'ftp://example.com/logo.png'));
        $config2 = $hpp2->build_hosted_page_config();
        $this->assertArrayNotHasKey('logoUrl', $config2);
    }

    public function testLogoUrlWithQueryStringIsAccepted()
    {
        $hpp = $this->makeHpp(array('hpp_logo_url' => 'https://example.com/logo.png?v=2'));
        $config = $hpp->build_hosted_page_config();

        $this->assertEquals('https://example.com/logo.png?v=2', $config['logoUrl']);
    }

    public function testLogoUrlOver512CharsIsOmitted()
    {
        $long_url = 'https://example.com/' . str_repeat('a', 500) . '.png';
        $hpp = $this->makeHpp(array('hpp_logo_url' => $long_url));
        $config = $hpp->build_hosted_page_config();

        $this->assertArrayNotHasKey('logoUrl', $config);
    }

    // -----------------------------------------------------------------
    // create()
    // -----------------------------------------------------------------

    public function testCreateStoresSessionIdHostedPageIdUrlAndFlowOnTheOrder()
    {
        $hosted = array('hostedPageId' => 'hpp-1', 'url' => 'https://hp.briqpay.com/payment/x/y');
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(array('sessionId' => 'sess-123'));
        $api->shouldReceive('create_hosted_page')->andReturn($hosted);

        $order = $this->makeOrder();
        $order->shouldReceive('update_meta_data')->with('_briqpay_session_id', 'sess-123')->once();
        $order->shouldReceive('update_meta_data')->with(Hosted_Payment_Page::META_HPP_ID, 'hpp-1')->once();
        $order->shouldReceive('update_meta_data')->with(Hosted_Payment_Page::META_HPP_URL, $hosted['url'])->once();
        $order->shouldReceive('update_meta_data')->with(Hosted_Payment_Page::META_HPP_FLOW, Hosted_Payment_Page::FLOW_B2C)->once();
        $order->shouldReceive('update_meta_data')->with(Hosted_Payment_Page::META_HPP_CREATED, Mockery::type('string'))->once();

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertIsArray($result);
        $this->assertEquals($hosted['url'], $result['url']);
    }

    public function testCreateSetsPaymentMethodToBriqpayAndStatusPending()
    {
        $api = $this->makeSuccessApi();
        $order = $this->makeOrder(array('status' => 'pending'));
        $order->shouldReceive('set_payment_method')->with('briqpay')->once();
        $order->shouldReceive('set_payment_method_title')->with('Briqpay')->once();
        $order->shouldReceive('set_status')->with('pending')->once();

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertIsArray($result);
    }

    public function testCreateFreezesLineReferencesOntoOrderItems()
    {
        $api = $this->makeSuccessApi();
        $item = $this->makeLineItem(array('sku' => 'SKU-XYZ'));
        $item->shouldReceive('add_meta_data')->with('_briqpay_item_reference', 'SKU-XYZ', true)->once();
        $item->shouldReceive('save')->once();

        $order = $this->makeOrder(array('items' => array($item)));

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertIsArray($result);
    }

    public function testCreateDoesNotOverwriteAnAlreadyFrozenLineReference()
    {
        $api = $this->makeSuccessApi();
        $item = $this->makeLineItem(array('sku' => 'SKU-NEW', 'item_reference_meta' => 'FROZEN-OLD-SKU'));
        $item->shouldReceive('add_meta_data')->never();

        $order = $this->makeOrder(array('items' => array($item)));

        $hpp = $this->makeHpp(array(), $api);
        $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);
    }

    public function testCreatePostsSessionIdAndConfigToHostedPageEndpoint()
    {
        $captured = null;
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(array('sessionId' => 'sess-1'));
        $api->shouldReceive('create_hosted_page')->with(Mockery::on(function ($arg) use (&$captured) {
            $captured = $arg;
            return true;
        }))->andReturn(array('url' => 'https://hp.briqpay.com/x'));

        $order = $this->makeOrder();
        $hpp = $this->makeHpp(array('hpp_page_title' => 'Pay now', 'hpp_show_cart' => 'yes'), $api);
        $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertEqualsCanonicalizing(array('sessionId', 'config'), array_keys($captured));
        $this->assertEquals('sess-1', $captured['sessionId']);
        $this->assertEquals('Pay now', $captured['config']['pageTitle']);
        $this->assertTrue($captured['config']['showCart']);
    }

    public function testCreateReturnsWpErrorAndDoesNotTouchTheOrderWhenSessionCreationFails()
    {
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(new \WP_Error('api_error', 'boom'));

        $order = $this->makeOrder();
        $order->shouldReceive('save')->never();
        $order->shouldReceive('set_payment_method')->never();

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
    }

    public function testCreateReturnsWpErrorAndDoesNotTouchTheOrderWhenHostedPageCreationFails()
    {
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(array('sessionId' => 'sess-1'));
        $api->shouldReceive('create_hosted_page')->andReturn(new \WP_Error('api_error', 'boom'));

        $order = $this->makeOrder();
        $order->shouldReceive('save')->never();

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
    }

    public function testCreateReturnsWpErrorWhenResponseHasNoUrl()
    {
        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('create_session')->andReturn(array('sessionId' => 'sess-1'));
        $api->shouldReceive('create_hosted_page')->andReturn(array('hostedPageId' => 'hpp-1'));

        $order = $this->makeOrder();
        $order->shouldReceive('save')->never();

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
    }

    public function testCreateRefusesWhenLineSumDeviatesFromOrderTotalByMoreThanFiveMinorUnits()
    {
        // Default single line item sums to 125.00; order total is set far off.
        $order = $this->makeOrder(array('total' => 500.00));
        $order->shouldReceive('save')->never();

        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldNotReceive('create_session');

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('briqpay_hpp_total_mismatch', $result->get_error_code());
    }

    public function testCreateRefusesForAnOrderWithNoItems()
    {
        $order = $this->makeOrder(array('items' => array()));
        $order->shouldReceive('save')->never();

        $hpp = $this->makeHpp();
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('briqpay_hpp_empty_order', $result->get_error_code());
    }

    public function testCreateRefusesForAnAlreadyPaidOrder()
    {
        foreach (array('processing', 'completed', 'refunded', 'cancelled') as $status) {
            $order = $this->makeOrder(array('status' => $status));
            $order->shouldReceive('save')->never();

            $hpp = $this->makeHpp();
            $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

            $this->assertInstanceOf('WP_Error', $result, 'Expected WP_Error for status: ' . $status);
            $this->assertEquals('briqpay_hpp_order_not_payable', $result->get_error_code());
        }
    }

    public function testCreateRefusesToRegenerateOverACompletedSession()
    {
        $order = $this->makeOrder(array(
            'meta' => array('_briqpay_session_id' => 'sess-old'),
        ));
        $order->shouldReceive('save')->never();

        $api = Mockery::mock('Briqpay\WooCommerce\API');
        $api->shouldReceive('get_session')->with('sess-old')->andReturn(array('status' => 'completed'));
        $api->shouldNotReceive('create_session');

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('briqpay_hpp_session_completed', $result->get_error_code());
    }

    public function testCreateAllowsRegenerationOverANonCompletedSession()
    {
        $order = $this->makeOrder(array(
            'meta' => array('_briqpay_session_id' => 'sess-old'),
        ));

        $api = $this->makeSuccessApi('sess-new', 'hpp-2', 'https://hp.briqpay.com/new');
        $api->shouldReceive('get_session')->with('sess-old')->andReturn(array('status' => 'pending'));

        $hpp = $this->makeHpp(array(), $api);
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertIsArray($result);
        $this->assertEquals('https://hp.briqpay.com/new', $result['url']);
    }

    public function testCreateIsBlockedWhenHostedPagesAreDisabledInSettings()
    {
        $order = $this->makeOrder();
        $order->shouldReceive('save')->never();

        $hpp = $this->makeHpp(array('hpp_enabled' => 'no'));
        $result = $hpp->create($order, Hosted_Payment_Page::FLOW_B2C);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('briqpay_hpp_disabled', $result->get_error_code());
    }

    // -----------------------------------------------------------------
    // Flow resolution
    // -----------------------------------------------------------------

    public function testNormalizeFlowFallsBackToB2cForUnknownInput()
    {
        $this->assertEquals(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::normalize_flow('bogus'));
        $this->assertEquals(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::normalize_flow(''));
        $this->assertEquals(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::normalize_flow(null));
    }

    public function testGetDefaultFlowReadsTheSetting()
    {
        $hpp = $this->makeHpp(array('hpp_default_flow' => 'b2b_checkout'));
        $this->assertEquals('b2b_checkout', $hpp->get_default_flow());
    }

    public function testFlowLabelsUseTheUpdatedCopy()
    {
        $flows = Hosted_Payment_Page::get_flows();

        $this->assertEquals('Consumer', $flows[Hosted_Payment_Page::FLOW_B2C]['label']);
        $this->assertEquals('Business - Payment Methods Only', $flows[Hosted_Payment_Page::FLOW_B2B_PAYMENT]['label']);
        $this->assertEquals('Business - Full Checkout', $flows[Hosted_Payment_Page::FLOW_B2B_CHECKOUT]['label']);
    }

    // -----------------------------------------------------------------
    // Meta box rendering - already-paid orders
    // -----------------------------------------------------------------

    public function testRenderMetaBoxDoesNotShowTheLinkForAnAlreadyPaidOrder()
    {
        $order = $this->makeOrder(array(
            'status' => 'completed',
            'meta' => array(Hosted_Payment_Page::META_HPP_URL => 'https://hp.briqpay.com/payment/x/y'),
        ));

        $hpp = $this->makeHpp();

        ob_start();
        $hpp->render_meta_box($order);
        $output = ob_get_clean();

        // The link is dead once the order is paid - it must not be shown or
        // linked, only the explanatory text.
        $this->assertStringNotContainsString('https://hp.briqpay.com/payment/x/y', $output);
        $this->assertStringContainsString('This order has already been paid, refunded or cancelled. No new hosted page can be created.', $output);
    }

    // -----------------------------------------------------------------
    // AJAX
    // -----------------------------------------------------------------

    public function testAjaxRejectsAnInvalidFlowInsteadOfSilentlyFallingBackToB2c()
    {
        $_POST = array('nonce' => 'n', 'order_id' => 1, 'flow' => 'not_a_flow');
        WP_Mock::userFunction('current_user_can', array('return' => true));

        $hpp = $this->makeHpp();

        try {
            $hpp->ajax_create_hosted_page();
            $this->fail('Expected Hpp_Ajax_Response_Exception');
        } catch (Hpp_Ajax_Response_Exception $e) {
            $this->assertFalse($e->success);
            $this->assertEquals('Invalid flow selected.', $e->data['message']);
        }
    }

    public function testAjaxReturnsTheUrlOnSuccess()
    {
        $order = $this->makeOrder();
        $api = $this->makeSuccessApi('sess-1', 'hpp-1', 'https://hp.briqpay.com/payment/x/y');

        // Pass the order MOCK OBJECT itself - see the absint()/wc_get_order() note in setUp().
        $_POST = array('nonce' => 'n', 'order_id' => $order, 'flow' => 'b2c');
        WP_Mock::userFunction('current_user_can', array('return' => true));

        $hpp = $this->makeHpp(array(), $api);

        try {
            $hpp->ajax_create_hosted_page();
            $this->fail('Expected Hpp_Ajax_Response_Exception');
        } catch (Hpp_Ajax_Response_Exception $e) {
            $this->assertTrue($e->success);
            $this->assertEquals('https://hp.briqpay.com/payment/x/y', $e->data['url']);
            $this->assertEquals('b2c', $e->data['flow']);
        }
    }

    public function testAjaxReturnsOrderNotFoundWhenOrderIdDoesNotResolve()
    {
        $_POST = array('nonce' => 'n', 'order_id' => 999999, 'flow' => 'b2c');
        WP_Mock::userFunction('current_user_can', array('return' => true));

        $hpp = $this->makeHpp();

        try {
            $hpp->ajax_create_hosted_page();
            $this->fail('Expected Hpp_Ajax_Response_Exception');
        } catch (Hpp_Ajax_Response_Exception $e) {
            $this->assertFalse($e->success);
            $this->assertEquals('Order not found.', $e->data['message']);
        }
    }

    public function testAjaxRejectsUsersWithoutTheCapability()
    {
        $_POST = array('nonce' => 'n', 'order_id' => 1, 'flow' => 'b2c');
        WP_Mock::userFunction('current_user_can', array('return' => false));

        $hpp = $this->makeHpp();

        try {
            $hpp->ajax_create_hosted_page();
            $this->fail('Expected Hpp_Ajax_Response_Exception');
        } catch (Hpp_Ajax_Response_Exception $e) {
            $this->assertFalse($e->success);
            $this->assertEquals('Permission denied.', $e->data['message']);
        }
    }

    // -----------------------------------------------------------------
    // Meta box scoping - admin-created orders only
    // -----------------------------------------------------------------

    public function testIsAdminCreatedOrderTrueForAdminOrigin()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('admin');
        $this->assertTrue(Hosted_Payment_Page::is_admin_created_order($order));
    }

    public function testIsAdminCreatedOrderTrueForEmptyOrigin()
    {
        // A brand new, not-yet-saved order has no created_via set yet.
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('');
        $this->assertTrue(Hosted_Payment_Page::is_admin_created_order($order));
    }

    public function testIsAdminCreatedOrderFalseForCheckoutOrigin()
    {
        // Checkout_Handler tags storefront orders with created_via 'Briqpay'.
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('Briqpay');
        $this->assertFalse(Hosted_Payment_Page::is_admin_created_order($order));
    }

    public function testIsAdminCreatedOrderFalseForOtherOrigins()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('subscription');
        $this->assertFalse(Hosted_Payment_Page::is_admin_created_order($order));
    }

    public function testIsAdminCreatedOrderTrueWhenOrderCannotBeResolved()
    {
        // Fails open - an unresolvable order must not hide the box.
        $this->assertTrue(Hosted_Payment_Page::is_admin_created_order(null));
    }

    public function testAddMetaBoxIsNotRegisteredForNonAdminCreatedOrders()
    {
        WP_Mock::userFunction('get_current_screen', array(
            'return' => (object) array('id' => 'shop_order'),
        ));
        WP_Mock::userFunction('add_meta_box')->never();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('Briqpay');

        $hpp = $this->makeHpp();
        $hpp->shouldReceive('resolve_order_from_admin_request')->andReturn($order);

        $hpp->add_meta_box();
    }

    public function testAddMetaBoxIsRegisteredForAdminCreatedOrders()
    {
        WP_Mock::userFunction('get_current_screen', array(
            'return' => (object) array('id' => 'shop_order'),
        ));
        WP_Mock::userFunction('add_meta_box')->once();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_created_via')->andReturn('admin');

        $hpp = $this->makeHpp();
        $hpp->shouldReceive('resolve_order_from_admin_request')->andReturn($order);

        $hpp->add_meta_box();
    }

    public function testAddMetaBoxIsRegisteredWhenOrderCannotBeResolved()
    {
        // A brand new HPOS "Add order" screen where we can't resolve an order
        // yet - must fail open (show the box) rather than hide it.
        WP_Mock::userFunction('get_current_screen', array(
            'return' => (object) array('id' => 'woocommerce_page_wc-orders'),
        ));
        WP_Mock::userFunction('add_meta_box')->once();

        $hpp = $this->makeHpp();
        $hpp->shouldReceive('resolve_order_from_admin_request')->andReturn(null);

        $hpp->add_meta_box();
    }

    public function testAddMetaBoxDoesNothingWhenDisabledInSettings()
    {
        WP_Mock::userFunction('get_current_screen')->never();
        WP_Mock::userFunction('add_meta_box')->never();

        $hpp = $this->makeHpp(array('hpp_enabled' => 'no'));
        $hpp->add_meta_box();
    }

    // -----------------------------------------------------------------
    // Settings page fold/unfold script
    // -----------------------------------------------------------------

    public function testAdminScriptsEnqueuesSettingsScriptOnBriqpaySettingsPage()
    {
        $_GET = array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'briqpay');

        WP_Mock::userFunction('wp_enqueue_script')->once()->with(
            'briqpay-hpp-settings',
            Mockery::type('string'),
            array('jquery'),
            Mockery::any(),
            true
        );

        $hpp = $this->makeHpp();
        $hpp->admin_scripts('woocommerce_page_wc-settings');
    }

    public function testAdminScriptsDoesNotEnqueueSettingsScriptOnOtherGatewaySections()
    {
        $_GET = array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'stripe');

        WP_Mock::userFunction('wp_enqueue_script')->never();

        $hpp = $this->makeHpp();
        $hpp->admin_scripts('woocommerce_page_wc-settings');
    }
}

}
