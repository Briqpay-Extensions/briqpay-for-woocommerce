<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use Briqpay\WooCommerce\Webhooks;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * T7 / T9 - regression guards.
 *
 * These protect behaviour that existed before the hook parity work and must
 * survive it, plus the placement decisions that are easy to undo by accident.
 */
class CheckoutHookRegressionTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Actions::reset();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function methodSource($class, $name)
    {
        $method = new \ReflectionMethod($class, $name);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // T9.1 - hooks that already fired must keep firing, gate or no gate
    // ──────────────────────────────────────────────────────────────────────

    /**
     * These four item-level actions shipped before the gate existed. Putting
     * them behind it would be a regression for every store that has not opted
     * in - they must stay unconditional.
     */
    public function testPreExistingItemHooksAreNotGated(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision')
            . $this->methodSource(Checkout_Handler::class, 'add_shipping_items_from_cart')
            . $this->methodSource(Checkout_Handler::class, 'add_fee_items_from_cart')
            . $this->methodSource(Checkout_Handler::class, 'add_coupon_items_from_cart');

        $unconditional = array(
            'woocommerce_checkout_create_order_line_item',
            'woocommerce_checkout_create_order_shipping_item',
            'woocommerce_checkout_create_order_fee_item',
            'woocommerce_checkout_create_order_coupon_item',
        );

        foreach ($unconditional as $hook) {
            $this->assertStringContainsString(
                "do_action('" . $hook . "'",
                $source,
                $hook . ' must still be fired.'
            );
            $this->assertStringNotContainsString(
                "hook_enabled('" . $hook . "')",
                $source,
                $hook . ' shipped before the gate and must not become gated.'
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // T7 - line-item object filter
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Core does not type-check this filter's return. We do, because a plugin
     * returning something unusable would fatal inside our AJAX handler rather
     * than merely misbehaving during a normal checkout.
     */
    public function testLineItemObjectFilterIsTypeChecked(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $this->assertStringContainsString(
            "apply_filters(\n                        'woocommerce_checkout_create_order_line_item_object'",
            $source,
            'The filter must be applied at item construction.'
        );
        $this->assertStringContainsString(
            'instanceof \\WC_Order_Item_Product',
            $source,
            'The filter result must be type-checked before use.'
        );
    }

    public function testLineItemObjectFilterIsGated(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $this->assertStringContainsString(
            "hook_enabled('woocommerce_checkout_create_order_line_item_object')",
            $source,
            'This filter is new, so unlike the four item actions it must be gated.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T9.2 - Hosted Payment Pages must stay out of this entirely
    // ──────────────────────────────────────────────────────────────────────

    /**
     * HPP orders are built by an admin and have no cart and no checkout form, so
     * no checkout action should ever fire for them. Stated as a test so nobody
     * later "fixes" the omission by wiring hooks into the HPP flow.
     */
    public function testHostedPaymentPageFiresNoCheckoutActions(): void
    {
        $source = $this->methodSource(Hosted_Payment_Page::class, 'build_session_payload');

        $this->assertStringNotContainsString(
            'woocommerce_checkout_',
            $source,
            'The hosted payment page flow must not fire checkout actions.'
        );
        $this->assertStringNotContainsString(
            'fire_commit_hooks',
            $source,
            'The hosted payment page flow must not fire commit hooks.'
        );
        $this->assertStringNotContainsString(
            'fire_checkout_data_hooks',
            $source
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T9.3 - created_via
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A store that has not opted in must keep the historical 'Briqpay' value.
     * Some plugins gate on created_via === 'checkout', which is exactly why the
     * new value is useful - and exactly why it must not appear unasked.
     */
    public function testCreatedViaIsTiedToTheGate(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $this->assertStringContainsString(
            "checkout_hooks_enabled() ? 'checkout' : 'Briqpay'",
            $source,
            'created_via must follow the gate, not change unconditionally.'
        );
        $this->assertStringContainsString(
            "apply_filters('briqpay_order_created_via'",
            $source,
            'Integrators must be able to override created_via either way.'
        );
    }

    /**
     * Neither value affects Hosted_Payment_Page::is_admin_created_order(), which
     * treats only '' and 'admin' as admin-created. Verified so the created_via
     * change cannot silently start showing the HPP box on storefront orders.
     */
    public function testNeitherCreatedViaValueLooksAdminCreated(): void
    {
        $source = $this->methodSource(Hosted_Payment_Page::class, 'is_admin_created_order');

        $this->assertStringContainsString("array('', 'admin')", $source);
        $this->assertStringNotContainsString("'checkout'", $source);
        $this->assertStringNotContainsString("'Briqpay'", $source);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Placement of the two call sites
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The data hooks must fire only after validation passed. Before it, a
     * rejected purchase would run third-party code for an order that is about to
     * be refused - and this flow creates the order before it validates.
     */
    public function testDataHooksFireOnlyWhenValidationPassed(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_make_decision');

        $this->assertStringContainsString(
            "if (\$validation['valid']) {\n                \$this->fire_checkout_data_hooks(\$order);",
            $source,
            'The data hooks must be guarded on validation having passed.'
        );

        $validate_pos = strpos($source, "\$validation = \$this->validate_data_integrity(\$session);");
        $fire_pos = strpos($source, 'fire_checkout_data_hooks');
        $decision_pos = strpos($source, "apply_filters('briqpay_decision_value'");

        $this->assertNotFalse($validate_pos);
        $this->assertNotFalse($fire_pos);
        $this->assertNotFalse($decision_pos);
        $this->assertLessThan($fire_pos, $validate_pos, 'Validation must come first.');
        $this->assertLessThan($decision_pos, $fire_pos, 'The hooks must run before the decision.');
    }

    /**
     * The return handler is the primary commit-hook site, and it has TWO exits
     * that both need it.
     *
     * Found in live testing: a normal purchase creates the order as 'pending' at
     * the decision point, so by the time the customer returns the order already
     * has that status and handle_briqpay_return() takes its
     * already-processed branch - which exits before reaching the
     * briqpay_payment_complete block. With the hooks only on the later path, the
     * webhook fallback became the de-facto primary site and every purchase fired
     * its commit hooks with no posted data.
     */
    public function testCommitHooksFireOnBothReturnHandlerExits(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'handle_briqpay_return');

        $occurrences = substr_count($source, '$this->fire_commit_hooks($order);');
        $this->assertSame(
            2,
            $occurrences,
            'Both return-handler exits must fire the commit hooks; fire_once() keeps it to one run.'
        );

        // The already-processed branch fires before its redirect.
        $already_pos = strpos($source, 'Order already processed');
        $first_commit_pos = strpos($source, '$this->fire_commit_hooks($order);');

        $this->assertNotFalse($already_pos);
        $this->assertLessThan(
            $first_commit_pos,
            $already_pos,
            'The already-processed branch must fire the hooks before redirecting.'
        );

        // On each path, briqpay_payment_complete is fired first.
        $this->assertSame(
            2,
            substr_count($source, '$this->fire_payment_complete($order, $session);'),
            'briqpay_payment_complete must also fire on both exits.'
        );

        $second_commit_pos = strpos($source, '$this->fire_commit_hooks($order);', $first_commit_pos + 1);
        $this->assertNotFalse($second_commit_pos);

        foreach (array($first_commit_pos, $second_commit_pos) as $commit_pos) {
            $verified_pos = strrpos(
                substr($source, 0, $commit_pos),
                '$this->fire_payment_complete($order, $session);'
            );
            $this->assertNotFalse(
                $verified_pos,
                'Each commit-hook call must be preceded by briqpay_payment_complete on the same path.'
            );
        }
    }

    /**
     * briqpay_payment_complete used to sit only inside the status-upgrade branch,
     * which a normal purchase never reaches - so the plugin's own documented action
     * never fired while the WooCommerce actions beside it did.
     */
    public function testPaymentCompleteIsOnceGuardedAndUngated(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'fire_payment_complete');

        $this->assertStringContainsString(
            "fire_once(\$order, 'verified'",
            $source,
            'It must fire once per order across both return paths.'
        );
        $this->assertStringContainsString(
            "apply_filters('briqpay_fire_payment_complete'",
            $source,
            'Suppressible for merchants who built a workaround.'
        );
        $this->assertStringNotContainsString(
            'checkout_hooks_enabled',
            $source,
            'This is the plugin own action, not part of the WooCommerce parity opt-in.'
        );
    }

    /**
     * It stays a return-time signal. The webhook deliberately does not fire it -
     * "the customer came back and we verified the session" is not the same claim as
     * "the money is secured", which woocommerce_payment_complete already carries
     * from the webhook via $order->payment_complete().
     */
    public function testPaymentCompleteIsNotFiredFromTheWebhook(): void
    {
        $webhooks = file_get_contents(
            (new \ReflectionClass(Webhooks::class))->getFileName()
        );

        $this->assertStringNotContainsString('briqpay_payment_complete', $webhooks);
        $this->assertStringNotContainsString('fire_payment_complete', $webhooks);
    }

    /**
     * The webhook is the fallback for a customer who pays and never returns. It
     * hangs off the approved status transition rather than the
     * order_approved_not_captured event name, because that event is skipped when
     * the order is already further along and auto-capturing methods reach a paid
     * state through the capture webhook instead.
     */
    public function testWebhookFallbackIsGatedAndGuarded(): void
    {
        $source = $this->methodSource(Webhooks::class, 'fire_commit_hooks_fallback');

        $this->assertStringContainsString(
            'Checkout_Handler::checkout_hooks_enabled()',
            $source,
            'The fallback must check the gate before touching the order.'
        );
        $this->assertStringContainsString(
            'catch (\\Throwable',
            $source,
            'Third-party code in the Action Scheduler worker must not escape.'
        );

        $gate_pos = strpos($source, 'checkout_hooks_enabled()');
        $call_pos = strpos($source, 'fire_commit_hooks($order)');

        $this->assertLessThan(
            $call_pos,
            $gate_pos,
            'The gate must be checked before the order is used at all.'
        );
    }

    /**
     * The fallback must be attempted BEFORE the rank check that guards the status
     * transition.
     *
     * Found in live testing: when a capture webhook arrived first and took the
     * order to 'processing', the later order_approved_not_captured webhook hit the
     * rank check and returned early - so the fallback never ran and that order
     * never got its commit hooks at all.
     */
    public function testWebhookFallbackRunsBeforeTheRankCheck(): void
    {
        $source = $this->methodSource(Webhooks::class, 'handle_order_status');

        $approved_pos = strpos($source, "case 'order_approved_not_captured':");
        $fallback_pos = strpos($source, 'fire_commit_hooks_fallback($order)', $approved_pos);
        $rank_check_pos = strpos($source, 'if ($current_rank >= 3)', $approved_pos);
        $rejected_pos = strpos($source, "case 'order_rejected':");

        $this->assertNotFalse($approved_pos);
        $this->assertNotFalse($fallback_pos);
        $this->assertNotFalse($rank_check_pos);
        $this->assertNotFalse($rejected_pos);

        $this->assertLessThan($fallback_pos, $approved_pos, 'It belongs in the approved branch.');
        $this->assertLessThan(
            $rank_check_pos,
            $fallback_pos,
            'The fallback must be attempted before the early return that guards the status change.'
        );
        $this->assertLessThan(
            $rejected_pos,
            $fallback_pos,
            'Never in the rejected branch.'
        );
    }

    /**
     * A capture can be the first event that takes an order to paid, arriving
     * before or instead of an order_status webhook.
     */
    public function testWebhookFallbackAlsoRunsOnTheCapturePaymentCompletePath(): void
    {
        $source = $this->methodSource(Webhooks::class, 'handle_capture_status');

        $complete_pos = strpos($source, 'payment_complete($capture_id)');
        $fallback_pos = strpos($source, 'fire_commit_hooks_fallback($order)');

        $this->assertNotFalse($complete_pos, 'Sanity: the capture path records payment.');
        $this->assertNotFalse(
            $fallback_pos,
            'An order that reaches paid purely through capture still needs its commit hooks.'
        );
        $this->assertLessThan($fallback_pos, $complete_pos);
    }

    /**
     * fire_commit_hooks() has to be callable from Webhooks, so it must stay
     * public while the data-hook counterpart stays private.
     */
    public function testCommitHookEntryPointIsPublic(): void
    {
        $commit = new \ReflectionMethod(Checkout_Handler::class, 'fire_commit_hooks');
        $this->assertTrue($commit->isPublic(), 'The webhook fallback calls this.');

        $data = new \ReflectionMethod(Checkout_Handler::class, 'fire_checkout_data_hooks');
        $this->assertTrue($data->isPrivate(), 'Only the decision flow may fire the data hooks.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Stash lifecycle
    // ──────────────────────────────────────────────────────────────────────

    public function testStashIsClearedByBothCleanupPaths(): void
    {
        $purchase = $this->methodSource(Checkout_Handler::class, 'clear_customer_data_after_purchase');
        $this->assertStringContainsString(
            'clear_stashed_posted_data()',
            $purchase,
            'The stash must not survive into the next checkout.'
        );

        $reset = $this->methodSource(
            \Briqpay\WooCommerce\Session_Reset_Handler::class,
            'process_scheduled_session_reset'
        );
        $this->assertStringContainsString(
            'clear_stashed_posted_data()',
            $reset,
            'A pre-login guest form must not be replayed after login.'
        );
    }

    public function testStashIsCapturedOnBothCheckoutPaths(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_get_session');

        $this->assertStringContainsString(
            "stash_posted_data(\$checkout_data, 'classic')",
            $source,
            'Classic checkout form must be captured.'
        );
        $this->assertStringContainsString(
            "stash_posted_data(\$blocks_data, 'blocks')",
            $source,
            'Blocks payload must be captured.'
        );
    }
}
