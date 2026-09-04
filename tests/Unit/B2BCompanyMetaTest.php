<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\B2b_Checkout;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Tests for B2B Company Metadata (v1.0.12)
 *
 * Covers:
 *  - save_company_metadata() saves name + CIN from session
 *  - save_company_metadata() is a no-op when B2B is not active
 *  - save_company_metadata() skips missing fields gracefully
 *  - display_company_in_admin() outputs correct HTML
 *  - display_company_in_admin() outputs nothing when meta is absent
 */
class B2BCompanyMetaTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var B2b_Checkout */
    private $b2b;

    public static $wc_return          = null;
    public static $wc_received_return = false;
    public static $ajax_return        = false;
    public static $referer_return     = '';

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        self::$wc_return          = null;
        self::$wc_received_return = false;
        self::$ajax_return        = false;
        self::$referer_return     = '';

        // Dynamic mocks shared across all tests
        WP_Mock::userFunction('WC', [
            'return' => function () { return self::$wc_return; },
        ]);
        WP_Mock::userFunction('is_order_received_page', [
            'return' => function () { return self::$wc_received_return; },
        ]);
        WP_Mock::userFunction('wp_doing_ajax', [
            'return' => function () { return self::$ajax_return; },
        ]);
        WP_Mock::userFunction('wp_get_raw_referer', [
            'return' => function () { return self::$referer_return; },
        ]);

        WP_Mock::userFunction('is_singular',  ['return' => false]);
        WP_Mock::userFunction('has_shortcode', ['return' => false]);
        WP_Mock::userFunction('__',            ['return_arg' => 0]);
        WP_Mock::userFunction('apply_filters', ['return_arg' => 1]);
        WP_Mock::userFunction('esc_html',      ['return_arg' => 0]);
        WP_Mock::userFunction('esc_html__',    ['return_arg' => 0]);
        WP_Mock::userFunction('sanitize_text_field', ['return_arg' => 0]);

        $post               = new \stdClass();
        $post->post_content = '';
        WP_Mock::userFunction('get_post', ['return' => $post]);

        $this->b2b = new B2b_Checkout();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Put a WC session mock in place that marks B2B as active.
     */
    private function activateB2B(): void
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_b2b_active')->andReturn(true);

        $wc          = Mockery::mock('WooCommerce');
        $wc->session = $session;

        self::$wc_return = $wc;
    }

    /**
     * Put a WC mock in place with B2B inactive (null session value).
     */
    private function deactivateB2B(): void
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_b2b_active')->andReturn(false);

        $wc          = Mockery::mock('WooCommerce');
        $wc->session = $session;

        self::$wc_return = $wc;
    }

    /**
     * Build a minimal Briqpay session array.
     */
    private function makeSession(array $companyData = []): array
    {
        return [
            'sessionId' => 'sess_test_123',
            'data'      => [
                'company' => $companyData,
            ],
        ];
    }

    /**
     * Build a mock WC_Order (no expectations — used purely as a pass-through).
     */
    private function mockOrder(): \Mockery\MockInterface
    {
        return Mockery::mock('WC_Order');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // save_company_metadata()
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Both name and CIN are present → both stored in metadata.
     */
    public function testSaveCompanyMetadataSavesNameAndCin(): void
    {
        $this->activateB2B();

        $session  = $this->makeSession(['name' => 'Acme AB', 'cin' => '556677-8899']);
        $order    = $this->mockOrder();
        $metadata = [];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayHasKey('_briqpay_company_name', $result);
        $this->assertArrayHasKey('_briqpay_company_cin',  $result);
        $this->assertSame('Acme AB',      $result['_briqpay_company_name']);
        $this->assertSame('556677-8899',  $result['_briqpay_company_cin']);
    }

    /**
     * Only name present (no CIN) → only name stored.
     */
    public function testSaveCompanyMetadataWithNameOnly(): void
    {
        $this->activateB2B();

        $session  = $this->makeSession(['name' => 'Widgets & Co']);
        $order    = $this->mockOrder();
        $metadata = [];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayHasKey('_briqpay_company_name', $result);
        $this->assertArrayNotHasKey('_briqpay_company_cin', $result);
    }

    /**
     * Only CIN present (no name) → only CIN stored.
     */
    public function testSaveCompanyMetadataWithCinOnly(): void
    {
        $this->activateB2B();

        $session  = $this->makeSession(['cin' => '999999-0000']);
        $order    = $this->mockOrder();
        $metadata = [];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayNotHasKey('_briqpay_company_name', $result);
        $this->assertArrayHasKey('_briqpay_company_cin', $result);
        $this->assertSame('999999-0000', $result['_briqpay_company_cin']);
    }

    /**
     * Company data is completely absent → existing metadata untouched.
     */
    public function testSaveCompanyMetadataWithEmptyCompanyData(): void
    {
        $this->activateB2B();

        $session  = $this->makeSession([]);   // no name, no cin
        $order    = $this->mockOrder();
        $metadata = ['some_existing_key' => 'value'];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayNotHasKey('_briqpay_company_name', $result);
        $this->assertArrayNotHasKey('_briqpay_company_cin',  $result);
        $this->assertArrayHasKey('some_existing_key', $result, 'Existing metadata must be preserved');
    }

    /**
     * B2B is NOT active → filter is a no-op, metadata unchanged.
     */
    public function testSaveCompanyMetadataIsNoOpWhenB2bInactive(): void
    {
        $this->deactivateB2B();

        $session  = $this->makeSession(['name' => 'Should Not Appear', 'cin' => '000000-0001']);
        $order    = $this->mockOrder();
        $metadata = [];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayNotHasKey('_briqpay_company_name', $result);
        $this->assertArrayNotHasKey('_briqpay_company_cin',  $result);
        $this->assertSame([], $result);
    }

    /**
     * Existing metadata keys are preserved when new keys are added.
     */
    public function testSaveCompanyMetadataPreservesExistingMetadata(): void
    {
        $this->activateB2B();

        $session  = $this->makeSession(['name' => 'Preserv AB', 'cin' => '111111-2222']);
        $order    = $this->mockOrder();
        $metadata = ['_briqpay_psp_name' => 'Klarna', '_briqpay_session_id' => 'sess_abc'];

        $result = $this->b2b->save_company_metadata($metadata, $order, $session);

        $this->assertArrayHasKey('_briqpay_psp_name',    $result);
        $this->assertArrayHasKey('_briqpay_session_id',  $result);
        $this->assertArrayHasKey('_briqpay_company_name', $result);
        $this->assertArrayHasKey('_briqpay_company_cin',  $result);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // display_company_in_admin()
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Both name and CIN present → HTML block is rendered.
     */
    public function testDisplayCompanyInAdminRendersBlock(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Acme AB');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('556677-8899');

        ob_start();
        $this->b2b->display_company_in_admin($order);
        $html = ob_get_clean();

        $this->assertStringContainsString('briqpay-company-info', $html);
        $this->assertStringContainsString('Acme AB',              $html);
        $this->assertStringContainsString('556677-8899',          $html);
    }

    /**
     * Only name present → rendered without CIN section.
     */
    public function testDisplayCompanyInAdminRendersNameOnly(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Name Only AB');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');

        ob_start();
        $this->b2b->display_company_in_admin($order);
        $html = ob_get_clean();

        $this->assertStringContainsString('Name Only AB', $html);
        $this->assertStringNotContainsString('CIN:',     $html);
    }

    /**
     * Both meta values are empty → nothing rendered.
     */
    public function testDisplayCompanyInAdminRendersNothingWhenEmpty(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');

        ob_start();
        $this->b2b->display_company_in_admin($order);
        $html = ob_get_clean();

        $this->assertSame('', $html, 'No output expected when company meta is empty');
    }

    /**
     * When the legacy B2B meta mapping toggle is enabled, Legacy_B2b_Meta
     * renders its own "Billing Organization Number" field for this order, so
     * this box must be a no-op to avoid showing the CIN twice.
     */
    public function testDisplayCompanyInAdminIsNoOpWhenLegacyMappingEnabled(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldNotReceive('get_meta');

        // get_option() is hard-defined once by tests/bootstrap.php and can't
        // be overridden per-test (see LegacyB2bMetaTest), so the "enabled"
        // state is passed via the test seam instead of toggling the real
        // setting.
        ob_start();
        $this->b2b->display_company_in_admin($order, true);
        $html = ob_get_clean();

        $this->assertSame('', $html, 'No output expected when legacy mapping owns the display');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // display_company_in_emails()
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Both name and CIN present → HTML block is rendered in the HTML email.
     */
    public function testDisplayCompanyInEmailsRendersHtmlBlock(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Acme AB');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('556677-8899');

        ob_start();
        $this->b2b->display_company_in_emails($order, false, false, null);
        $html = ob_get_clean();

        $this->assertStringContainsString('Acme AB', $html);
        $this->assertStringContainsString('556677-8899', $html);
    }

    /**
     * Plain-text emails must not get HTML markup.
     */
    public function testDisplayCompanyInEmailsRendersPlainText(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Acme AB');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('556677-8899');

        ob_start();
        $this->b2b->display_company_in_emails($order, false, true, null);
        $text = ob_get_clean();

        $this->assertStringContainsString('Acme AB', $text);
        $this->assertStringContainsString('556677-8899', $text);
        $this->assertStringNotContainsString('<', $text, 'Plain-text emails must not contain HTML tags');
    }

    /**
     * No company meta on the order → nothing rendered, in either admin or
     * customer emails (sent_to_admin is irrelevant to whether the data exists).
     */
    public function testDisplayCompanyInEmailsRendersNothingWhenEmpty(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');

        ob_start();
        $this->b2b->display_company_in_emails($order, true, false, null);
        $html = ob_get_clean();

        $this->assertSame('', $html, 'No output expected when company meta is empty');
    }

    /**
     * Unlike display_company_in_admin(), this is not gated on legacy mapping -
     * Legacy_B2b_Meta renders nothing in emails, so there is nothing to
     * duplicate.
     */
    public function testDisplayCompanyInEmailsIgnoresLegacyMappingToggle(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_briqpay_company_name')->andReturn('Acme AB');
        $order->shouldReceive('get_meta')->with('_briqpay_company_cin')->andReturn('');
        $order->shouldReceive('get_meta')->with('_billing_org_nr')->andReturn('');

        ob_start();
        $this->b2b->display_company_in_emails($order, false, false, null);
        $html = ob_get_clean();

        $this->assertStringContainsString('Acme AB', $html);
    }
}
