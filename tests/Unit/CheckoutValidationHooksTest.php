<?php
namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $errors = array();
            public $error_data = array();
            public function __construct($code = '', $message = '', $data = '') {
                if ('' !== $code) { $this->add($code, $message, $data); }
            }
            public function add($code, $message, $data = '') { $this->errors[$code][] = $message; if ('' !== $data) { $this->error_data[$code] = $data; } }
            public function get_error_code() { $codes = array_keys($this->errors); return $codes ? $codes[0] : ''; }
            public function get_error_message($code = '') { $code = $code ?: $this->get_error_code(); return isset($this->errors[$code][0]) ? $this->errors[$code][0] : ''; }
            public function get_error_messages($code = '') { if ($code) { return $this->errors[$code] ?? array(); } $all = array(); foreach ($this->errors as $m) { $all = array_merge($all, $m); } return $all; }
            public function get_error_data($code = '') { $code = $code ?: $this->get_error_code(); return $this->error_data[$code] ?? null; }
        }
    }
}

namespace Briqpay\WooCommerce\Tests\Unit {

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * The hooks WooCommerce's own process_checkout() fires that the plugin did
 * not: the validation hooks a plugin uses to refuse a purchase, the customer
 * hooks, the order-resume and customer-id filters and the payment-result
 * filter. Audit of WC_Checkout 10.8.1 on 2026-10-08, after Swemed reported
 * woocommerce_checkout_process never running on Briqpay orders.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class CheckoutValidationHooksTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var array */
    private $notices = array();

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Actions::reset();
        \Briqpay_Test_Orders::reset();
        \Briqpay_Test_Options::reset();
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('wp_strip_all_tags', array('return' => function ($s) {
            return strip_tags($s);
        }));
        WP_Mock::userFunction('is_email', array('return' => function ($s) {
            return false !== strpos((string) $s, '@');
        }));
        $this->notices = array();
        WP_Mock::userFunction('wc_get_notices', array('return' => function ($type = '') {
            if ('' === $type) {
                return $this->notices;
            }
            return $this->notices[$type] ?? array();
        }));
        WP_Mock::userFunction('wc_set_notices', array('return' => function ($notices) {
            $this->notices = $notices;
        }));
        WP_Mock::userFunction('wc_add_notice', array('return' => function ($message, $type = 'success') {
            $this->notices[$type][] = array('notice' => $message, 'data' => array());
        }));
    }

    public function tearDown(): void
    {
        \Briqpay_Test_Options::reset();
        \Briqpay_Test_Orders::reset();
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function hooks($enabled)
    {
        // The bootstrap's get_option() reads this store; a WP_Mock userFunction
        // cannot replace a function that already exists.
        \Briqpay_Test_Options::$store['woocommerce_briqpay_settings'] = array('checkout_hooks_enabled' => $enabled ? 'yes' : 'no', 'logging' => 'no');
    }

    private function stash(array $data, $source = 'classic', $user_id = 0)
    {
        $store = array(
            Checkout_Handler::POSTED_DATA_KEY => $data,
            Checkout_Handler::POSTED_DATA_SOURCE_KEY => $source,
        );
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
        WP_Mock::userFunction('get_current_user_id', array('return' => $user_id));
    }

    private function invoke($method, array $args = array(), $handler = null)
    {
        $handler = $handler ?: new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($handler, $args);
    }

    private function methodSource($name)
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, $name);
        $lines = file($method->getFileName());
        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Validation hooks
    // ──────────────────────────────────────────────────────────────────────

    public function testValidationHooksFireInCoreOrderAndLetAValidPurchaseThrough(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'));

        $messages = $this->invoke('fire_validation_hooks');

        $this->assertSame(array(), $messages);
        $this->assertSame(array(
            'woocommerce_before_checkout_process',
            'woocommerce_checkout_process',
            'woocommerce_check_cart_items',
            'woocommerce_after_checkout_validation',
        ), \Briqpay_Test_Actions::matching('woocommerce_'));

        $args = \Briqpay_Test_Actions::argsFor('woocommerce_after_checkout_validation');
        $this->assertSame(array('billing_first_name' => 'Anna'), $args[0]);
        $this->assertInstanceOf(\WP_Error::class, $args[1]);
    }

    /**
     * @dataProvider provideSilentCases
     */
    public function testNothingFiresWhenGatedOffOrForBlocks($enabled, $source): void
    {
        $this->hooks($enabled);
        $this->stash(array('billing_first_name' => 'Anna'), $source);

        $this->assertSame(array(), $this->invoke('fire_validation_hooks'));
        $this->assertSame(array(), \Briqpay_Test_Actions::matching('woocommerce_'));
    }

    public function provideSilentCases()
    {
        return array(
            'setting off, classic' => array(false, 'classic'),
            'setting on, blocks (core fires none of these for Blocks)' => array(true, 'blocks'),
        );
    }

    /**
     * The common refusal: a plugin calls wc_add_notice(..., 'error') on
     * woocommerce_checkout_process. The message reaches the customer, and the
     * page's own notices are left as they were.
     */
    public function testAnErrorNoticeFromAHookRefusesThePurchase(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'));
        $this->notices = array('success' => array(array('notice' => 'Coupon applied', 'data' => array())));
        \Briqpay_Test_Actions::listen('woocommerce_checkout_process', function () {
            wc_add_notice('<strong>Please choose a delivery date.</strong>', 'error');
        });

        $messages = $this->invoke('fire_validation_hooks');

        $this->assertSame(array('Please choose a delivery date.'), $messages);
        $this->assertSame(array('success' => array(array('notice' => 'Coupon applied', 'data' => array()))), $this->notices, 'Notices restored; the decision answers the iframe, not a page.');
    }

    public function testAnErrorAddedOnAfterCheckoutValidationRefusesThePurchase(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'));
        \Briqpay_Test_Actions::listen('woocommerce_after_checkout_validation', function ($data, $errors) {
            $this->assertSame(array('billing_first_name' => 'Anna'), $data);
            $errors->add('sunday', 'No delivery on Sundays.');
        });

        $this->assertSame(array('No delivery on Sundays.'), $this->invoke('fire_validation_hooks'));
    }

    /**
     * Core catches the exception and shows its message; the later hooks never
     * run, because the throw left process_checkout().
     */
    public function testAThrowingHookRefusesThePurchaseAndStopsTheSequence(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'));
        \Briqpay_Test_Actions::listen('woocommerce_checkout_process', function () {
            throw new \Exception('Your account is on hold.');
        });

        $this->assertSame(array('Your account is on hold.'), $this->invoke('fire_validation_hooks'));
        $this->assertSame(array('woocommerce_before_checkout_process', 'woocommerce_checkout_process'), \Briqpay_Test_Actions::matching('woocommerce_'));
    }

    public function testThePostedDataFilterFeedsEveryLaterHook(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'));
        WP_Mock::onFilter('woocommerce_checkout_posted_data')
            ->with(array('billing_first_name' => 'Anna'))
            ->reply(array('billing_first_name' => 'Anna', 'delivery_date' => '2026-10-10'));

        $handler = new Checkout_Handler();
        $this->invoke('fire_validation_hooks', array(), $handler);

        $args = \Briqpay_Test_Actions::argsFor('woocommerce_after_checkout_validation');
        $this->assertSame('2026-10-10', $args[0]['delivery_date']);
        $this->assertSame('2026-10-10', $this->invoke('get_hook_data', array(), $handler)['delivery_date'], 'The order hooks later in the decision see the filtered data too.');
    }

    public function testTheDecisionRefusesWithTheHookMessagesAndOnlyAfterItsOwnCheckPassed(): void
    {
        $source = $this->methodSource('ajax_make_decision');

        $this->assertStringContainsString("\$hook_errors = \$validation['valid'] ? \$this->fire_validation_hooks() : array();", $source);
        $this->assertStringContainsString("in_array(\$err, \$whitelist, true) || in_array(\$err, \$hook_errors, true)", $source, 'Hook messages are user-facing, like core notices.');
        $this->assertLessThan(strpos($source, '$initial_decision = \'allow\';'), strpos($source, '$this->fire_validation_hooks()'), 'Decided before the decision value is built.');
        $this->assertLessThan(strpos($source, '$this->fire_validation_hooks()'), strpos($source, '$validation = $this->validate_data_integrity($session);'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Customer hooks
    // ──────────────────────────────────────────────────────────────────────

    public function testAGuestGetsOnlyUpdateUserMetaWithCustomerZero(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna'), 'classic', 0);

        $this->invoke('fire_customer_hooks', array(array('billing_first_name' => 'Anna')));

        $this->assertSame(array('woocommerce_checkout_update_user_meta'), \Briqpay_Test_Actions::matching('woocommerce_'));
        $this->assertSame(array(0, array('billing_first_name' => 'Anna')), \Briqpay_Test_Actions::argsFor('woocommerce_checkout_update_user_meta'));
    }

    public function testALoggedInCustomerIsUpdatedLikeCoreDoes(): void
    {
        $this->hooks(true);
        $this->stash(array('billing_first_name' => 'Anna', 'billing_city' => 'Lund', 'order_comments' => 'ring'), 'classic', 7);

        $customer = Mockery::mock('overload:WC_Customer');
        // Any other set_* core would call for a posted key is accepted silently,
        // as the real WC_Customer's own setters would be.
        $customer->shouldIgnoreMissing();
        $customer->shouldReceive('get_first_name')->andReturn('');
        $customer->shouldReceive('get_last_name')->andReturn('');
        $customer->shouldReceive('get_display_name')->andReturn('anna@example.test');
        $customer->shouldReceive('set_first_name')->with('Anna')->atLeast()->once();
        $customer->shouldReceive('set_display_name')->once();
        $customer->shouldReceive('set_billing_first_name')->with('Anna')->once();
        $customer->shouldReceive('set_billing_city')->with('Lund')->once();
        $customer->shouldReceive('save')->once();

        $this->invoke('fire_customer_hooks', array(array('billing_first_name' => 'Anna', 'billing_city' => 'Lund', 'order_comments' => 'ring')));

        $this->assertSame(array('woocommerce_checkout_update_customer', 'woocommerce_checkout_update_user_meta'), \Briqpay_Test_Actions::matching('woocommerce_'));
        $this->assertSame(7, \Briqpay_Test_Actions::argsFor('woocommerce_checkout_update_user_meta')[0]);
    }

    public function testCustomerHooksRunBeforeCreateOrderInTheDataHooks(): void
    {
        $source = $this->methodSource('fire_checkout_data_hooks');

        $this->assertLessThan(
            strpos($source, "do_action('woocommerce_checkout_create_order'"),
            strpos($source, '$this->fire_customer_hooks($data);'),
            'Core: process_customer() runs right before create_order().'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Filters around order creation and the redirect
    // ──────────────────────────────────────────────────────────────────────

    public function testTheCustomerIdFilterIsApplied(): void
    {
        $this->hooks(true);
        $this->stash(array(), 'classic', 3);
        WP_Mock::onFilter('woocommerce_checkout_customer_id')->with(3)->reply(9);

        $this->assertSame(9, $this->invoke('checkout_customer_id'));
    }

    public function testAnOrderHandedBackByCreateOrderFilterIsResumed(): void
    {
        $this->hooks(true);
        $this->stash(array());
        WP_Mock::onFilter('woocommerce_create_order')->withAnyArgs()->reply(77);
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with(array('pending', 'failed'))->andReturn(true);
        $order->shouldReceive('remove_order_items')->once();
        \Briqpay_Test_Orders::register(77, $order);

        $this->assertSame($order, $this->invoke('resume_order_from_filter'));
        $this->assertSame(array(77), \Briqpay_Test_Actions::argsFor('woocommerce_resume_order'));
    }

    public function testAnOrderThatIsNotPendingIsNotResumed(): void
    {
        $this->hooks(true);
        $this->stash(array());
        WP_Mock::onFilter('woocommerce_create_order')->withAnyArgs()->reply(77);
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->andReturn(false);
        $order->shouldNotReceive('remove_order_items');
        \Briqpay_Test_Orders::register(77, $order);

        $this->assertNull($this->invoke('resume_order_from_filter'));
    }

    public function testThePaymentResultFilterCanChangeTheRedirect(): void
    {
        $this->hooks(true);
        $this->stash(array());
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(5);
        $order->shouldReceive('get_checkout_order_received_url')->andReturn('https://shop.test/thanks/5');
        WP_Mock::onFilter('woocommerce_payment_successful_result')
            ->with(array('result' => 'success', 'redirect' => 'https://shop.test/thanks/5'), 5)
            ->reply(array('result' => 'success', 'redirect' => 'https://shop.test/upsell'));

        $this->assertSame('https://shop.test/upsell', $this->invoke('payment_success_redirect', array($order)));
    }

    public function testThePaymentResultFilterIsGated(): void
    {
        $this->hooks(false);
        $this->stash(array());
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(5);
        $order->shouldReceive('get_checkout_order_received_url')->andReturn('https://shop.test/thanks/5');

        $this->assertSame('https://shop.test/thanks/5', $this->invoke('payment_success_redirect', array($order)));
    }

    public function testBothReturnExitsUseTheFilteredRedirect(): void
    {
        $source = $this->methodSource('handle_briqpay_return');

        $this->assertSame(2, substr_count($source, 'wp_safe_redirect($this->payment_success_redirect($order));'));
        $this->assertStringNotContainsString('wp_safe_redirect($order->get_checkout_order_received_url())', $source);
    }
}
}
