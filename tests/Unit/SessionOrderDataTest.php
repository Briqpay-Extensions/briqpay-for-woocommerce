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
        // The two order-building paths, plus the return handler's guarded call -
        // see testTheReturnHandlerAppliesTheFieldsAgain().
        $this->assertSame(2, substr_count($source, 'Session_Order_Data::apply_custom_fields($order, $session)'));
        $this->assertSame(1, substr_count($source, 'Session_Order_Data::try_apply_custom_fields($order, $session)'));
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
                array('source' => 'orderNote', 'key' => 'orderNumber', 'label' => 'Eget ordernummer', 'value' => 'PO-4711'),
                array('source' => 'orderNote', 'key' => 'altEmail', 'label' => 'Alternativ e-post', 'value' => 'faktura@acme.se'),
                array('source' => 'customForm1', 'key' => 'wantsCall', 'label' => 'Ring mig', 'value' => 'Yes'),
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
        $order->shouldReceive('update_meta_data')->with('_briqpay_order_note_orderNumber', 'PO-4711')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_order_note_altEmail', 'faktura@acme.se')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_custom_form_wantsCall', 'Yes')->once();
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
        // Nothing at all is written, not just no note: the webhook saves only when
        // this returns true, so an unchanged session must leave the order untouched.
        $order->shouldReceive('update_meta_data')->never();
        $order->shouldReceive('add_order_note')->never();

        $this->assertFalse(Session_Order_Data::apply_custom_fields($order, $session));
    }

    public function testNoFieldsTouchesNothing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('update_meta_data')->never();
        $order->shouldReceive('add_order_note')->never();

        $this->assertFalse(Session_Order_Data::apply_custom_fields($order, $this->session(array())));
    }

    // ──────────────────────────────────────────────────────────────────────
    // paymentAdditionalFields - the invoice method's own block
    //
    // Reported by Klinikinredning Sverige AB against 1.1.19: the reference, own
    // order number and alternative invoice email were still lost on every order.
    // They are not in orderNote or customForm1 at all - they are the payment
    // method's own fields, and Briqpay fills them in 1-95 seconds AFTER the
    // decision, which is when the order is built.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The exact shape Splunk shows for this merchant.
     */
    private function paymentFieldSession()
    {
        return $this->session(array(
            'paymentAdditionalFields' => array(
                'reference' => array('value' => 'Anna Andersson', 'header' => 'Er referens'),
                'customerOrderNumber' => array('value' => 'PO-4711', 'header' => 'Ert ordernummer'),
                'email' => array('value' => 'faktura@acme.se', 'header' => 'Fakturamail'),
                'gln' => array('value' => '7350000000001', 'header' => 'GLN'),
            ),
        ));
    }

    public function testPaymentAdditionalFieldsAreRead(): void
    {
        $fields = Session_Order_Data::custom_fields($this->paymentFieldSession());

        $this->assertSame(
            array(
                array('source' => 'paymentAdditionalFields', 'key' => 'reference', 'label' => 'Er referens', 'value' => 'Anna Andersson'),
                array('source' => 'paymentAdditionalFields', 'key' => 'customerOrderNumber', 'label' => 'Ert ordernummer', 'value' => 'PO-4711'),
                array('source' => 'paymentAdditionalFields', 'key' => 'email', 'label' => 'Fakturamail', 'value' => 'faktura@acme.se'),
                array('source' => 'paymentAdditionalFields', 'key' => 'gln', 'label' => 'GLN', 'value' => '7350000000001'),
            ),
            $fields
        );
    }

    public function testPaymentFieldsGetTheirOwnMetaKeys(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data')->with(Session_Order_Data::META_FIELDS, Mockery::type('string'))->once();
        $order->shouldReceive('add_order_note')->once();

        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_field_reference', 'Anna Andersson')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_field_customerOrderNumber', 'PO-4711')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_field_email', 'faktura@acme.se')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_field_gln', '7350000000001')->once();

        $this->assertTrue(Session_Order_Data::apply_custom_fields($order, $this->paymentFieldSession()));
    }

    /**
     * sanitize_key() would lowercase this to customerordernumber. An integration
     * reading the meta has to be able to predict the key from the Briqpay field
     * name, so Briqpay's own casing is what gets stored.
     */
    public function testBriqpayKeyCasingIsPreserved(): void
    {
        $fields = Session_Order_Data::custom_fields($this->paymentFieldSession());

        $this->assertSame('customerOrderNumber', $fields[1]['key']);
    }

    /**
     * Characters that are unsafe in a meta key still go, and a key left with
     * nothing at all is dropped rather than stored under a bare prefix.
     */
    public function testUnsafeKeyCharactersAreStillRemoved(): void
    {
        $fields = Session_Order_Data::custom_fields($this->session(array(
            'paymentAdditionalFields' => array(
                'our ref/2' => array('value' => 'X'),
                '///' => array('value' => 'dropped'),
            ),
        )));

        $this->assertCount(1, $fields);
        $this->assertSame('ourref2', $fields[0]['key']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The customer note
    // ──────────────────────────────────────────────────────────────────────

    public function testTheOrderNoteBecomesTheCustomerNote(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note');
        $order->shouldReceive('get_customer_note')->andReturn('');

        $order->shouldReceive('set_customer_note')->with('Leave at reception')->once();

        Session_Order_Data::apply_custom_fields($order, $this->session(array(
            'orderNote' => array('note' => array('value' => 'Leave at reception', 'header' => 'Meddelande')),
        )));
    }

    /**
     * A note the customer typed into WooCommerce's own checkout form must win over
     * a later Briqpay sync.
     */
    public function testAnExistingCustomerNoteIsKept(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note');
        $order->shouldReceive('get_customer_note')->andReturn('Typed in the WooCommerce form');

        $order->shouldReceive('set_customer_note')->never();

        Session_Order_Data::apply_custom_fields($order, $this->session(array(
            'orderNote' => array('note' => array('value' => 'Leave at reception')),
        )));

        $this->assertTrue(true);
    }

    /**
     * Only orderNote.note is the customer's message. A payment field that happens
     * to be called "note" is not, and must not overwrite it.
     */
    public function testOnlyTheOrderNoteSourceSetsTheCustomerNote(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note');

        $order->shouldReceive('set_customer_note')->never();

        Session_Order_Data::apply_custom_fields($order, $this->session(array(
            'paymentAdditionalFields' => array('note' => array('value' => 'Not the customer note')),
        )));

        $this->assertTrue(true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Applied again after the decision
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The order is built at the decision, before Briqpay has filled the payment
     * fields in. The return handler is the first chance to pick them up.
     */
    public function testTheReturnHandlerAppliesTheFieldsAgain(): void
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, 'handle_briqpay_return');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $apply_pos = strpos($body, 'Session_Order_Data::try_apply_custom_fields($order, $session)');
        $save_pos = strpos($body, '$order->save();');

        $this->assertNotFalse($apply_pos, 'The return handler must re-apply the checkout fields.');
        $this->assertNotFalse($save_pos);
        $this->assertLessThan($save_pos, $apply_pos, 'The fields must be applied before the order is saved.');
    }

    /**
     * A customer who never comes back to the store has no return handler, so the
     * webhook is the only path left. It has to run inside the order lock and after
     * the re-read, or it writes onto a stale order and races the status handling.
     */
    public function testTheWebhookAppliesTheFieldsUnderTheLock(): void
    {
        $source = file_get_contents(BRIQPAY_WC_PATH . 'includes/class-webhooks.php');

        $lock_pos = strpos($source, 'Lock::acquire_wait($lock');
        $reload_pos = strpos($source, 'Order_Management::reload_order($order)');
        $apply_pos = strpos($source, 'Session_Order_Data::try_apply_custom_fields($order, $session)');
        $route_pos = strpos($source, "if ('capture' === \$action");

        $this->assertNotFalse($apply_pos, 'The webhook must apply the checkout fields.');
        $this->assertLessThan($apply_pos, $lock_pos, 'It must run inside the order lock.');
        $this->assertLessThan($apply_pos, $reload_pos, 'It must run after the order is re-read.');
        $this->assertLessThan($route_pos, $apply_pos, 'It must run before the status routing.');
    }

    /**
     * Storing a reference field must never cost the customer their order
     * confirmation or the merchant a payment-status update. Both new call sites
     * run before that work, so a throw here has to be swallowed.
     */
    public function testAThrowIsSwallowedAndReportedAsNoChange(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note')->andThrow(new \RuntimeException('database is gone'));

        $this->assertFalse(
            Session_Order_Data::try_apply_custom_fields($order, $this->paymentFieldSession()),
            'A failure must report "nothing to save" rather than escape.'
        );
    }

    /**
     * The wrapper only catches - it must not change the answer on the happy path,
     * or the webhook would stop saving.
     */
    public function testTheGuardedCallStillReportsARealChange(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with(Session_Order_Data::META_FIELDS)->andReturn('');
        $order->shouldReceive('update_meta_data');
        $order->shouldReceive('add_order_note');

        $this->assertTrue(Session_Order_Data::try_apply_custom_fields($order, $this->paymentFieldSession()));
    }

    /**
     * Both call sites must use the guarded wrapper, not the raw method.
     */
    public function testBothPostDecisionCallSitesAreGuarded(): void
    {
        foreach (array('includes/class-checkout-handler.php', 'includes/class-webhooks.php') as $file) {
            $source = file_get_contents(BRIQPAY_WC_PATH . $file);

            $this->assertStringContainsString(
                'Session_Order_Data::try_apply_custom_fields($order, $session)',
                $source,
                $file . ' must apply the fields through the guarded wrapper.'
            );
        }
    }

    /**
     * Saving only on a real change is what stops a redelivered webhook writing a
     * second identical order note.
     */
    public function testTheWebhookOnlySavesWhenSomethingChanged(): void
    {
        $source = file_get_contents(BRIQPAY_WC_PATH . 'includes/class-webhooks.php');

        $this->assertStringContainsString(
            'if (Session_Order_Data::try_apply_custom_fields($order, $session)) {',
            $source,
            'The webhook must save only when apply_custom_fields() reports a change.'
        );
    }
}
