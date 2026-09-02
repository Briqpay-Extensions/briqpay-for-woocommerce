<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Hosted_Payment_Page;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Customer data required before a payment-module hosted page can be created.
 *
 * The payment-module flows load only the `payment` module, so the hosted page shows
 * payment methods and nothing else - the customer cannot enter an address. Briqpay
 * needs it prefilled, and build_session_payload() omits whole blocks when the order
 * cannot supply them: get_billing_address() returns null unless street, city,
 * postcode and country are all present.
 *
 * The old behaviour was a link created successfully, sent to the customer, and then
 * never unlocking. This refuses at creation instead.
 *
 * Business - Full Checkout is exempt: it loads company_lookup, billing and shipping,
 * so Briqpay collects the data and requiring it up front would defeat the flow.
 */
class HostedPagePrefillTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('is_wp_error', array(
            'return' => function ($thing) {
                return $thing instanceof \WP_Error;
            },
        ));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * An order with every field a payment-module flow needs.
     *
     * @param array $overrides Field => value, '' to blank one out.
     */
    private function mockOrder(array $overrides = array())
    {
        $fields = array_merge(array(
            'address_1' => 'Sveavagen 20',
            'city' => 'Stockholm',
            'postcode' => '11157',
            'country' => 'SE',
            'email' => 'anna@example.com',
            'company' => 'Briqpay AB',
        ), $overrides);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(770);
        $order->shouldReceive('get_meta')->andReturn('');

        foreach ($fields as $field => $value) {
            $order->shouldReceive('get_billing_' . $field)->andReturn($value);
        }

        return $order;
    }

    private function validate($order, $flow)
    {
        $hpp = new Hosted_Payment_Page();
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'validate_prefill_for_flow');
        $method->setAccessible(true);
        return $method->invokeArgs($hpp, array($order, $flow));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Complete orders pass
    // ──────────────────────────────────────────────────────────────────────

    public function testACompleteConsumerOrderIsAccepted(): void
    {
        $this->assertTrue($this->validate($this->mockOrder(), Hosted_Payment_Page::FLOW_B2C));
    }

    public function testACompleteBusinessPaymentOrderIsAccepted(): void
    {
        $this->assertTrue($this->validate($this->mockOrder(), Hosted_Payment_Page::FLOW_B2B_PAYMENT));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The reported failure
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testAMissingFieldIsRefused($field, $label): void
    {
        $result = $this->validate(
            $this->mockOrder(array($field => '')),
            Hosted_Payment_Page::FLOW_B2C
        );

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('briqpay_hpp_missing_customer_data', $result->get_error_code());
        $this->assertStringContainsString(
            $label,
            $result->get_error_message(),
            'The message must name the field the merchant has to fill in.'
        );
    }

    public function requiredFieldProvider()
    {
        return array(
            // These four are what make get_billing_address() return null, dropping
            // the whole billing block from the payload.
            'street' => array('address_1', 'billing address'),
            'city' => array('city', 'billing city'),
            'postcode' => array('postcode', 'billing postcode'),
            'country' => array('country', 'billing country'),
            // Not required for the block to be built, but a payment-module page has
            // no way to collect it and the PSPs need it.
            'email' => array('email', 'billing email'),
        );
    }

    public function testWhitespaceOnlyCountsAsMissing(): void
    {
        $result = $this->validate(
            $this->mockOrder(array('city' => '   ')),
            Hosted_Payment_Page::FLOW_B2C
        );

        $this->assertInstanceOf('WP_Error', $result);
    }

    public function testEveryMissingFieldIsListedNotJustTheFirst(): void
    {
        $result = $this->validate(
            $this->mockOrder(array('city' => '', 'postcode' => '', 'email' => '')),
            Hosted_Payment_Page::FLOW_B2C
        );

        $message = $result->get_error_message();

        foreach (array('billing city', 'billing postcode', 'billing email') as $label) {
            $this->assertStringContainsString($label, $message);
        }
    }

    /**
     * The message has to tell the merchant what to do, not just that something is
     * wrong - they are looking at an order screen, not a log.
     */
    public function testTheMessageExplainsWhyAndOffersTheAlternative(): void
    {
        $result = $this->validate(
            $this->mockOrder(array('address_1' => '')),
            Hosted_Payment_Page::FLOW_B2C
        );

        $message = $result->get_error_message();

        $this->assertStringContainsString('payment methods only', $message);
        $this->assertStringContainsString('never unlock', $message);
        $this->assertStringContainsString('Business - Full Checkout', $message);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Business payment-module flow also needs a company
    // ──────────────────────────────────────────────────────────────────────

    public function testBusinessPaymentFlowRequiresACompany(): void
    {
        $result = $this->validate(
            $this->mockOrder(array('company' => '')),
            Hosted_Payment_Page::FLOW_B2B_PAYMENT
        );

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertStringContainsString('company name', $result->get_error_message());
    }

    /**
     * The company may live only in the meta this plugin writes, as it does on orders
     * that came through the B2B storefront checkout.
     */
    public function testTheCompanyMetaFallbackSatisfiesTheRequirement(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(771);
        $order->shouldReceive('get_billing_address_1')->andReturn('Sveavagen 20');
        $order->shouldReceive('get_billing_city')->andReturn('Stockholm');
        $order->shouldReceive('get_billing_postcode')->andReturn('11157');
        $order->shouldReceive('get_billing_country')->andReturn('SE');
        $order->shouldReceive('get_billing_email')->andReturn('anna@example.com');
        $order->shouldReceive('get_billing_company')->andReturn('');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Briqpay AB');
        $order->shouldReceive('get_meta')->andReturn('');

        $this->assertTrue($this->validate($order, Hosted_Payment_Page::FLOW_B2B_PAYMENT));
    }

    /**
     * A consumer flow must not demand a company.
     */
    public function testConsumerFlowDoesNotRequireACompany(): void
    {
        $this->assertTrue($this->validate(
            $this->mockOrder(array('company' => '')),
            Hosted_Payment_Page::FLOW_B2C
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Full Checkout is exempt - this is the point of the feature
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Business - Full Checkout loads company_lookup, billing and shipping, so
     * Briqpay collects the details. Requiring them here would block the one flow
     * that exists to gather them.
     *
     * @dataProvider everyFieldBlankProvider
     */
    public function testFullCheckoutAcceptsAnOrderWithNoCustomerData($overrides): void
    {
        $this->assertTrue(
            $this->validate($this->mockOrder($overrides), Hosted_Payment_Page::FLOW_B2B_CHECKOUT),
            'Full Checkout must not require prefilled customer data.'
        );
    }

    public function everyFieldBlankProvider()
    {
        return array(
            'no address' => array(array('address_1' => '')),
            'no city' => array(array('city' => '')),
            'no email' => array(array('email' => '')),
            'no company' => array(array('company' => '')),
            'nothing at all' => array(array(
                'address_1' => '',
                'city' => '',
                'postcode' => '',
                'country' => '',
                'email' => '',
                'company' => '',
            )),
        );
    }

    /**
     * The exemption is driven off loadModules, not a hardcoded flow name, so a new
     * flow that collects its own data is exempt automatically.
     */
    public function testTheExemptionIsDrivenByLoadModules(): void
    {
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'validate_prefill_for_flow');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString(
            "in_array('billing', \$modules, true)",
            $body,
            'Exemption must follow the modules the flow loads.'
        );
        $this->assertStringNotContainsString(
            'FLOW_B2B_CHECKOUT',
            $body,
            'Hardcoding the flow name would miss any future flow that collects its own data.'
        );
    }

    /**
     * The required set is filterable, for a PSP with different needs.
     */
    public function testTheRequiredFieldsAreFilterable(): void
    {
        $order = $this->mockOrder(array('email' => ''));

        // WP_Mock's filter matcher compares literal arguments - Mockery type
        // matchers do not apply here - so the default set and the exact order
        // instance both have to be spelled out.
        WP_Mock::onFilter('briqpay_hpp_required_prefill_fields')
            ->with(
                array(
                    'address_1' => 'billing address',
                    'city' => 'billing city',
                    'postcode' => 'billing postcode',
                    'country' => 'billing country',
                    'email' => 'billing email',
                ),
                $order,
                Hosted_Payment_Page::FLOW_B2C
            )
            ->reply(array('city' => 'billing city'));

        // Email is blank, but the filter removed it from the requirements.
        $this->assertTrue($this->validate($order, Hosted_Payment_Page::FLOW_B2C));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Wiring
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The guard has to run before the session is built, and before the API call
     * that would otherwise produce an unusable link.
     */
    public function testTheGuardRunsBeforeThePayloadIsBuilt(): void
    {
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'create');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $guard_pos = strpos($body, 'validate_prefill_for_flow($order, $flow)');
        $payload_pos = strpos($body, 'build_session_payload($order, $flow)');

        $this->assertNotFalse($guard_pos, 'create() must run the prefill guard.');
        $this->assertNotFalse($payload_pos);
        $this->assertLessThan($payload_pos, $guard_pos);

        $this->assertStringContainsString(
            'if (is_wp_error($prefill)) {',
            $body,
            'And return the error rather than continuing.'
        );
    }

    /**
     * normalize_flow() must have run first, or an alias would be looked up in
     * get_flows() and find nothing - failing open.
     */
    public function testTheGuardRunsAfterTheFlowIsNormalised(): void
    {
        $method = new \ReflectionMethod(Hosted_Payment_Page::class, 'create');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $normalize_pos = strpos($body, 'self::normalize_flow($flow)');
        $guard_pos = strpos($body, 'validate_prefill_for_flow($order, $flow)');

        $this->assertNotFalse($normalize_pos);
        $this->assertLessThan($guard_pos, $normalize_pos);
    }
}
