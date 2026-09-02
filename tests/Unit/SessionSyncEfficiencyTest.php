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

    /**
     * The regression this closes, found in production: a customer arrived with a
     * session already in their WC session and an unchanged cart, so the skip fired
     * and returned a response with no htmlSnippet. The front end had no session of
     * its own (JS state does not survive navigation), so it had nothing to render -
     * the checkout stayed blank, with no error anywhere.
     *
     * Skipping is therefore only safe when the BROWSER already holds this session.
     */
    public function testSkippingRequiresTheBrowserToAlreadyHoldTheSession(): void
    {
        $body = $this->methodBody('update_session');

        $this->assertStringContainsString(
            '$this->client_session_id === $session_id',
            $body,
            'The skip must confirm the browser has this session rendered.'
        );

        $guard_pos = strpos($body, '$this->client_session_id === $session_id');
        $return_pos = strpos($body, "'briqpayUnchanged' => true");

        $this->assertNotFalse($guard_pos);
        $this->assertNotFalse($return_pos);
        $this->assertLessThan(
            $return_pos,
            $guard_pos,
            'The snippet-less response must sit behind that guard.'
        );

        $this->assertStringContainsString(
            'patching anyway to obtain a snippet',
            $body,
            'A fresh page load must fall through to the PATCH so it gets a snippet.'
        );
    }

    public function testTheClientSessionIdIsAcceptedAndNormalised(): void
    {
        $manager = new Session_Manager();

        $property = new \ReflectionProperty(Session_Manager::class, 'client_session_id');
        $property->setAccessible(true);

        $this->assertNull($property->getValue($manager), 'Absent by default.');

        $manager->set_client_session_id('sess_abc');
        $this->assertSame('sess_abc', $property->getValue($manager));

        // An empty string is what the front end sends on a fresh load, and must not
        // be mistaken for "the browser holds session ''".
        $manager->set_client_session_id('');
        $this->assertNull($property->getValue($manager));
    }

    /**
     * The AJAX handler has to actually pass it through, and must complain when a
     * response cannot be rendered - the silence is what made this bug expensive.
     */
    public function testTheAjaxHandlerForwardsItAndFlagsUnrenderableResponses(): void
    {
        $method = new \ReflectionMethod(\Briqpay\WooCommerce\Checkout_Handler::class, 'ajax_get_session');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("\$_POST['client_session_id']", $body);
        $this->assertStringContainsString('set_client_session_id($client_session_id)', $body);

        $set_pos = strpos($body, 'set_client_session_id');
        // Anchor on the call, not the earlier comment that mentions it.
        $call_pos = strpos($body, '$session_manager->get_or_create_session()');
        $this->assertLessThan($call_pos, $set_pos, 'It must be set before the session work runs.');

        $this->assertStringContainsString(
            'carries no htmlSnippet',
            $body,
            'An unrenderable response must be logged as an error.'
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
    // Gateway availability is resolved once per request
    // ──────────────────────────────────────────────────────────────────────

    /**
     * get_available_payment_gateways() runs is_available() on every registered
     * gateway, and third-party gateways do real work there. WooCommerce does not
     * memoize it and themes often evaluate body_class() more than once, so this was
     * repeating the whole pass each time.
     */
    public function testGatewayAvailabilityIsMemoisedForTheRequest(): void
    {
        $method = new \ReflectionMethod(
            \Briqpay\WooCommerce\Checkout_Handler::class,
            'get_available_gateways_once'
        );
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('static $gateways = null;', $body);
        $this->assertStringContainsString(
            'if (null !== $gateways) {',
            $body,
            'A second call must return the cached list.'
        );
        $this->assertTrue($method->isStatic());
    }

    public function testBodyClassUsesTheMemoisedAccessor(): void
    {
        $method = new \ReflectionMethod(
            \Briqpay\WooCommerce\Checkout_Handler::class,
            'add_body_class'
        );
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('self::get_available_gateways_once()', $body);
        $this->assertStringNotContainsString(
            '->get_available_payment_gateways()',
            $body,
            'body_class must not run the availability pass directly.'
        );
    }

    /**
     * Memoisation must not spread beyond body_class output. Anywhere that needs a
     * live list - the gateway itself, order handling - has to keep asking
     * WooCommerce directly.
     */
    public function testOnlyBodyClassUsesTheMemoisedList(): void
    {
        $handler = file_get_contents(
            (new \ReflectionClass(\Briqpay\WooCommerce\Checkout_Handler::class))->getFileName()
        );

        $this->assertSame(
            1,
            substr_count($handler, 'self::get_available_gateways_once()'),
            'Exactly one consumer: add_body_class().'
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
