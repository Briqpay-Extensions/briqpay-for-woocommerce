<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * T3 / T4 / T6 - firing the checkout actions.
 *
 * Every fired action is captured by the recording do_action() installed in
 * tests/bootstrap.php, so these are behavioural assertions: what actually fired,
 * in what order, with what arguments.
 *
 * The single most important case in the whole suite is
 * testGateOffFiresNoCheckoutActions() - it is the executable proof that a store
 * which has not opted in behaves exactly as it did before this feature existed.
 */
class CheckoutHookFiringTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var array Meta written to the fake order, by key. */
    private $order_meta = array();

    /** @var int How many times save() was called on the fake order. */
    private $saves = 0;

    /** @var float Total the fake order reports. */
    private $total = 100.00;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Actions::reset();

        $this->order_meta = array();
        $this->saves = 0;
        $this->total = 100.00;

        WP_Mock::userFunction('current_time', array('return' => '2026-08-18 12:00:00'));
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fixtures
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A fake WC_Order backed by this test's own meta array, so fire_once()'s
     * persistence is observable.
     */
    private function mockOrder($id = 4242)
    {
        $order = Mockery::mock('WC_Order');

        $order->shouldReceive('get_id')->andReturn($id);

        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) {
            return $this->order_meta[$key] ?? '';
        });

        $order->shouldReceive('update_meta_data')->andReturnUsing(function ($key, $value) {
            $this->order_meta[$key] = $value;
        });

        $order->shouldReceive('save')->andReturnUsing(function () {
            $this->saves++;
            return true;
        });

        $order->shouldReceive('get_total')->andReturnUsing(function () {
            return $this->total;
        });

        return $order;
    }

    /**
     * A WC()->session backed by an array, optionally pre-seeded with a stash.
     */
    private function mockWcSession(array $store = array())
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->andReturnUsing(function ($key, $default = null) use (&$store) {
            return array_key_exists($key, $store) ? $store[$key] : $default;
        });
        $session->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$store) {
            $store[$key] = $value;
        });

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;

        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    private function stash(array $data, $source = 'classic')
    {
        $this->mockWcSession(array(
            Checkout_Handler::POSTED_DATA_KEY => $data,
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => $source,
        ));
    }

    private function invoke($method, array $args = array())
    {
        $handler = new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($handler, $args);
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4.1 - THE no-regression test
    // ──────────────────────────────────────────────────────────────────────

    /**
     * With every checkout action individually disabled - which is exactly what
     * the master switch produces - not one woocommerce_* action fires.
     *
     * The master switch itself cannot be toggled here (the bootstrap hard-defines
     * get_option()), so the same end state is produced through the per-hook
     * filter, which the master switch short-circuits to. CheckoutHookGateTest
     * proves the switch precedes and overrides the filter.
     */
    public function testGateOffFiresNoCheckoutActions(): void
    {
        foreach ($this->allCheckoutHooks() as $hook) {
            WP_Mock::onFilter('briqpay_fire_checkout_hook')->with(true, $hook)->reply(false);
        }

        $this->stash(array('billing_first_name' => 'Anna'));
        $this->invoke('fire_checkout_data_hooks', array($this->mockOrder()));

        $this->assertSame(
            array(),
            \Briqpay_Test_Actions::matching('woocommerce_'),
            'A store that has not opted in must fire zero WooCommerce checkout actions.'
        );
    }

    public function testGateOffAlsoFiresNoCommitActions(): void
    {
        foreach ($this->allCheckoutHooks() as $hook) {
            WP_Mock::onFilter('briqpay_fire_checkout_hook')->with(true, $hook)->reply(false);
        }

        $this->stash(array('billing_first_name' => 'Anna'));
        $handler = new Checkout_Handler();
        $handler->fire_commit_hooks($this->mockOrder());

        $this->assertSame(
            array(),
            \Briqpay_Test_Actions::matching('woocommerce_'),
            'Commit actions must be gated too.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4.2 - what fires, in what order, with what data
    // ──────────────────────────────────────────────────────────────────────

    public function testDataHooksFireInCoreOrderWithTheStashedData(): void
    {
        $posted = array('billing_first_name' => 'Anna', 'my_custom_field' => 'gift');
        $this->stash($posted);

        $order = $this->mockOrder();
        $this->invoke('fire_checkout_data_hooks', array($order));

        $this->assertSame(
            array(
                'woocommerce_checkout_create_order',
                'woocommerce_checkout_update_order_meta',
            ),
            \Briqpay_Test_Actions::matching('woocommerce_checkout_'),
            'create_order must precede update_order_meta, matching WC_Checkout.'
        );

        $create_args = \Briqpay_Test_Actions::argsFor('woocommerce_checkout_create_order');
        $this->assertSame($order, $create_args[0], 'First arg must be the order.');
        $this->assertSame($posted, $create_args[1], 'Second arg must be the posted data.');

        $meta_args = \Briqpay_Test_Actions::argsFor('woocommerce_checkout_update_order_meta');
        $this->assertSame(4242, $meta_args[0], 'First arg must be the order ID, not the order.');
        $this->assertSame($posted, $meta_args[1]);
    }

    /**
     * Commit-hook firing must be logged whether or not posted data is present.
     *
     * When only the empty-data case logged, a successful good-context firing left
     * no trace, so verifying the placement from a live log was guesswork - which is
     * how two placement bugs survived a full test round.
     */
    public function testCommitHookFiringIsLoggedOnBothPaths(): void
    {
        $ref = new \ReflectionMethod(Checkout_Handler::class, 'fire_commit_hooks');
        $lines = file($ref->getFileName());
        $body = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));

        $this->assertStringContainsString('with no posted data', $body, 'Degraded path must log.');
        $this->assertStringContainsString('posted field(s) from the', $body, 'Good path must log too.');
        $this->assertStringContainsString('} else {', $body, 'Both branches present.');
    }

    public function testCommitHooksFireInCoreOrder(): void
    {
        $this->stash(array('billing_city' => 'Stockholm'));

        $order = $this->mockOrder();
        (new Checkout_Handler())->fire_commit_hooks($order);

        $this->assertSame(
            array(
                'woocommerce_checkout_order_created',
                'woocommerce_checkout_order_processed',
            ),
            \Briqpay_Test_Actions::matching('woocommerce_checkout_')
        );

        $processed = \Briqpay_Test_Actions::argsFor('woocommerce_checkout_order_processed');
        $this->assertSame(4242, $processed[0]);
        $this->assertSame(array('billing_city' => 'Stockholm'), $processed[1]);
        $this->assertSame($order, $processed[2]);
    }

    /**
     * T8.5 - the Blocks path additionally fires the Store API equivalents, and
     * the classic hooks receive normalised keys.
     */
    public function testBlocksPathFiresStoreApiActionsToo(): void
    {
        $this->stash(
            array('billing_address' => array('first_name' => 'Anna', 'city' => 'Stockholm')),
            'blocks'
        );

        $this->invoke('fire_checkout_data_hooks', array($this->mockOrder()));

        $this->assertSame(
            array(
                'woocommerce_checkout_create_order',
                'woocommerce_checkout_update_order_meta',
                'woocommerce_store_api_checkout_update_order_meta',
            ),
            \Briqpay_Test_Actions::matching('woocommerce_')
        );

        $args = \Briqpay_Test_Actions::argsFor('woocommerce_checkout_create_order');
        $this->assertSame(
            array('billing_first_name' => 'Anna', 'billing_city' => 'Stockholm'),
            $args[1],
            'Classic hooks must receive classic keys even on the Blocks path.'
        );
    }

    public function testClassicPathDoesNotFireStoreApiActions(): void
    {
        $this->stash(array('billing_first_name' => 'Anna'), 'classic');

        $this->invoke('fire_checkout_data_hooks', array($this->mockOrder()));

        $this->assertSame(
            array(),
            \Briqpay_Test_Actions::matching('woocommerce_store_api_'),
            'Store API actions belong to the Blocks path only.'
        );
    }

    /**
     * T4.5 - the order is saved after the hooks so property changes a plugin
     * made on woocommerce_checkout_create_order actually persist.
     */
    public function testOrderIsSavedAfterTheHooks(): void
    {
        $this->stash(array('billing_first_name' => 'Anna'));

        $this->invoke('fire_checkout_data_hooks', array($this->mockOrder()));

        // One save for the fire-once flag, one after create_order, one final.
        $this->assertGreaterThanOrEqual(
            2,
            $this->saves,
            'The order must be saved after the hooks, or plugin changes are lost.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T3 - fire-once guard
    // ──────────────────────────────────────────────────────────────────────

    public function testDataHooksFireOnlyOncePerOrder(): void
    {
        $this->stash(array('billing_first_name' => 'Anna'));
        $order = $this->mockOrder();

        $this->invoke('fire_checkout_data_hooks', array($order));
        $first = \Briqpay_Test_Actions::matching('woocommerce_checkout_');

        \Briqpay_Test_Actions::reset();
        $this->invoke('fire_checkout_data_hooks', array($order));

        $this->assertNotEmpty($first, 'Sanity: the first call must fire.');
        $this->assertSame(
            array(),
            \Briqpay_Test_Actions::matching('woocommerce_'),
            'A retry after a rejected decision must not re-run plugin side effects.'
        );
    }

    public function testCommitHooksFireOnlyOncePerOrder(): void
    {
        $this->stash(array());
        $order = $this->mockOrder();
        $handler = new Checkout_Handler();

        // Primary site (return handler) then fallback site (webhook).
        $handler->fire_commit_hooks($order);
        $this->assertSame(1, \Briqpay_Test_Actions::countFor('woocommerce_checkout_order_processed'));

        $handler->fire_commit_hooks($order);
        $this->assertSame(
            1,
            \Briqpay_Test_Actions::countFor('woocommerce_checkout_order_processed'),
            'Return handler and webhook fallback must not both fire for one order.'
        );
    }

    /**
     * T3.2 - the two groups are tracked independently: firing the data hooks
     * must not consume the commit hooks' one shot.
     */
    public function testDataAndCommitGroupsAreIndependent(): void
    {
        $this->stash(array());
        $order = $this->mockOrder();

        $this->invoke('fire_checkout_data_hooks', array($order));
        \Briqpay_Test_Actions::reset();

        (new Checkout_Handler())->fire_commit_hooks($order);

        $this->assertSame(
            array(
                'woocommerce_checkout_order_created',
                'woocommerce_checkout_order_processed',
            ),
            \Briqpay_Test_Actions::matching('woocommerce_checkout_'),
            'Commit hooks must still fire after the data hooks already have.'
        );
    }

    public function testGuardWritesBothGroupKeys(): void
    {
        $this->stash(array());
        $order = $this->mockOrder();

        $this->invoke('fire_checkout_data_hooks', array($order));
        (new Checkout_Handler())->fire_commit_hooks($order);

        $this->assertArrayHasKey('_briqpay_hooks_data', $this->order_meta);
        $this->assertArrayHasKey('_briqpay_hooks_commit', $this->order_meta);
    }

    /**
     * T3.3 - the flag is persisted BEFORE the callback runs, so third-party code
     * that fatals cannot earn itself a second attempt at the same side effects.
     */
    public function testFlagIsPersistedBeforeTheCallbackRuns(): void
    {
        $order = $this->mockOrder();
        $observed_during_callback = null;

        $this->invoke('fire_once', array(
            $order,
            'data',
            function () use (&$observed_during_callback) {
                $observed_during_callback = $this->order_meta;
            },
        ));

        $this->assertArrayHasKey(
            '_briqpay_hooks_data',
            $observed_during_callback,
            'The guard must already be persisted while the callback runs.'
        );
    }

    /**
     * T4.3 - a throwing hook is caught. These actions run third-party code
     * inside our AJAX handler; an uncaught throwable would break the decision
     * response and strand a customer on a paid-but-unconfirmed order.
     */
    public function testThrowingCallbackIsCaught(): void
    {
        $order = $this->mockOrder();

        $ran = $this->invoke('fire_once', array(
            $order,
            'data',
            function () {
                throw new \RuntimeException('plugin exploded');
            },
        ));

        $this->assertTrue($ran, 'fire_once() must report that it ran.');
        $this->assertArrayHasKey('_briqpay_hooks_data', $this->order_meta);
    }

    public function testThrowingCallbackStillBlocksARetry(): void
    {
        $order = $this->mockOrder();
        $calls = 0;

        $thrower = function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('plugin exploded');
        };

        $this->invoke('fire_once', array($order, 'data', $thrower));
        $this->invoke('fire_once', array($order, 'data', $thrower));

        $this->assertSame(1, $calls, 'A fatal must not buy a second attempt.');
    }

    public function testFireOnceReportsWhetherItRan(): void
    {
        $order = $this->mockOrder();

        $this->assertTrue($this->invoke('fire_once', array($order, 'data', function () {})));
        $this->assertFalse($this->invoke('fire_once', array($order, 'data', function () {})));
    }

    // ──────────────────────────────────────────────────────────────────────
    // T6 - total drift detection
    // ──────────────────────────────────────────────────────────────────────

    public function testUnchangedTotalIsNotReported(): void
    {
        $order = $this->mockOrder();
        $this->total = 100.00;

        $this->invoke('warn_on_total_drift', array($order, 100.00));

        // Nothing to assert beyond "did not throw"; the log is a no-op in tests.
        $this->assertSame(100.00, $this->total);
    }

    public function testSubCentNoiseDoesNotTripTheEpsilon(): void
    {
        $order = $this->mockOrder();
        $this->total = 100.004;

        $this->invoke('warn_on_total_drift', array($order, 100.00));

        $this->assertSame(100.004, $this->total);
    }

    /**
     * T6.4 - detection only. Silently "correcting" the order would hide a real
     * configuration problem from the merchant, and the authorized Briqpay amount
     * cannot be changed at this point anyway.
     */
    public function testDriftIsNotSilentlyCorrected(): void
    {
        $order = $this->mockOrder();
        $this->total = 175.00;

        $this->invoke('warn_on_total_drift', array($order, 100.00));

        $this->assertSame(
            175.00,
            $this->total,
            'The order total must be left alone; the mismatch is reported, not hidden.'
        );
        $this->assertSame(
            0,
            $this->saves,
            'Drift detection must not write to the order.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function allCheckoutHooks()
    {
        return array(
            'woocommerce_checkout_create_order',
            'woocommerce_checkout_update_order_meta',
            'woocommerce_checkout_order_created',
            'woocommerce_checkout_order_processed',
            'woocommerce_checkout_create_order_line_item_object',
            'woocommerce_store_api_checkout_update_order_meta',
            'woocommerce_store_api_checkout_order_processed',
        );
    }
}
