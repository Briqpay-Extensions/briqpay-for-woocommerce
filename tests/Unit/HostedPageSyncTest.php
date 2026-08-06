<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Hosted_Payment_Page;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Tests for Hosted_Payment_Page::maybe_sync_from_session(), which writes
 * company/address details collected by a B2B Checkout hosted page flow back
 * onto the WooCommerce order.
 */
class HostedPageSyncTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('sanitize_text_field', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testB2bCheckoutOrderSyncsCompanyAndAddressesFromSession()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with(Hosted_Payment_Page::META_HPP_FLOW)->andReturn(Hosted_Payment_Page::FLOW_B2B_CHECKOUT);

        $order->shouldReceive('update_meta_data')->with('_briqpay_company_name', 'Acme AB')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_company_cin', '556677-8899')->once();

        $order->shouldReceive('set_billing_first_name')->with('Jane')->once();
        $order->shouldReceive('set_billing_last_name')->with('Doe')->once();
        $order->shouldReceive('set_billing_address_1')->with('Main St 1')->once();
        $order->shouldReceive('set_billing_postcode')->with('11122')->once();
        $order->shouldReceive('set_billing_city')->with('Stockholm')->once();
        $order->shouldReceive('set_billing_country')->with('SE')->once();
        $order->shouldReceive('set_billing_email')->with('jane@acme.se')->once();
        $order->shouldReceive('set_billing_phone')->with('+46701234567')->once();

        $order->shouldReceive('set_shipping_first_name')->with('Jane')->once();
        $order->shouldReceive('set_shipping_last_name')->with('Doe')->once();
        $order->shouldReceive('set_shipping_address_1')->with('Warehouse St 2')->once();
        $order->shouldReceive('set_shipping_postcode')->with('22233')->once();
        $order->shouldReceive('set_shipping_city')->with('Gothenburg')->once();
        $order->shouldReceive('set_shipping_country')->with('SE')->once();

        $order->shouldReceive('save')->once();

        $session = array(
            'data' => array(
                'company' => array('name' => 'Acme AB', 'cin' => '556677-8899'),
                'billing' => array(
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'streetAddress' => 'Main St 1',
                    'zip' => '11122',
                    'city' => 'Stockholm',
                    'country' => 'SE',
                    'email' => 'jane@acme.se',
                    'phoneNumber' => '+46701234567',
                ),
                'shipping' => array(
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'streetAddress' => 'Warehouse St 2',
                    'zip' => '22233',
                    'city' => 'Gothenburg',
                    'country' => 'SE',
                ),
            ),
        );

        $hpp = new Hosted_Payment_Page();
        $hpp->maybe_sync_from_session(array(), $session, $order);
    }

    public function testNonB2bCheckoutFlowsDoNotOverwriteOrderAddresses()
    {
        foreach (array(Hosted_Payment_Page::FLOW_B2C, Hosted_Payment_Page::FLOW_B2B_PAYMENT) as $flow) {
            $order = Mockery::mock('WC_Order');
            $order->shouldReceive('get_meta')->with(Hosted_Payment_Page::META_HPP_FLOW)->andReturn($flow);
            $order->shouldReceive('set_billing_first_name')->never();
            $order->shouldReceive('update_meta_data')->never();
            $order->shouldReceive('save')->never();

            $session = array('data' => array(
                'billing' => array('firstName' => 'Jane'),
                'company' => array('name' => 'Acme AB'),
            ));

            $hpp = new Hosted_Payment_Page();
            $hpp->maybe_sync_from_session(array(), $session, $order);
        }
    }

    public function testSyncIgnoresOrdersWithoutAnHppFlowMeta()
    {
        // A regular storefront Briqpay order (no HPP flow meta at all) must be untouched.
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with(Hosted_Payment_Page::META_HPP_FLOW)->andReturn('');
        $order->shouldReceive('set_billing_first_name')->never();
        $order->shouldReceive('save')->never();

        $session = array('data' => array('billing' => array('firstName' => 'Jane')));

        $hpp = new Hosted_Payment_Page();
        $hpp->maybe_sync_from_session(array(), $session, $order);
    }

    public function testEmptyBriqpayFieldsDoNotBlankExistingOrderValues()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with(Hosted_Payment_Page::META_HPP_FLOW)->andReturn(Hosted_Payment_Page::FLOW_B2B_CHECKOUT);

        $order->shouldReceive('set_billing_first_name')->with('Jane')->once();
        // These are empty in the session payload below and must NOT trigger a
        // call that would blank out whatever the order already has stored.
        $order->shouldReceive('set_billing_address_2')->never();
        $order->shouldReceive('set_billing_state')->never();
        $order->shouldReceive('set_billing_last_name')->never();
        $order->shouldReceive('save')->once();

        $session = array('data' => array(
            'billing' => array(
                'firstName' => 'Jane',
                'lastName' => '',
                'streetAddress2' => '',
                'region' => '',
            ),
        ));

        $hpp = new Hosted_Payment_Page();
        $hpp->maybe_sync_from_session(array(), $session, $order);
    }

    public function testCompanyCinIsOmittedWhenNotProvided()
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with(Hosted_Payment_Page::META_HPP_FLOW)->andReturn(Hosted_Payment_Page::FLOW_B2B_CHECKOUT);
        $order->shouldReceive('update_meta_data')->with('_briqpay_company_name', 'Acme AB')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_company_cin', Mockery::any())->never();
        $order->shouldReceive('save')->once();

        $session = array('data' => array('company' => array('name' => 'Acme AB')));

        $hpp = new Hosted_Payment_Page();
        $hpp->maybe_sync_from_session(array(), $session, $order);
    }
}
