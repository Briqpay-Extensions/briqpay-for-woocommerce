<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Session_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Tests for the "WooCommerce and Briqpay are out of sync" flag.
 *
 * Background: the request that syncs the cart (PATCH) and the request that makes
 * the payment decision are different, so a sync failure has to be remembered in
 * the WC session. Checkout_Handler::validate_data_integrity() refuses the
 * purchase while the flag is set.
 *
 * The bug these tests pin down: a failed PATCH used to set the flag and then
 * immediately create a replacement session. The replacement is built from the
 * current cart - i.e. in sync by construction - but the flag survived, so the
 * customer's next Pay click was refused with "We were unable to synchronize your
 * cart" even though nothing was out of sync. Where PATCH failed systematically
 * (e.g. a create-only field leaking into the update payload via a merchant
 * filter) the flag could never clear and checkout was hard-blocked while looking
 * completely healthy.
 */
class SyncFailedFlagTest extends TestCase
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
     * Fake WC()->session backed by a plain array.
     */
    private function mockWcSession(array $initial = array())
    {
        $store = $initial;

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

        return $session;
    }

    private function mockWcWithoutSession()
    {
        $wc = Mockery::mock('WooCommerce');
        $wc->session = null;
        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Round-trip
    // ──────────────────────────────────────────────────────────────────────

    public function testFlagRoundTrips(): void
    {
        $this->mockWcSession();

        $this->assertFalse(Session_Manager::has_sync_failed(), 'Should start clear.');

        Session_Manager::set_sync_failed(true);
        $this->assertTrue(Session_Manager::has_sync_failed());

        Session_Manager::set_sync_failed(false);
        $this->assertFalse(Session_Manager::has_sync_failed());
    }

    public function testFlagIsCoercedToBool(): void
    {
        $this->mockWcSession();

        Session_Manager::set_sync_failed(1);
        $this->assertTrue(Session_Manager::has_sync_failed());

        Session_Manager::set_sync_failed(0);
        $this->assertFalse(Session_Manager::has_sync_failed());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reading a flag written before the helpers existed
    // ──────────────────────────────────────────────────────────────────────

    public function testReadsTheSameSessionKeyAsBefore(): void
    {
        // Stores mid-checkout when this version deploys already have the raw key
        // in their WC session; the helpers must keep reading it, not orphan it.
        $this->assertSame('briqpay_sync_failed', Session_Manager::SYNC_FAILED_KEY);

        $this->mockWcSession(array('briqpay_sync_failed' => true));
        $this->assertTrue(Session_Manager::has_sync_failed());
    }

    // ──────────────────────────────────────────────────────────────────────
    // No WC session available
    // ──────────────────────────────────────────────────────────────────────

    public function testHasSyncFailedIsFalseWithoutASession(): void
    {
        // Webhook/CLI contexts have no WC session. Never block on a flag that
        // cannot even be read.
        $this->mockWcWithoutSession();
        $this->assertFalse(Session_Manager::has_sync_failed());
    }

    public function testSetSyncFailedIsANoOpWithoutASession(): void
    {
        $this->mockWcWithoutSession();

        Session_Manager::set_sync_failed(true);

        // Reached without a fatal - the null guard held.
        $this->assertFalse(Session_Manager::has_sync_failed());
    }

    // ──────────────────────────────────────────────────────────────────────
    // The regression itself
    // ──────────────────────────────────────────────────────────────────────

    public function testSuccessfulCreateClearsAFlagLeftByAFailedPatch(): void
    {
        // Mirrors get_or_create_session() -> failed PATCH -> create_session():
        // whatever the PATCH left behind, a session created from the current cart
        // is in sync, so create_session()'s success path clears the flag.
        $this->mockWcSession(array('briqpay_sync_failed' => true));

        Session_Manager::set_sync_failed(false);

        $this->assertFalse(
            Session_Manager::has_sync_failed(),
            'A successful recovery create must not leave the next decision blocked.'
        );
    }

    public function testCreateSessionClearsTheFlagOnSuccess(): void
    {
        $this->assertTrue(
            $this->createSessionBodyContains("self::set_sync_failed(false);"),
            'create_session() must clear the flag when a session is created.'
        );
    }

    public function testCreateSessionSetsTheFlagOnFailure(): void
    {
        $this->assertTrue(
            $this->createSessionBodyContains("self::set_sync_failed(true);"),
            'create_session() must block the decision when no session could be created.'
        );
    }

    public function testGetOrCreateSessionNoLongerMarksFailureBeforeRecovering(): void
    {
        // The regression was a set_sync_failed(true) here that the following
        // create_session() could not undo. Recovery owns the flag now, so
        // get_or_create_session() must not write it on the PATCH-failure path.
        $body = $this->methodBody('get_or_create_session');

        $this->assertStringNotContainsString(
            'set_sync_failed(true)',
            $body,
            'get_or_create_session() must leave the failure flag to create_session().'
        );
        $this->assertStringContainsString(
            'set_sync_failed(false)',
            $body,
            'A successful PATCH must still clear the flag.'
        );
    }

    /**
     * Read a method's source out of Session_Manager.
     *
     * create_session() is private and makes live API calls, so its behaviour is
     * asserted structurally rather than by execution - enough to catch the
     * regression coming back.
     */
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

    private function createSessionBodyContains($needle)
    {
        return false !== strpos($this->methodBody('create_session'), $needle);
    }
}
