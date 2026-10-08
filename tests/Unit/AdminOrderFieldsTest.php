<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Admin_Order_Fields;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use Briqpay\WooCommerce\Session_Order_Data;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * The Briqpay checkout fields (reference, own order number, invoice email,
 * GLN, and whatever the merchant configured) on the order edit screen: shown
 * and editable, following the payment method like the organisation number,
 * saved into the same meta the checkout writes, and sent on with a hosted
 * payment page. Swemed: "kan vi även få upp alla extra fält som vi vanligtvis
 * fyller i?"
 */
class AdminOrderFieldsTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var array<string,string> */
    private $meta = array();

    /** @var string[] */
    private $notes = array();

    /** @var int */
    private $saves = 0;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Options::reset();
        $this->meta = array();
        $this->notes = array();
        $this->saves = 0;
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('esc_html__', array('return_arg' => 0));
        WP_Mock::userFunction('esc_html_e', array('return' => function ($s) {
            echo $s;
        }));
        WP_Mock::userFunction('esc_html', array('return_arg' => 0));
        WP_Mock::userFunction('esc_attr', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_email', array('return' => function ($s) {
            return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
        }));
        WP_Mock::userFunction('sanitize_key', array('return_arg' => 0));
        WP_Mock::userFunction('wp_unslash', array('return_arg' => 0));
        WP_Mock::userFunction('wp_json_encode', array('return' => function ($v) {
            return json_encode($v);
        }));
        WP_Mock::userFunction('is_email', array('return' => function ($s) {
            return (bool) filter_var($s, FILTER_VALIDATE_EMAIL);
        }));
        WP_Mock::userFunction('woocommerce_wp_text_input', array('return' => function ($args) {
            printf('<input id="%s" name="%s" value="%s" type="%s" data-label="%s" />', $args['id'], $args['name'], $args['value'], $args['type'], $args['label']);
        }));
    }

    public function tearDown(): void
    {
        \Briqpay_Test_Options::reset();
        unset($_POST[Admin_Order_Fields::POST_KEY], $_POST['woocommerce_meta_nonce']);
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function order($payment_method = 'briqpay', array $meta = array())
    {
        $this->meta = $meta;
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(77);
        $order->shouldReceive('get_payment_method')->andReturn($payment_method);
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) {
            return $this->meta[$key] ?? '';
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function ($key, $value) {
            $this->meta[$key] = $value;
        });
        $order->shouldReceive('delete_meta_data')->andReturnUsing(function ($key) {
            unset($this->meta[$key]);
        });
        $order->shouldReceive('add_order_note')->andReturnUsing(function ($note) {
            $this->notes[] = $note;
        });
        $order->shouldReceive('save')->andReturnUsing(function () {
            $this->saves++;
        });
        return $order;
    }

    private function render($order)
    {
        ob_start();
        (new Admin_Order_Fields())->render($order);
        return ob_get_clean();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Which fields are offered
    // ──────────────────────────────────────────────────────────────────────

    public function testAManualOrderOffersTheStandardInvoiceFields(): void
    {
        $html = $this->render($this->order());

        foreach (array('reference', 'orderNumber', 'email', 'gln') as $key) {
            $this->assertStringContainsString('name="briqpay_fields[paymentAdditionalFields][' . $key . ']"', $html);
        }
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringNotContainsString('<div class="address">', $html, 'Nothing filled in yet, so no view block.');
        $this->assertStringContainsString('briqpay-admin-field', $html, 'The toggle script finds it by this class.');
        $this->assertStringNotContainsString('display:none', $html);
    }

    public function testFieldsSeenOnEarlierOrdersAreOfferedWithTheirLabels(): void
    {
        Session_Order_Data::remember_fields(array(
            array('source' => 'customForm1', 'key' => 'costCenter', 'label' => 'Kostnadsställe', 'value' => '4711'),
        ));

        $html = $this->render($this->order());

        $this->assertStringContainsString('name="briqpay_fields[customForm1][costCenter]"', $html);
        $this->assertStringContainsString('data-label="Kostnadsställe"', $html);
    }

    public function testTheOrdersOwnFieldsAreShownInViewModeWithTheirValues(): void
    {
        $order = $this->order('briqpay', array(
            Session_Order_Data::META_FIELDS => json_encode(array(
                array('source' => 'paymentAdditionalFields', 'key' => 'reference', 'label' => 'Er referens', 'value' => 'Anna'),
            )),
            '_briqpay_payment_field_reference' => 'Anna',
        ));

        $html = $this->render($order);

        $view = substr($html, strpos($html, '<div class="address">'), strpos($html, '<div class="edit_address">') - strpos($html, '<div class="address">'));
        $this->assertStringContainsString('Er referens: Anna', $view);
        $this->assertStringContainsString('value="Anna"', $html);
        $this->assertStringContainsString('data-label="Er referens"', $html, "The checkout's own label wins over the standard one.");
    }

    public function testTheBlockIsRenderedHiddenForOtherPaymentMethods(): void
    {
        $html = $this->render($this->order('cod'));

        $this->assertStringContainsString('display:none', $html);
        $this->assertStringContainsString('briqpay-admin-field', $html);
    }

    public function testAFilterCanAddAField(): void
    {
        WP_Mock::onFilter('briqpay_admin_order_fields')->withAnyArgs()->reply(array(
            array('source' => 'customForm1', 'key' => 'project', 'label' => 'Projekt', 'value' => ''),
        ));

        $html = $this->render($this->order());

        $this->assertStringContainsString('name="briqpay_fields[customForm1][project]"', $html);
        $this->assertStringNotContainsString('[gln]', $html);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Saving
    // ──────────────────────────────────────────────────────────────────────

    private function post(array $fields)
    {
        $_POST[Admin_Order_Fields::POST_KEY] = $fields;
        $_POST['woocommerce_meta_nonce'] = 'n';
        WP_Mock::userFunction('wp_verify_nonce', array('return' => true));
        WP_Mock::userFunction('current_user_can', array('return' => true));
    }

    public function testTypedValuesLandInTheSameMetaTheCheckoutWrites(): void
    {
        $order = $this->order();
        \Briqpay_Test_Orders::register(77, $order);
        $this->post(array('paymentAdditionalFields' => array('reference' => 'PO-1', 'email' => 'faktura@example.test', 'gln' => '')));

        (new Admin_Order_Fields())->save(77);

        $this->assertSame('PO-1', $this->meta['_briqpay_payment_field_reference']);
        $this->assertSame('faktura@example.test', $this->meta['_briqpay_payment_field_email']);
        $this->assertArrayNotHasKey('_briqpay_payment_field_gln', $this->meta);
        $record = json_decode($this->meta[Session_Order_Data::META_FIELDS], true);
        $this->assertSame(array('reference', 'email'), array_column($record, 'key'));
        $this->assertSame('Reference', $record[0]['label']);
        $this->assertSame(1, $this->saves);
        $this->assertStringContainsString('Reference: PO-1', $this->notes[0]);
    }

    public function testNothingIsWrittenWhenNothingChanged(): void
    {
        $order = $this->order('briqpay', array('_briqpay_payment_field_reference' => 'PO-1'));
        \Briqpay_Test_Orders::register(77, $order);
        $this->post(array('paymentAdditionalFields' => array('reference' => 'PO-1', 'gln' => '')));

        (new Admin_Order_Fields())->save(77);

        $this->assertSame(0, $this->saves);
        $this->assertSame(array(), $this->notes);
    }

    public function testClearingAFieldRemovesItsMetaAndItsRecord(): void
    {
        $order = $this->order('briqpay', array(
            '_briqpay_payment_field_reference' => 'PO-1',
            Session_Order_Data::META_FIELDS => json_encode(array(
                array('source' => 'paymentAdditionalFields', 'key' => 'reference', 'label' => 'Er referens', 'value' => 'PO-1'),
            )),
        ));
        \Briqpay_Test_Orders::register(77, $order);
        $this->post(array('paymentAdditionalFields' => array('reference' => '')));

        (new Admin_Order_Fields())->save(77);

        $this->assertArrayNotHasKey('_briqpay_payment_field_reference', $this->meta);
        $this->assertArrayNotHasKey(Session_Order_Data::META_FIELDS, $this->meta);
        $this->assertStringContainsString('(cleared)', $this->notes[0]);
    }

    public function testAnInvalidEmailIsNotStored(): void
    {
        $order = $this->order();
        \Briqpay_Test_Orders::register(77, $order);
        $this->post(array('paymentAdditionalFields' => array('email' => 'not an email')));

        (new Admin_Order_Fields())->save(77);

        $this->assertArrayNotHasKey('_briqpay_payment_field_email', $this->meta);
        $this->assertSame(0, $this->saves);
    }

    public function testUnknownSourcesAndUnsafeKeysAreIgnored(): void
    {
        $order = $this->order();
        \Briqpay_Test_Orders::register(77, $order);
        $this->post(array('evil' => array('x' => 'y'), 'customForm1' => array('***' => 'z')));

        (new Admin_Order_Fields())->save(77);

        $this->assertSame(array(), $this->meta);
        $this->assertSame(0, $this->saves);
    }

    public function testSavingRequiresTheNonce(): void
    {
        $order = $this->order();
        \Briqpay_Test_Orders::register(77, $order);
        $_POST[Admin_Order_Fields::POST_KEY] = array('paymentAdditionalFields' => array('reference' => 'PO-1'));
        $_POST['woocommerce_meta_nonce'] = 'bad';
        WP_Mock::userFunction('wp_verify_nonce', array('return' => false));

        (new Admin_Order_Fields())->save(77);

        $this->assertSame(array(), $this->meta);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Remembering what the checkout delivers
    // ──────────────────────────────────────────────────────────────────────

    public function testTheCheckoutsFieldsAreRememberedWithTheirLatestLabel(): void
    {
        Session_Order_Data::remember_fields(array(array('source' => 'paymentAdditionalFields', 'key' => 'reference', 'label' => 'Referens', 'value' => 'a')));
        Session_Order_Data::remember_fields(array(array('source' => 'paymentAdditionalFields', 'key' => 'reference', 'label' => 'Er referens', 'value' => 'b')));

        $this->assertSame(array('paymentAdditionalFields|reference' => 'Er referens'), Session_Order_Data::seen_fields());
    }

    public function testApplyingCheckoutFieldsRemembersThem(): void
    {
        $source = (new \ReflectionMethod(Session_Order_Data::class, 'apply_custom_fields'));
        $lines = file($source->getFileName());
        $body = implode('', array_slice($lines, $source->getStartLine() - 1, $source->getEndLine() - $source->getStartLine() + 1));

        $this->assertStringContainsString('self::remember_fields($fields);', $body);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hosted payment page
    // ──────────────────────────────────────────────────────────────────────

    private function invoiceDetails($order, $flow)
    {
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'get_invoice_details');
        $method->setAccessible(true);
        return $method->invoke(new Hosted_Payment_Page(), $order, $flow);
    }

    public function testTheInvoiceFieldsAreSentWithABusinessHostedPage(): void
    {
        $order = $this->order('briqpay', array(
            '_briqpay_payment_field_reference' => 'PO-1',
            '_briqpay_payment_field_orderNumber' => '7',
            '_briqpay_payment_field_email' => 'faktura@example.test',
            '_briqpay_payment_field_gln' => '7350000000000',
        ));

        $this->assertSame(
            array('reference' => 'PO-1', 'orderNumber' => '7', 'email' => 'faktura@example.test', 'gln' => '7350000000000'),
            $this->invoiceDetails($order, Hosted_Payment_Page::FLOW_B2B_PAYMENT)
        );
        $this->assertNull($this->invoiceDetails($order, Hosted_Payment_Page::FLOW_B2C), 'The block belongs to the invoice method: business flows only.');
    }

    /**
     * Briqpay refuses the whole session on a value outside its limits, so such
     * a value is left out rather than sent.
     */
    public function testValuesOutsideBriqpaysLimitsAreLeftOut(): void
    {
        $order = $this->order('briqpay', array(
            '_briqpay_payment_field_reference' => 'AB',
            '_briqpay_payment_field_orderNumber' => str_repeat('9', 257),
            '_briqpay_payment_field_email' => 'not-an-email',
            '_briqpay_payment_field_gln' => '7350000000000',
        ));

        $this->assertSame(array('gln' => '7350000000000'), $this->invoiceDetails($order, Hosted_Payment_Page::FLOW_B2B_CHECKOUT));
        $this->assertNull($this->invoiceDetails($this->order('briqpay', array()), Hosted_Payment_Page::FLOW_B2B_CHECKOUT));
    }

    public function testThePayloadBuilderIncludesThem(): void
    {
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'build_session_payload');
        $lines = file($method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString("\$data['data']['invoiceDetails'] = \$invoice_details;", $body);
    }
}
