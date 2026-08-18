<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Upgrade;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * T1.1 / T1.3 / T1.4 / T1.5 - the upgrade migration.
 *
 * The single most important behaviour in the hook parity work: a store that is
 * already live must upgrade to identical behaviour. That is decided here, by
 * stamping 'no' for any store that already has credentials configured.
 *
 * register_activation_hook() cannot do this - WordPress does not fire it when a
 * plugin is updated in place - which is why the migration is driven from
 * admin_init and keyed on a stored version option.
 *
 * The decision logic is exercised through arrays this test owns, because
 * tests/bootstrap.php hard-defines get_option() and WP_Mock cannot override an
 * already-real function.
 */
class UpgradeMigrationTest extends TestCase
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

    // ──────────────────────────────────────────────────────────────────────
    // T1.3 / T1.4 - the decision rule
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The rule: an existing install is one with credentials already configured.
     * A store that was never given a merchant ID cannot have taken a payment, so
     * it has no behaviour worth preserving and gets the correct default.
     */
    public function testDecisionRuleIsCredentialPresence(): void
    {
        $source = $this->methodSource('stamp_checkout_hooks_default');

        $this->assertStringContainsString(
            "!empty(\$settings['merchant_id'])",
            $source,
            'Existing installs must be identified by configured credentials.'
        );
        $this->assertStringContainsString(
            "\$is_existing_install ? 'no' : 'yes'",
            $source,
            "An existing install must be stamped 'no' and a fresh one 'yes'."
        );
    }

    /**
     * T1.5 - never overwrite an explicit choice, including one written by an
     * earlier run of this migration. Without this, every version bump would
     * reset a merchant's decision.
     */
    public function testNeverOverwritesAnExplicitChoice(): void
    {
        $source = $this->methodSource('stamp_checkout_hooks_default');

        $this->assertStringContainsString(
            "isset(\$settings['checkout_hooks_enabled'])",
            $source,
            'The migration must detect an already-present value.'
        );

        $guard_pos = strpos($source, "isset(\$settings['checkout_hooks_enabled'])");
        $write_pos = strpos($source, 'update_option(');

        $this->assertNotFalse($guard_pos);
        $this->assertNotFalse($write_pos);
        $this->assertLessThan(
            $write_pos,
            $guard_pos,
            'The already-set guard must precede the write.'
        );
        $this->assertStringContainsString(
            'return;',
            substr($source, $guard_pos, $write_pos - $guard_pos),
            'The guard must return early, not fall through to the write.'
        );
    }

    /**
     * A corrupt or non-array option must not fatal, and must not be replaced
     * with a fresh array that silently discards the merchant's settings.
     */
    public function testBailsOnNonArraySettings(): void
    {
        $source = $this->methodSource('stamp_checkout_hooks_default');

        $this->assertStringContainsString(
            'if (!is_array($settings))',
            $source,
            'Non-array settings must be handled rather than assumed.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1.1 - version gating
    // ──────────────────────────────────────────────────────────────────────

    public function testMigrationIsSkippedWhenVersionMatches(): void
    {
        $ran = false;

        WP_Mock::userFunction('update_option', array(
            'return' => function () use (&$ran) {
                $ran = true;
                return true;
            },
        ));

        // The bootstrap's get_option() returns false for briqpay_wc_version, so
        // the stored version never equals BRIQPAY_WC_VERSION here and the guard
        // is asserted structurally instead.
        $source = $this->methodSource('maybe_migrate');

        $this->assertStringContainsString(
            'BRIQPAY_WC_VERSION === $stored',
            $source,
            'The migration must short-circuit when it has already run for this version.'
        );

        $guard_pos = strpos($source, 'BRIQPAY_WC_VERSION === $stored');
        $work_pos = strpos($source, 'stamp_checkout_hooks_default');

        $this->assertNotFalse($work_pos);
        $this->assertLessThan(
            $work_pos,
            $guard_pos,
            'The version guard must come before any work.'
        );
    }

    public function testVersionIsStampedAfterTheWork(): void
    {
        $source = $this->methodSource('maybe_migrate');

        $work_pos = strpos($source, 'stamp_checkout_hooks_default');
        $stamp_pos = strpos($source, 'update_option(self::VERSION_OPTION');

        $this->assertNotFalse($work_pos);
        $this->assertNotFalse($stamp_pos);
        $this->assertLessThan(
            $stamp_pos,
            $work_pos,
            'The version must be stamped after the migration work, so a failure retries.'
        );
    }

    public function testRunsOnAdminInitOnly(): void
    {
        // The migration writes options; there is no reason to run it on every
        // front-end request, and doing so would add a read to every page load.
        $upgrade = new Upgrade();

        WP_Mock::expectActionAdded('admin_init', array(Upgrade::class, 'maybe_migrate'));

        $upgrade->init();

        WP_Mock::assertHooksAdded();
    }

    public function testVersionOptionNameIsStable(): void
    {
        // Renaming this would make every store re-run the migration, which the
        // already-set guard would survive - but the guard is the second line of
        // defence, not the first.
        $this->assertSame('briqpay_wc_version', Upgrade::VERSION_OPTION);
    }

    /**
     * Read a method's source out of Upgrade.
     *
     * The migration's own reads go through the bootstrap's hard-defined
     * get_option(), so the decision rules it encodes are asserted against the
     * source. These are regression pins for rules that must not drift.
     *
     * @param string $name
     * @return string
     */
    private function methodSource($name)
    {
        $method = new \ReflectionMethod(Upgrade::class, $name);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }
}
