<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Gateway;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use Briqpay\WooCommerce\Legacy_B2b_Meta;
use Briqpay\WooCommerce\Order_Management;
use Briqpay\WooCommerce\Order_Status_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * The session-id order meta key is shared with the previous plugin.
 *
 * krokedil/briqpay-for-woocommerce stores it under exactly the same key:
 *
 *   class-briqpay-gateway.php, process_payment():
 *       $order->update_meta_data( '_briqpay_session_id', $response['sessionid'] );
 *   includes/briqpay-functions.php:
 *       order lookup by meta_key '_briqpay_session_id'
 *   class-briqpay-confirmation.php:
 *       $order->get_meta( '_briqpay_session_id' )
 *
 * That is why it is deliberately absent from Legacy_B2b_Meta's mapping - mirroring
 * it would write the same value to the same key.
 *
 * It also means orders created by the OLD plugin are already fully operable here,
 * because this key is not decorative metadata: it is what capture, refund, the
 * janitor, the webhook order lookup, the admin meta box, the pay button and
 * hosted-page regeneration all key on.
 *
 * Renaming it would silently strand every migrated order - capture_order() and
 * refund_order() both bail on a missing session id with no error, no order note and
 * no log line. These tests exist to make that rename fail loudly instead.
 */
class SessionIdCompatibilityTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /**
     * The one string the previous plugin also uses.
     */
    const SHARED_KEY = '_briqpay_session_id';

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Locks live in the bootstrap's in-memory option store, which persists
        // across tests in a process. PaymentSafetyTest deliberately leaves a capture
        // lock held, and without this reset that lock makes capture_order() below
        // bail before reaching anything worth asserting on.
        \Briqpay_Test_Options::reset();

        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function classSource($class)
    {
        return file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    /**
     * Every session-id-shaped meta key in the class must BE the shared key.
     *
     * Deliberately not "the file mentions the key somewhere": a partial rename
     * would leave the other call sites intact and sail past that. Scanning every
     * `_briqpay_session*` occurrence catches a rename of one call site, and does not
     * get in the way of legitimately adding new ones.
     *
     * @dataProvider sessionIdConsumerProvider
     */
    public function testEverySessionIdKeyInTheClassIsTheSharedKey($class): void
    {
        $source = $this->classSource($class);

        // Quoted strings only. An unquoted match is a method name such as
        // sync_with_briqpay_session(), not a meta key.
        preg_match_all("/'(_briqpay_session[A-Za-z_]*)'/", $source, $matches);

        $this->assertNotEmpty(
            $matches[1],
            $class . ' should reference the session id meta key at all.'
        );

        $unexpected = array_values(array_unique(array_filter(
            $matches[1],
            function ($key) {
                return self::SHARED_KEY !== $key;
            }
        )));

        $this->assertSame(
            array(),
            $unexpected,
            $class . ' uses ' . implode(', ', $unexpected) . ' - migrated orders carry '
                . self::SHARED_KEY . ', so those sites would find nothing.'
        );
    }

    public function sessionIdConsumerProvider()
    {
        return array(
            'checkout handler' => array(Checkout_Handler::class),
            'order management' => array(Order_Management::class),
            'order status manager' => array(Order_Status_Manager::class),
            'hosted payment page' => array(Hosted_Payment_Page::class),
            'gateway' => array(Gateway::class),
        );
    }

    /**
     * No variant spellings. A near-miss key is worse than an obvious rename,
     * because half the plugin would keep working.
     *
     * @dataProvider consumerAndVariantProvider
     */
    public function testNoVariantSpellingsAreUsed($class, $variant): void
    {
        $source = $this->classSource($class);

        $this->assertStringNotContainsString(
            "'" . $variant . "'",
            $source,
            $class . ' uses ' . $variant . ' - migrated orders store ' . self::SHARED_KEY . '.'
        );
    }

    public function consumerAndVariantProvider()
    {
        $variants = array(
            '_briqpay_session',
            '_briqpay_sessionid',
            '_briqpay_purchase_id',
            '_wc_briqpay_session_id',
            'briqpay_session_id_meta',
        );

        $cases = array();

        foreach ($this->sessionIdConsumerProvider() as $label => $args) {
            foreach ($variants as $variant) {
                $cases[$label . ' / ' . $variant] = array($args[0], $variant);
            }
        }

        return $cases;
    }

    /**
     * The legacy mapping must NOT mirror the session id. Adding it would be
     * redundant, and a future maintainer answering "should we map it?" should find
     * this test rather than guess.
     */
    public function testTheLegacyMappingDoesNotMirrorTheSessionId(): void
    {
        $method = new \ReflectionMethod(Legacy_B2b_Meta::class, 'apply');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString(
            self::SHARED_KEY,
            $body,
            'The previous plugin uses the same key, so mirroring it writes the same value twice.'
        );
    }

    /**
     * The keys the legacy mapping DOES write, pinned. If one is dropped, a
     * migrated store's ERP export loses a field silently.
     */
    public function testTheLegacyMappingStillWritesItsOwnKeys(): void
    {
        $method = new \ReflectionMethod(Legacy_B2b_Meta::class, 'apply');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        foreach (array(
            '_billing_org_nr',
            '_shipping_email',
            '_briqpay_payment_method',
            '_briqpay_autocapture',
            '_briqpay_rules_result',
            '_briqpay_psp_update_order_supported',
        ) as $key) {
            $this->assertStringContainsString(
                "'" . $key . "'",
                $body,
                $key . ' is part of the previous plugin\'s contract and must keep being written.'
            );
        }
    }

    /**
     * Not covered behaviourally, deliberately.
     *
     * Driving capture_order() would prove a migrated order's session id flows into
     * the capture path, but tests/bootstrap.php hard-defines wc_get_order() (it
     * returns null for an id), and WP_Mock cannot override an already-real function
     * - the same constraint documented in LegacyB2bMetaTest and
     * AdminOrderMetaBoxTest. capture_order() therefore bails on a null order before
     * reaching anything worth asserting.
     *
     * The key-scanning tests above are what actually guard the compatibility: they
     * were mutation-tested by renaming the key at a single call site, which the
     * earlier "does the file mention the key" form of this test did NOT catch.
     */
    public function testTheBehaviouralCaptureCaseIsDocumentedAsUncovered(): void
    {
        $reflection = new \ReflectionFunction('wc_get_order');

        $this->assertStringContainsString(
            'bootstrap',
            (string) $reflection->getFileName(),
            'wc_get_order() is no longer the bootstrap stub, so it may now be mockable - '
                . 'add the behavioural capture test that was not possible before.'
        );
    }
}
