<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Session_Order_Data;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Session data that has to reach the order: the shipping recipient's company and
 * the merchant's own checkout fields (reference, own order number, alternative
 * email). The shipping company used to be overwritten with the buyer's company
 * name, and the custom fields were never read at all.
 */
class SessionOrderDataTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_textarea_field', array('return_arg' => 0));
        WP_Mock::userFunction('sanitize_key', array(
            'return' => function ($key) {
                return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));
            },
        ));
        WP_Mock::userFunction('wp_json_encode', array(
            'return' => function ($value) {
                return json_encode($value);
            },
        ));
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function session(array $data)
    {
        return array('data' => $data);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Shipping company
    // ──────────────────────────────────────────────────────────────────────

    public function testShippingCompanyComesFromTheShippingBlock(): void
    {
        $session = $this->session(array(
            'company' => array('name' => 'Buyer AB'),
            'shipping' => array('companyName' => 'Recipient AB'),
        ));

        $this->assertSame('Recipient AB', Session_Order_Data::shipping_company($session));
    }

    public function testShippingCompanyFallsBackToTheBuyingCompany(): void
    {
        $session = $this->session(array(
            'company' => array('name' => 'Buyer AB'),
            'shipping' => array('streetAddress' => 'Street 1'),
        ));

        $this->assertSame('Buyer AB', Session_Order_Data::shipping_company($session));
    }

    public function testBlankShippingCompanyAlsoFallsBack(): void
    {
        $session = $this->session(array(
            'company' => array('name' => 'Buyer AB'),
            'shipping' => array('companyName' => '  '),
        ));

        $this->assertSame('Buyer AB', Session_Order_Data::shipping_company($session));
    }

    public function testNoCompanyAnywhereGivesEmpty(): void
    {
        $this->assertSame('', Session_Order_Data::shipping_company($this->session(array())));
    }

    /**
     * Every site that maps the session onto the customer or the order must take
     * the shipping company from the helper, never from company.name directly.
     */
    public function testCheckoutHandlerNoLongerCopiesTheBuyerCompanyToShipping(): void
    {
        $source = file_get_contents(BRIQPAY_WC_PATH . 'includes/class-checkout-handler.php');

        $this->assertDoesNotMatchRegularExpression(
            '/set_shipping_company\(sanitize_text_field\(\$company_name\)\)/',
            $source
        );
        $this->assertSame(3, substr_count($source, 'Session_Order_Data::shipping_company($session)'));
        $this->assertSame(2, substr_count($source, 'Session_Order_Data::apply_custom_fields($order, $session)'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Custom fields
    // ──────────────────────────────────────────────────────────────────────

    private function fieldSession()
    {
        return $this->session(array(
            'orderNote' => array(
                'reference' => array('value' => 'Anna Andersson', 'header' => 'Er referens'),
                'orderNumber' => array('value' => 'PO-4711', 'header' => 'Eget ordernummer'),
                'altEmail' => array('value' => 'faktura@acme.se', 'header' => 'Alternativ e-post'),
                'empty' => array('value' => '', 'header' => 'Unused'),
            ),
            'customForm1' => array(
                'wantsCall' => array('value' => true, 'header' => 'Ring mig'),
            ),
        ));
    }

    public function testFieldsAreReadFromOrderNoteAndCustomForm(): void
    {
        $fields = Session_Order_Data::custom_fields($this->fieldSession());

        $this->assertSame(
            array(
                array('source' => 'orderNote', 'key' => 'reference', 'label' => 'Er referens', 'value' => 'Anna Andersson'),
                array('source' => 'orderNote', 'key' => 'ordernumber', 'label' => 'Eget ordernummer', 'value' => 'PO-4711'),
                array('source' => 'orderNote', 'key' => 'altemail', 'label' => 'Alternativ e-post', 'value' => 'faktura@acme.se'),
                array('source' => 'customForm1', 'key' => 'wantscall', 'label' => 'Ring mig', 'value' => 'Yes'),
            ),
            $fields
        );
    }

    public function testKeyIsTheLabelWhenBriqpaySendsNoHeader(): void
    {
        $fields = Session_Order_Data::custom_fields($this->session(array(
            'orderNote' => array('reference' => array('value' => 'X')),
        )));

        $this->assertSame('reference', $fields[0]['label']);
    }

    public function testObjectValuedFieldsAreFlattened(): void
    {
        $fields = Session_Order_Data::custom_fields($this->session(array(
            'orderNote' => array('delivery' => array('date' => '2026-10-01', 'slot' => 'AM', 'header' => 'Leverans')),
        )));

        $this->assertSame('2026-10-01, AM', $fields[0]['value']);
        $this->assertSame('Leverans', $fields[0]['label']);
    }

    public function testUnexpectedShapesAreIgnored(): void
    {
        $this->assertSame(array(), Session_Order_Data::custom_fields($this->session(array('orderNote' => 'text'))));
        $this->assertSame(array(), Session_Order_Data::custom_fields(array()));
    }

    public function testFieldsAreWrittenAsMetaAndOneOrderNote(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');

        $order->shouldReceive('update_meta_data')->with('_briqpay_order_note_reference', 'Anna Andersson')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_order_note_ordernumber', 'PO-4711')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_order_note_altemail', 'faktura@acme.se')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_custom_form_wantscall', 'Yes')->once();
        $order->shouldReceive('update_meta_data')->with(Session_Order_Data::META_FIELDS, Mockery::type('string'))->once();

        $order->shouldReceive('add_order_note')->once()->with(Mockery::on(function ($note) {
            return false !== strpos($note, 'Er referens: Anna Andersson')
                && false !== strpos($note, 'Eget ordernummer: PO-4711')
                && false !== strpos($note, 'Alternativ e-post: faktura@acme.se');
        }));

        Session_Order_Data::apply_custom_fields($order, $this->fieldSession());
    }

    /**
     * Hosted page sync runs on every webhook; unchanged values must not add
     * another note each time.
     */
    public function testUnchangedFieldsAddNoSecondNote(): void
    {
        $session = $this->fieldSession();
        $stored = json_encode(Session_Order_Data::custom_fields($session));

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn($stored);
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note')->never();

        Session_Order_Data::apply_custom_fields($order, $session);

        $this->assertTrue(true);
    }

    public function testNoFieldsTouchesNothing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('update_meta_data')->never();
        $order->shouldReceive('add_order_note')->never();

        Session_Order_Data::apply_custom_fields($order, $this->session(array()));

        $this->assertTrue(true);
    }
}
