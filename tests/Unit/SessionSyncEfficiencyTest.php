<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Session_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Redundant session synchronisation.
 *
 * Observed on a real checkout: a session was POSTed at :14 and an identical
 * payload PATCHed at :16, and because each response carries an htmlSnippet the
 * front end rebuilt the iframe it had only just drawn. Two causes, both here:
 *
 *  1. create_session() never recorded the hash the next update would compare
 *     against, so the sync right after a create always had nothing to match.
 *  2. The skip in update_session() also required a non-null $existing_session -
 *     which no caller has ever passed - so it was unreachable dead code and every
 *     sync hit the API even when nothing had changed.
 */
class SessionSyncEfficiencyTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function methodBody($name)
    {
        $method = new \ReflectionMethod(Session_Manager::class, $name);
        $lines = file($method->getFileName());
        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // The skip must be reachable
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The condition must not depend on $existing_session, because neither caller
     * supplies it. That is what made "Skipping PATCH request" impossible to hit -
     * it has never appeared in a production log.
     */
    public function testTheSkipDoesNotDependOnAnArgumentNobodyPasses(): void
    {
        $body = $this->methodBody('update_session');

        $this->assertStringContainsString(
            'if ($stored_hash === $new_hash) {',
            $body,
            'The hash comparison must stand on its own.'
        );
        $this->assertStringNotContainsString(
            '$stored_hash === $new_hash && $existing_session !== null',
            $body,
            'Re-adding the $existing_session requirement makes the skip dead again.'
        );
    }

    /**
     * Both call sites pass only the session id - the fact that made the old
     * condition unsatisfiable. Pinned so a future caller change is noticed.
     */
    public function testNoCallerSuppliesAnExistingSession(): void
    {
        $manager = file_get_contents(
            (new \ReflectionClass(Session_Manager::class))->getFileName()
        );

        $this->assertStringContainsString(
            '$this->update_session($session_id);',
            $manager,
            'get_or_create_session() calls it with one argument.'
        );

        $handler = file_get_contents(
            (new \ReflectionClass(\Briqpay\WooCommerce\Checkout_Handler::class))->getFileName()
        );

        $this->assertStringContainsString(
            '$session_manager->update_session($session_id);',
            $handler,
            'The emergency sync calls it with one argument too.'
        );
    }

    /**
     * Skipping still has to return something the AJAX layer can answer with. The
     * update path only needs the session id - the iframe already shows this
     * session, so no snippet is required.
     */
    public function testSkippingReturnsAUsableResult(): void
    {
        $body = $this->methodBody('update_session');

        $this->assertStringContainsString("'sessionId' => \$session_id", $body);
        $this->assertStringContainsString("'briqpayUnchanged' => true", $body);
        $this->assertStringContainsString(
            'if (null !== $existing_session && !is_wp_error($existing_session)) {',
            $body,
            'A caller that does supply the session should still get it back.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Creating must seed the hash
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateStoresTheHashTheNextUpdateWillCompare(): void
    {
        $body = $this->methodBody('create_session');

        $this->assertStringContainsString(
            '$this->store_update_payload_hash($session_id);',
            $body,
            'Without this the sync right after a create always PATCHes.'
        );

        $success_pos = strpos($body, "self::set_session_id(\$session['sessionId'])");
        $hash_pos = strpos($body, 'store_update_payload_hash');

        $this->assertNotFalse($success_pos);
        $this->assertNotFalse($hash_pos);
        $this->assertLessThan(
            $hash_pos,
            $success_pos,
            'Only seed the hash once the session actually exists.'
        );
    }

    /**
     * The seeded hash has to be computed exactly as update_session() computes it -
     * same builder, same filter, same encoding - or it never matches and the skip
     * is dead for a second reason.
     */
    public function testTheSeededHashMirrorsTheUpdateComputation(): void
    {
        $seed = $this->methodBody('store_update_payload_hash');
        $update = $this->methodBody('update_session');

        foreach (array(
            '$this->get_session_data(true)',
            "apply_filters('briqpay_update_session_data', \$data, \$session_id)",
            'md5(wp_json_encode($data))',
        ) as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $seed,
                'The seed must mirror: ' . $fragment
            );
            $this->assertStringContainsString(
                $fragment,
                $update,
                'Sanity - update_session() really does use: ' . $fragment
            );
        }
    }

    public function testSeedingIsSafeWithoutAWcSession(): void
    {
        $seed = $this->methodBody('store_update_payload_hash');

        $this->assertStringContainsString(
            'if (null === WC() || null === WC()->session) {',
            $seed,
            'Hosted pages and CLI contexts have no WC session.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // The empty-cart short-circuit must stay ahead of the hash work
    // ──────────────────────────────────────────────────────────────────────

    public function testEmptyCartStillBailsBeforeAnyHashWork(): void
    {
        $body = $this->methodBody('create_session');

        $empty_pos = strpos($body, 'WC()->cart->is_empty()');
        $hash_pos = strpos($body, 'store_update_payload_hash');

        $this->assertNotFalse($empty_pos);
        $this->assertLessThan(
            $hash_pos,
            $empty_pos,
            'An empty cart must not reach the extra payload build.'
        );
    }
}
