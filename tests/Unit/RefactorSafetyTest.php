<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Order_Management;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Undefined-variable guard for extracted method bodies.
 *
 * Two real defects shipped in this work, both the same shape: wrapping an
 * existing method in a lock meant moving its body into a new private method, and
 * the moved body kept referring to variables that were parameters of the OLD
 * signature.
 *
 *   capture_order_locked()  referenced $order_id, which only capture_order() had.
 *   Every failed capture then scheduled its Action Scheduler retry with
 *   array(null), so wc_get_order(0) returned false and no retry ever ran.
 *
 *   execute_single_refund() referenced $reason, which only refund_order() had.
 *   Every amount-only refund silently lost the merchant's reason text.
 *
 * Neither was caught, because the existing tests only exercised happy-path item
 * derivation. PHP does not fail on an undefined variable - it warns and evaluates
 * to null - so nothing crashed. This test reads each method's own tokens and
 * asserts every variable it uses is either a parameter, something it assigns, or
 * an accepted global.
 */
class RefactorSafetyTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Methods whose bodies were extracted or reshaped in this work, and so are
     * the ones at risk of referencing a caller's variable.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public function extractedMethodProvider()
    {
        return array(
            'capture_order' => array(Order_Management::class, 'capture_order'),
            'capture_order_locked' => array(Order_Management::class, 'capture_order_locked'),
            'manual_capture' => array(Order_Management::class, 'manual_capture'),
            'manual_capture_locked' => array(Order_Management::class, 'manual_capture_locked'),
            'refund_order' => array(Order_Management::class, 'refund_order'),
            'refund_order_locked' => array(Order_Management::class, 'refund_order_locked'),
            'execute_single_refund' => array(Order_Management::class, 'execute_single_refund'),
            'derive_order_tax_rate_bps' => array(Order_Management::class, 'derive_order_tax_rate_bps'),
            'transaction_approval_state' => array(Order_Management::class, 'transaction_approval_state'),
            'fire_checkout_data_hooks' => array(Checkout_Handler::class, 'fire_checkout_data_hooks'),
            'fire_commit_hooks' => array(Checkout_Handler::class, 'fire_commit_hooks'),
            'fire_once' => array(Checkout_Handler::class, 'fire_once'),
            'with_posted_data' => array(Checkout_Handler::class, 'with_posted_data'),
            'apply_native_order_properties' => array(Checkout_Handler::class, 'apply_native_order_properties'),
            'stash_posted_data' => array(Checkout_Handler::class, 'stash_posted_data'),
            'warn_on_total_drift' => array(Checkout_Handler::class, 'warn_on_total_drift'),
            'reserve_stock' => array(Checkout_Handler::class, 'reserve_stock'),
            'order_has_active_stock_reservation' => array(Checkout_Handler::class, 'order_has_active_stock_reservation'),
            'release_stock' => array(Checkout_Handler::class, 'release_stock'),
            'recalculate_cogs' => array(Checkout_Handler::class, 'recalculate_cogs'),
            'fire_tax_item_hooks' => array(Checkout_Handler::class, 'fire_tax_item_hooks'),
            'handle_checkout_order_processed' => array(Checkout_Handler::class, 'handle_checkout_order_processed'),
        );
    }

    /**
     * @dataProvider extractedMethodProvider
     */
    public function testMethodUsesNoUndefinedVariables($class, $method): void
    {
        $undefined = $this->findUndefinedVariables($class, $method);

        $this->assertSame(
            array(),
            $undefined,
            sprintf(
                '%s::%s() reads %s, which is neither a parameter nor assigned in the method. '
                . 'This is the defect shape that broke capture retries and dropped refund reasons.',
                $class,
                $method,
                implode(', ', $undefined)
            )
        );
    }

    /**
     * The scanner must actually detect the two shipped defects, or it proves
     * nothing. Re-create each on a throwaway function and confirm a catch.
     */
    public function testTheScannerCatchesTheDefectItGuardsAgainst(): void
    {
        $source = <<<'PHP'
        function briqpay_scanner_probe($order, $session_id)
        {
            $result = do_something($order, $session_id);
            if (is_wp_error($result)) {
                schedule_retry(array($order_id));
                log_it($reason);
            }
        }
PHP;

        $found = $this->scanSource($source, array('order', 'session_id'));

        $this->assertContains('$order_id', $found, 'Must catch the capture-retry defect.');
        $this->assertContains('$reason', $found, 'Must catch the refund-reason defect.');
        $this->assertNotContains('$result', $found, 'Assigned variables are fine.');
        $this->assertNotContains('$order', $found, 'Parameters are fine.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scanner
    // ──────────────────────────────────────────────────────────────────────

    private function findUndefinedVariables($class, $method)
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        $source = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));

        $params = array();
        foreach ($ref->getParameters() as $param) {
            $params[] = $param->getName();
        }

        return $this->scanSource($source, $params);
    }

    /**
     * Report variables read before being defined.
     *
     * Intentionally simple: it treats any variable that appears as an assignment
     * target, a foreach target, a catch target, a closure parameter, or a closure
     * `use` binding as defined, and everything else as a read. That is enough to
     * catch a moved body referencing a caller's parameter.
     *
     * @param string   $source PHP source of one function.
     * @param string[] $params Declared parameter names, without the sigil.
     * @return string[] Variable names that are read but never defined.
     */
    private function scanSource($source, array $params)
    {
        $tokens = token_get_all('<?php ' . $source);

        $defined = array();
        foreach ($params as $param) {
            $defined['$' . $param] = true;
        }
        $defined['$this'] = true;

        foreach (array('$_POST', '$_GET', '$_REQUEST', '$_SERVER', '$_COOKIE', '$GLOBALS', '$_FILES', '$_SESSION') as $global) {
            $defined[$global] = true;
        }

        // First pass: everything that binds a name.
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || T_VARIABLE !== $tokens[$i][0]) {
                continue;
            }

            $name = $tokens[$i][1];

            // Look ahead past whitespace for an assignment operator.
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                break;
            }

            $next = $tokens[$j] ?? null;
            $is_assignment = ('=' === $next)
                || (is_array($next) && in_array($next[0], array(
                    T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
                    T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_DOUBLE_ARROW,
                ), true));

            // Look behind for binding keywords: foreach ... as, catch (..., use (,
            // function (, and by-reference forms.
            for ($k = $i - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && T_WHITESPACE === $tokens[$k][0]) {
                    continue;
                }
                break;
            }

            // A binding may be preceded by a type declaration - `catch (\Throwable
            // $e)`, `function (int $x)`, `?Foo $y` - so step back over any type
            // tokens before judging what precedes the variable.
            $k = $this->skipTypeTokensBackwards($tokens, $k);

            $prev = $tokens[$k] ?? null;
            // `global $wpdb;` binds $wpdb in local scope same as an assignment would.
            $is_binding = is_array($prev) && in_array($prev[0], array(T_AS, T_USE, T_FUNCTION, T_FN, T_CATCH, T_GLOBAL), true);

            // '&' or '(' or ',' immediately before, inside a binding construct.
            // Note PHP 8.1+ tokenizes the '&' of `as &$ref` as
            // T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG rather than the plain string,
            // so both forms have to be recognised.
            $is_ampersand = ('&' === $prev) || $this->isAmpersandToken($prev);

            if (!$is_binding && ($is_ampersand || '(' === $prev || ',' === $prev || '|' === $prev)) {
                $is_binding = $this->isInsideBindingConstruct($tokens, $k);
            }

            // The value half of `foreach ($x as $k => $v)` binds too. Routed
            // through the same context check so an array literal's `'k' => $v`,
            // where $v really is a read, is not mistaken for a binding.
            if (!$is_binding && is_array($prev) && T_DOUBLE_ARROW === $prev[0]) {
                $is_binding = $this->isInsideBindingConstruct($tokens, $k);
            }

            if ($is_assignment || $is_binding) {
                $defined[$name] = true;
            }
        }

        // Second pass: report reads of anything still undefined.
        $undefined = array();
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || T_VARIABLE !== $tokens[$i][0]) {
                continue;
            }

            $name = $tokens[$i][1];

            if (isset($defined[$name]) || in_array($name, $undefined, true)) {
                continue;
            }

            // `self::$prop` and `$obj->$prop` name a property, not a local, so the
            // sigil here is not a variable read at all.
            for ($k = $i - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && T_WHITESPACE === $tokens[$k][0]) {
                    continue;
                }
                break;
            }

            $prev = $tokens[$k] ?? null;
            if (is_array($prev) && in_array($prev[0], array(T_DOUBLE_COLON, T_OBJECT_OPERATOR), true)) {
                continue;
            }

            $undefined[] = $name;
        }

        return $undefined;
    }

    /**
     * Is this token an ampersand, in either the pre-8.1 or 8.1+ tokenization?
     *
     * @param mixed $token
     * @return bool
     */
    private function isAmpersandToken($token)
    {
        if (!is_array($token)) {
            return false;
        }

        foreach (array('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG', 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') as $name) {
            if (defined($name) && constant($name) === $token[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Step an index back past type-declaration tokens.
     *
     * @param array $tokens
     * @param int   $index Index of the first significant token before a variable.
     * @return int Index of the first token that is not part of a type.
     */
    private function skipTypeTokensBackwards(array $tokens, $index)
    {
        $type_tokens = array(T_STRING, T_NS_SEPARATOR, T_ARRAY, T_CALLABLE, T_STATIC);

        foreach (array('T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE') as $name) {
            if (defined($name)) {
                $type_tokens[] = constant($name);
            }
        }

        while ($index >= 0) {
            $token = $tokens[$index];

            if (is_array($token) && T_WHITESPACE === $token[0]) {
                $index--;
                continue;
            }

            if ('?' === $token) {
                $index--;
                continue;
            }

            if (is_array($token) && in_array($token[0], $type_tokens, true)) {
                $index--;
                continue;
            }

            break;
        }

        return $index;
    }

    /**
     * Walk back from a '(' / ',' / '&' to see whether we are in a parameter list,
     * a closure `use`, a foreach target, or a catch clause.
     *
     * @param array $tokens
     * @param int   $from Index to walk back from.
     * @return bool
     */
    private function isInsideBindingConstruct(array $tokens, $from)
    {
        $depth = 0;

        for ($i = $from; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (')' === $token) {
                $depth++;
                continue;
            }
            if ('(' === $token) {
                if (0 === $depth) {
                    // Keyword immediately before this opening paren decides it.
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                            continue;
                        }
                        break;
                    }
                    $keyword = $tokens[$j] ?? null;
                    return is_array($keyword) && in_array($keyword[0], array(
                        T_FUNCTION, T_FN, T_USE, T_CATCH, T_FOREACH, T_LIST,
                    ), true);
                }
                $depth--;
                continue;
            }
            if (is_array($token) && in_array($token[0], array(T_AS, T_FOREACH), true)) {
                return true;
            }
            // A statement boundary means we were not in a binding construct.
            if (';' === $token || '{' === $token || '}' === $token) {
                return false;
            }
        }

        return false;
    }
}
