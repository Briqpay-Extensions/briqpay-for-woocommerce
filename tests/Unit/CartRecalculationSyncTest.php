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

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * sync_after_cart_recalculation() - the backstop that reconciles the Briqpay
 * session inside whichever single request actually recalculated the cart,
 * instead of relying on a second, separate request to catch up with it.
 *
 * These tests are about the GATING: whether the sync fires at all in a given
 * context. Session_Manager is overload-mocked throughout, since the actual
 * payload building and network call are its own responsibility and are
 * covered by its own test suite - here every test asserts only whether
 * sync_if_changed() was reached, which is what the hook's own logic decides.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class CartRecalculationSyncTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface Overloads every `new Session_Manager()` and static call. */
    private $sessionManager;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $this->sessionManager = Mockery::mock('overload:Briqpay\WooCommerce\Session_Manager');
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array $overrides Override any of the default "everything says
     *                         yes, sync" conditions, to isolate one gate at a
     *                         time.
     */
    private function mockConditions(array $overrides = array())
    {
        $c = array_merge(array(
            'doing_ajax' => true,
            'session_id' => 'sess_123',
            'is_checkout' => true,
            'is_order_received_page' => false,
            'is_cart' => false,
            'chosen_payment_method' => 'briqpay',
        ), $overrides);

        WP_Mock::userFunction('wp_doing_ajax', array('return' => $c['doing_ajax']));
        WP_Mock::userFunction('is_checkout', array('return' => $c['is_checkout']));
        WP_Mock::userFunction('is_order_received_page', array('return' => $c['is_order_received_page']));
        WP_Mock::userFunction('is_cart', array('return' => $c['is_cart']));

        $this->sessionManager->shouldReceive('get_session_id')->andReturn($c['session_id']);

        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('chosen_payment_method')->andReturn($c['chosen_payment_method']);

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    private function invoke($handler = null)
    {
        $handler = $handler ?: new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, 'sync_after_cart_recalculation');
        $ref->setAccessible(true);
        $ref->invoke($handler, Mockery::mock('WC_Cart'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The case this exists for
    // ──────────────────────────────────────────────────────────────────────

    public function testSyncsWhenEverythingSaysThisIsALiveBriqpayCheckout(): void
    {
        $this->mockConditions();
        $this->sessionManager->shouldReceive('sync_if_changed')->once()->with('sess_123')
            ->andReturn(array('sessionId' => 'sess_123'));

        $this->invoke();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Every gate that must independently block the sync
    // ──────────────────────────────────────────────────────────────────────

    public function testDoesNotSyncOutsideAnAjaxRequest(): void
    {
        // The checkout page's own first render already gets a correct session
        // from the browser's initial call moments later; doing it here too
        // would add a synchronous Briqpay round trip to that page's first paint.
        $this->mockConditions(array('doing_ajax' => false));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testDoesNotCreateASessionThatDoesNotExistYet(): void
    {
        // Creating one is the browser-triggered flow's job - it has to hand
        // back a snippet to render, which this hook has no request to answer.
        $this->mockConditions(array('session_id' => null));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testDoesNotSyncOffTheCheckoutPage(): void
    {
        // A product page or a mini-cart widget also calls calculate_totals();
        // neither has anything to do with an in-progress payment.
        $this->mockConditions(array('is_checkout' => false));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testDoesNotSyncOnTheOrderReceivedPage(): void
    {
        $this->mockConditions(array('is_order_received_page' => true));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testDoesNotSyncOnTheCartPage(): void
    {
        // is_checkout() can report true on the cart page too (the B2B
        // shortcode's force_is_checkout filter) - is_cart() is the
        // authoritative exclusion, matching enqueue_critical_assets() above.
        $this->mockConditions(array('is_cart' => true));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testDoesNotSyncWhenAnotherGatewayIsChosen(): void
    {
        $this->mockConditions(array('chosen_payment_method' => 'cod'));
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    public function testCanBeDisabledByFilterAsAnEscapeHatch(): void
    {
        $this->mockConditions();
        WP_Mock::onFilter('briqpay_sync_on_cart_recalculation')->with(true)->reply(false);
        $this->sessionManager->shouldReceive('sync_if_changed')->never();

        $this->invoke();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Safety
    // ──────────────────────────────────────────────────────────────────────

    /**
     * This runs inside requests that have nothing to do with payment failure
     * handling - a shipping method change, a coupon. A problem here must never
     * surface as a broken checkout for the customer.
     */
    public function testASessionManagerExceptionDoesNotEscapeTheHook(): void
    {
        $this->mockConditions();
        $this->sessionManager->shouldReceive('sync_if_changed')->once()
            ->andThrow(new \RuntimeException('API unreachable'));

        $this->invoke();

        $this->assertTrue(true, 'sync_after_cart_recalculation() must swallow the throw.');
    }

    /**
     * sync_if_changed() returning a WP_Error (the normal failure path, not an
     * exception) must be logged and stepped over the same way.
     */
    public function testAWpErrorFromUpdateSessionDoesNotEscapeTheHook(): void
    {
        $this->mockConditions();
        WP_Mock::userFunction('is_wp_error', array('return' => true));
        $this->sessionManager->shouldReceive('sync_if_changed')->once()
            ->andReturn(new \WP_Error('x', 'nope'));

        $this->invoke();

        $this->assertTrue(true);
    }

    /**
     * sync_if_changed() never itself recalculates the cart, so this should be
     * unreachable in practice - the guard exists for a future change elsewhere
     * that might accidentally make it recurse.
     */
    public function testReentrantCallIsIgnored(): void
    {
        $this->mockConditions();
        $handler = new Checkout_Handler();

        // Capped at 3 rather than left to recurse freely: if the guard this
        // pins is ever removed, the method calls itself with nothing to stop
        // it, and an uncapped mock here would hang the test suite instead of
        // failing it fast. The count is asserted directly ($calls) rather than
        // via Mockery's own once()/times() bookkeeping, which this recursive
        // self-call pattern (the mocked method's own return callback invoking
        // the method under test again) does not appear to track correctly.
        $calls = 0;
        $this->sessionManager->shouldReceive('sync_if_changed')->andReturnUsing(function () use ($handler, &$calls) {
            $calls++;
            // A second recalculation happening while the first sync from this
            // hook is still "in flight".
            if ($calls < 3) {
                $this->invoke($handler);
            }
            return array('sessionId' => 'sess_123');
        });

        $this->invoke($handler);

        $this->assertSame(
            1,
            $calls,
            'A recalculation triggered from within an in-progress sync must not start a second one.'
        );
    }
}

}
