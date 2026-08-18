<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Lock;
use Briqpay\WooCommerce\Money;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Atomic locks and minor-unit money conversion.
 *
 * Lock replaces the check-then-set transient pattern that allowed duplicate
 * orders, captures, refunds and webhook processing. The bootstrap's in-memory
 * add_option() mirrors WordPress's refuse-if-exists semantics, which is the
 * property the whole design rests on.
 */
class LockAndMoneyTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Options::reset();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lock - mutual exclusion
    // ──────────────────────────────────────────────────────────────────────

    public function testFirstCallerAcquiresAndSecondIsRefused(): void
    {
        $this->assertTrue(Lock::acquire('decision_sess_1', 30));
        $this->assertFalse(
            Lock::acquire('decision_sess_1', 30),
            'A second concurrent caller must be refused - this is the race the old code lost.'
        );
    }

    public function testReleaseAllowsReacquisition(): void
    {
        $this->assertTrue(Lock::acquire('decision_sess_1', 30));
        Lock::release('decision_sess_1');
        $this->assertTrue(Lock::acquire('decision_sess_1', 30));
    }

    public function testDifferentKeysDoNotContend(): void
    {
        $this->assertTrue(Lock::acquire('capture_1', 30));
        $this->assertTrue(Lock::acquire('capture_2', 30));
        $this->assertTrue(Lock::acquire('refund_1', 30));
    }

    /**
     * A request that dies mid-flight must not wedge a session forever.
     */
    public function testAnExpiredLockIsReclaimed(): void
    {
        // Write a lock that expired an hour ago, as an abandoned request would leave.
        \Briqpay_Test_Options::$store[Lock::PREFIX . md5('decision_sess_1')] = time() - 3600;

        $this->assertTrue(
            Lock::acquire('decision_sess_1', 30),
            'An abandoned lock must be reclaimable.'
        );
        $this->assertFalse(
            Lock::acquire('decision_sess_1', 30),
            'Once reclaimed it is held again.'
        );
    }

    public function testAnUnexpiredLockIsNotReclaimed(): void
    {
        \Briqpay_Test_Options::$store[Lock::PREFIX . md5('decision_sess_1')] = time() + 600;

        $this->assertFalse(Lock::acquire('decision_sess_1', 30));
    }

    public function testIsHeldReflectsStateWithoutClaiming(): void
    {
        $this->assertFalse(Lock::is_held('capture_9'));

        Lock::acquire('capture_9', 30);
        $this->assertTrue(Lock::is_held('capture_9'));

        Lock::release('capture_9');
        $this->assertFalse(Lock::is_held('capture_9'));
    }

    public function testIsHeldIsFalseForAnExpiredLock(): void
    {
        \Briqpay_Test_Options::$store[Lock::PREFIX . md5('capture_9')] = time() - 1;

        $this->assertFalse(Lock::is_held('capture_9'));
    }

    /**
     * Locks must not be loaded into alloptions on every request.
     */
    public function testLocksAreStoredWithAutoloadOff(): void
    {
        $method = new \ReflectionMethod(Lock::class, 'add');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("'no'", $body, 'add_option() must pass autoload no.');
    }

    /**
     * Arbitrary session IDs must not be able to exceed the option_name column.
     */
    public function testKeysAreHashed(): void
    {
        $long = str_repeat('x', 500);

        $this->assertTrue(Lock::acquire($long, 30));

        $keys = array_keys(\Briqpay_Test_Options::$store);
        $this->assertCount(1, $keys);
        $this->assertSame(Lock::PREFIX . md5($long), $keys[0]);
        $this->assertLessThan(64, strlen($keys[0]));
    }

    /**
     * The atomicity claim: it must be add_option(), not update_option() or
     * wp_cache_add(). update_option() always succeeds and so cannot arbitrate;
     * wp_cache_add() is only atomic with a persistent object cache installed and
     * degrades silently to per-request memory without one.
     */
    public function testClaimUsesAddOptionNotUpdateOptionOrCache(): void
    {
        // Comments discuss the alternatives by name, so strip them and assert on
        // the code itself.
        $code = php_strip_whitespace((new \ReflectionClass(Lock::class))->getFileName());

        $this->assertStringContainsString('add_option(', $code);
        $this->assertStringNotContainsString('update_option(', $code);
        $this->assertStringNotContainsString('wp_cache_add(', $code);
        $this->assertStringNotContainsString('set_transient(', $code);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lock - idempotency markers
    // ──────────────────────────────────────────────────────────────────────

    public function testClaimOnceIsWonByTheFirstCallerOnly(): void
    {
        $this->assertTrue(Lock::claim_once('wh_event_abc', 300));
        $this->assertFalse(
            Lock::claim_once('wh_event_abc', 300),
            'A duplicate webhook delivery must be recognised as a duplicate.'
        );
        $this->assertFalse(Lock::claim_once('wh_event_abc', 300));
    }

    public function testDistinctEventsEachClaimOnce(): void
    {
        $this->assertTrue(Lock::claim_once('wh_capture_1', 300));
        $this->assertTrue(Lock::claim_once('wh_capture_2', 300));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Money
    // ──────────────────────────────────────────────────────────────────────

    public function testDefaultsToTwoDecimalsWhenWooCommerceIsAbsent(): void
    {
        $this->assertSame(2, Money::decimals());
        $this->assertSame(100, Money::multiplier());
    }

    /**
     * At two decimals the helper must be byte-identical to the x100 the rest of
     * the plugin still does, or centralising it would change live amounts.
     */
    public function testMatchesTheHistoricalHundredMultiplierExactly(): void
    {
        $cases = array(
            array(26.25, 2625),
            array(0.01, 1),
            array(0.0, 0),
            array(1999.99, 199999),
            array(-12.5, -1250),
            array(0.005, 1),
        );

        foreach ($cases as list($major, $expected)) {
            $this->assertSame(
                (int) round($major * 100),
                Money::to_minor($major),
                'to_minor() must equal the historical x100 for ' . $major
            );
            $this->assertSame($expected, Money::to_minor($major));
        }
    }

    public function testFromMinorRoundTrips(): void
    {
        $this->assertSame(26.25, Money::from_minor(2625));
        $this->assertSame(0.01, Money::from_minor(1));
        $this->assertSame(0.0, Money::from_minor(0));
    }

    public function testStringAmountsAreAccepted(): void
    {
        $this->assertSame(2625, Money::to_minor('26.25'));
    }

    public function testTwoDecimalsIsTheSupportedPrecision(): void
    {
        $this->assertTrue(Money::is_supported_precision());
    }

    public function testDecimalsFilterDrivesTheMultiplier(): void
    {
        WP_Mock::onFilter('briqpay_money_decimals')->with(2)->reply(0);

        $this->assertSame(0, Money::decimals());
        $this->assertSame(1, Money::multiplier());
        $this->assertSame(1000, Money::to_minor(1000), 'A zero-decimal currency sends whole units.');
        $this->assertFalse(
            Money::is_supported_precision(),
            'Zero decimals is exactly the case the gateway must refuse.'
        );
    }

    public function testThreeDecimalsIsAlsoUnsupported(): void
    {
        WP_Mock::onFilter('briqpay_money_decimals')->with(2)->reply(3);

        $this->assertSame(1000, Money::multiplier());
        $this->assertSame(1234, Money::to_minor(1.234));
        $this->assertFalse(Money::is_supported_precision());
    }

    public function testNegativeDecimalsCannotProduceAZeroMultiplier(): void
    {
        WP_Mock::onFilter('briqpay_money_decimals')->with(2)->reply(-1);

        $this->assertSame(1, Money::multiplier());
        $this->assertSame(5.0, Money::from_minor(5), 'from_minor() must never divide by zero.');
    }
}
