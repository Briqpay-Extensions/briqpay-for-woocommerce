<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Legacy_B2b_Meta;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Tests for Legacy_B2b_Meta.
 *
 * Covers:
 *  - is_enabled() default (via get_option())
 *  - is_b2b_session() detection from the session payload alone
 *  - apply() writes the legacy meta keys, gated on both of the above
 *  - get_company_cin() read-side fallback, always ungated
 *
 * Note: get_option() is hard-defined once by tests/bootstrap.php with a
 * fixed settings array that never includes 'legacy_b2b_meta_mapping', and
 * WP_Mock::userFunction() cannot override an already-real function (same
 * constraint documented in AdminOrderMetaBoxTest). So is_enabled() can only
 * be exercised for its real, always-false-by-default outcome here; the
 * "toggle on" branch of apply() is exercised via its $enabled test seam
 * instead of by faking get_option().
 */
class LegacyB2bMetaTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('sanitize_text_field', ['return_arg' => 0]);
        WP_Mock::userFunction('sanitize_email', ['return_arg' => 0]);
        WP_Mock::userFunction('sanitize_key', ['return_arg' => 0]);
        WP_Mock::userFunction('wp_unslash', ['return_arg' => 0]);
        WP_Mock::userFunction('wp_json_encode', [
            'return' => function ($value) { return json_encode($value); },
        ]);
        WP_Mock::userFunction('__', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_html__', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_html', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_attr', ['return_arg' => 0]);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function b2bSession(array $companyData = [], array $overrides = []): array
    {
        return [
            'sessionId' => 'sess_123',
            'data' => array_merge([
                'company' => $companyData,
                'billing' => ['email' => 'billing@example.com', 'phoneNumber' => '+46700000000'],
                'shipping' => [],
                'transactions' => [['autoCaptureEnabled' => false]],
            ], $overrides),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // is_enabled()
    // ──────────────────────────────────────────────────────────────────────

    public function testIsEnabledFalseByDefault(): void
    {
        // The bootstrap's fixed settings array never includes the key, and
        // existing installs must not change behaviour on upgrade.
        $this->assertFalse(Legacy_B2b_Meta::is_enabled());
    }

    // ──────────────────────────────────────────────────────────────────────
    // is_b2b_session()
    // ──────────────────────────────────────────────────────────────────────

    public function testIsB2bSessionTrueForTopLevelCustomerType(): void
    {
        $session = ['customerType' => 'business', 'data' => []];
        $this->assertTrue(Legacy_B2b_Meta::is_b2b_session($session));
    }

    public function testIsB2bSessionTrueForNestedCustomerType(): void
    {
        $session = ['data' => ['customerType' => 'business']];
        $this->assertTrue(Legacy_B2b_Meta::is_b2b_session($session));
    }

    public function testIsB2bSessionTrueForCompanyCinOnly(): void
    {
        $session = ['data' => ['company' => ['cin' => '556677-8899']]];
        $this->assertTrue(Legacy_B2b_Meta::is_b2b_session($session));
    }

    public function testIsB2bSessionTrueForCompanyNameOnly(): void
    {
        $session = ['data' => ['company' => ['name' => 'Acme AB']]];
        $this->assertTrue(Legacy_B2b_Meta::is_b2b_session($session));
    }

    public function testIsB2bSessionFalseForEmptySession(): void
    {
        $session = ['data' => []];
        $this->assertFalse(Legacy_B2b_Meta::is_b2b_session($session));
    }

    public function testIsB2bSessionFalseForExplicitConsumerSession(): void
    {
        $session = ['customerType' => 'consumer', 'data' => ['company' => []]];
        $this->assertFalse(Legacy_B2b_Meta::is_b2b_session($session));
    }

    // ──────────────────────────────────────────────────────────────────────
    // apply()
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Exercises the real is_enabled() (always false in this harness), not
     * the test seam - proving apply() is a no-op with the setting untouched.
     */
    public function testApplyIsNoOpWhenToggleIsOff(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldNotReceive('update_meta_data');
        $order->shouldNotReceive('set_shipping_phone');

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899', 'name' => 'Acme AB']));
    }

    public function testApplyIsNoOpForB2cSessionEvenWhenToggleIsOn(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldNotReceive('update_meta_data');

        $session = [
            'customerType' => 'consumer',
            'data' => ['company' => [], 'billing' => [], 'shipping' => []],
        ];

        Legacy_B2b_Meta::apply($order, $session, true);
    }

    public function testApplyWritesOrgNumberFromCompanyCin(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('get_payment_method_title')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', '556677-8899')->once();
        $order->shouldReceive('update_meta_data')->with('_shipping_email', 'billing@example.com')->once();
        $order->shouldReceive('set_shipping_phone')->with('+46700000000')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', 'Klarna')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', '')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any())->once();

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899', 'name' => 'Acme AB']), true);
    }

    public function testApplyShippingEmailFallsBackToBillingEmailWhenShippingEmailAbsent(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_shipping_email', 'billing@example.com')->once();
        $order->shouldReceive('set_shipping_phone')->with('+46700000000');
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899']), true);
    }

    public function testApplyDoesNotWriteShippingEmailWhenNoneAvailable(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());
        $order->shouldNotReceive('update_meta_data')->with('_shipping_email', Mockery::any());

        $session = $this->b2bSession(['cin' => '556677-8899'], ['billing' => [], 'shipping' => []]);

        Legacy_B2b_Meta::apply($order, $session, true);
    }

    /**
     * A stub without set_shipping_phone() must not blow up - the is_callable()
     * guard exists precisely for WC versions below 5.6, which don't have it.
     */
    public function testApplySkipsShippingPhoneWhenOrderLacksMethod(): void
    {
        $order = new class {
            public $meta = [];
            public function get_id() { return 99; }
            public function get_meta($key) { return $key === '_briqpay_psp_name' ? 'Klarna' : ''; }
            public function get_payment_method_title() { return 'Klarna'; }
            public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        };

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899']), true);

        $this->assertSame('556677-8899', $order->meta['_billing_org_nr']);
    }

    public function testApplyAutocaptureWrittenAsOneWhenSessionHasAutoCapture(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_shipping_email', Mockery::any());
        $order->shouldReceive('set_shipping_phone')->with(Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', 1)->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());

        $session = $this->b2bSession(['cin' => '556677-8899'], ['transactions' => [['autoCaptureEnabled' => true]]]);

        Legacy_B2b_Meta::apply($order, $session, true);
    }

    public function testApplyWritesPspUpdateOrderSupportedOnlyWhenTrue(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_shipping_email', Mockery::any());
        $order->shouldReceive('set_shipping_phone')->with(Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_psp_update_order_supported', true)->once();

        $session = $this->b2bSession(['cin' => '556677-8899'], [
            'transactions' => [[
                'autoCaptureEnabled' => false,
                'pspSupportedOrderOperations' => ['updateOrderSupported' => true],
            ]],
        ]);

        Legacy_B2b_Meta::apply($order, $session, true);
    }

    public function testApplyDoesNotWritePspUpdateOrderSupportedWhenAbsent(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('Klarna');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_shipping_email', Mockery::any());
        $order->shouldReceive('set_shipping_phone')->with(Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());
        $order->shouldNotReceive('update_meta_data')->with('_briqpay_psp_update_order_supported', Mockery::any());

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899']), true);
    }

    public function testApplyFallsBackToPaymentMethodTitleWhenPspNameMetaMissing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_meta')->with('_briqpay_psp_name')->andReturn('');
        $order->shouldReceive('get_payment_method_title')->andReturn('Briqpay');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_shipping_email', Mockery::any());
        $order->shouldReceive('set_shipping_phone')->with(Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_payment_method', 'Briqpay')->once();
        $order->shouldReceive('update_meta_data')->with('_briqpay_autocapture', Mockery::any());
        $order->shouldReceive('update_meta_data')->with('_briqpay_rules_result', Mockery::any());

        Legacy_B2b_Meta::apply($order, $this->b2bSession(['cin' => '556677-8899']), true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // get_company_cin() - always ungated
    // ──────────────────────────────────────────────────────────────────────

    public function testGetCompanyCinPrefersCurrentKey(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('556677-8899');

        $this->assertSame('556677-8899', Legacy_B2b_Meta::get_company_cin($order));
    }

    public function testGetCompanyCinFallsBackToLegacyKey(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('998877-6655');

        $this->assertSame('998877-6655', Legacy_B2b_Meta::get_company_cin($order));
    }

    public function testGetCompanyCinReturnsEmptyStringWhenNeitherKeyPresent(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');

        $this->assertSame('', Legacy_B2b_Meta::get_company_cin($order));
    }

    /**
     * Proves the read fallback works even though is_enabled() is false in
     * this harness by default - it must never depend on the toggle.
     */
    public function testGetCompanyCinFallbackWorksRegardlessOfToggleState(): void
    {
        $this->assertFalse(Legacy_B2b_Meta::is_enabled());

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('112233-4455');

        $this->assertSame('112233-4455', Legacy_B2b_Meta::get_company_cin($order));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The admin field: visible on open, and following the payment method
    //
    // Reported: the organisation number only appeared once the billing address
    // was being EDITED, because the field was rendered only as an input inside
    // div.edit_address, which WooCommerce keeps hidden until the pencil is
    // clicked. And on a manual order it did not appear at all until the order
    // had been saved with Briqpay as the payment method.
    // ──────────────────────────────────────────────────────────────────────

    private function mockRenderFunctions(): void
    {
        WP_Mock::userFunction('esc_html_e', ['return' => function ($text) { echo $text; }]);
        WP_Mock::userFunction('woocommerce_wp_text_input', ['return' => function ($args) {
            echo '<input id="' . $args['id'] . '" value="' . $args['value'] . '">';
        }]);
    }

    private function renderOrgNr($org_nr, $payment_method = 'briqpay'): string
    {
        $this->mockRenderFunctions();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn($org_nr);
        $order->shouldReceive('get_payment_method')->andReturn($payment_method);

        ob_start();
        Legacy_B2b_Meta::render_org_nr_field($order);

        return (string) ob_get_clean();
    }

    public function testOrgNumberIsShownBeforeAnyoneClicksEdit(): void
    {
        $html = $this->renderOrgNr('5591202071');

        $view_pos = strpos($html, '<div class="address">');
        $edit_pos = strpos($html, '<div class="edit_address">');

        $this->assertNotFalse($view_pos, 'There must be a view-mode block - that is what shows on open.');
        $this->assertNotFalse($edit_pos, 'The input must still be there for editing.');
        $this->assertLessThan($edit_pos, $view_pos);
        $this->assertStringContainsString('5591202071', substr($html, $view_pos, $edit_pos - $view_pos), 'The number must be in the view block.');
        $this->assertStringNotContainsString('display:none', $html, 'A Briqpay order shows the field.');
    }

    public function testNoViewBlockWhenThereIsNothingToShowYet(): void
    {
        $html = $this->renderOrgNr('');

        $this->assertStringNotContainsString('<div class="address">', $html);
        $this->assertStringContainsString('id="_billing_org_nr"', $html, 'The input stays so a number can be entered.');
    }

    /**
     * Rendered for every order so the dropdown can reveal it, but a non-Briqpay
     * order must not show it on load.
     */
    public function testFieldIsRenderedHiddenForOtherPaymentMethods(): void
    {
        $html = $this->renderOrgNr('5591202071', 'cod');

        $this->assertStringContainsString('briqpay-admin-field', $html, 'The toggle script finds it by this class.');
        $this->assertStringContainsString('display:none', $html);
    }

    public function testTheToggleScriptIsEnqueuedOnOrderScreensOnly(): void
    {
        if (!defined('BRIQPAY_WC_URL')) {
            define('BRIQPAY_WC_URL', 'https://example.test/wp-content/plugins/briqpay/');
        }
        if (!defined('BRIQPAY_WC_VERSION')) {
            define('BRIQPAY_WC_VERSION', 'test');
        }

        $enqueued = [];
        WP_Mock::userFunction('wp_enqueue_script', ['return' => function ($handle) use (&$enqueued) { $enqueued[] = $handle; }]);
        WP_Mock::userFunction('get_current_screen', ['return' => (object) ['post_type' => 'shop_order']]);

        Legacy_B2b_Meta::admin_scripts('woocommerce_page_wc-orders');
        Legacy_B2b_Meta::admin_scripts('post-new.php');
        Legacy_B2b_Meta::admin_scripts('edit.php');

        $this->assertSame(
            ['briqpay-admin-order-fields', 'briqpay-admin-order-fields'],
            $enqueued,
            'HPOS and classic order screens get it; the orders list does not.'
        );
    }

    /**
     * The field is on every order's screen now. Saving a non-Briqpay order with
     * the (empty) field present must not stamp empty meta on it.
     */
    public function testSavingAnEmptyNumberOnAnOrderWithoutOneWritesNothing(): void
    {
        $_POST['_billing_org_nr'] = '';
        $_POST['woocommerce_meta_nonce'] = 'n';
        WP_Mock::userFunction('wp_verify_nonce', ['return' => true]);
        WP_Mock::userFunction('current_user_can', ['return' => true]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');
        $order->shouldNotReceive('update_meta_data');
        $order->shouldNotReceive('save');

        // The test bootstrap's wc_get_order() returns an object it is handed.
        Legacy_B2b_Meta::save_org_nr_field($order);

        unset($_POST['_billing_org_nr'], $_POST['woocommerce_meta_nonce']);
        $this->assertTrue(true);
    }

    public function testATypedNumberIsSaved(): void
    {
        $_POST['_billing_org_nr'] = '5591202071';
        $_POST['woocommerce_meta_nonce'] = 'n';
        WP_Mock::userFunction('wp_verify_nonce', ['return' => true]);
        WP_Mock::userFunction('current_user_can', ['return' => true]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', '5591202071')->once();
        $order->shouldReceive('save')->once();

        Legacy_B2b_Meta::save_org_nr_field($order);

        unset($_POST['_billing_org_nr'], $_POST['woocommerce_meta_nonce']);
        $this->assertTrue(true);
    }

    /**
     * Clearing a number that IS set must still go through.
     */
    public function testClearingAnExistingNumberStillSaves(): void
    {
        $_POST['_billing_org_nr'] = '';
        $_POST['woocommerce_meta_nonce'] = 'n';
        WP_Mock::userFunction('wp_verify_nonce', ['return' => true]);
        WP_Mock::userFunction('current_user_can', ['return' => true]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('5591202071');
        $order->shouldReceive('update_meta_data')->with('_billing_org_nr', '')->once();
        $order->shouldReceive('save')->once();

        Legacy_B2b_Meta::save_org_nr_field($order);

        unset($_POST['_billing_org_nr'], $_POST['woocommerce_meta_nonce']);
        $this->assertTrue(true);
    }
}
