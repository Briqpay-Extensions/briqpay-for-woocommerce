<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * T2 / T5 / T8 - the posted-data stash, its replay, and Blocks normalisation.
 *
 * WooCommerce passes the submitted checkout form to
 * woocommerce_checkout_create_order, ..._update_order_meta and
 * ..._order_processed. Our decision request has none of it - it posts only a
 * session ID - so the form is captured during ajax_get_session() and replayed
 * when the hooks fire.
 */
class CheckoutPostedDataTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var array Backing store for the fake WC session. */
    private $session_store = array();

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $this->session_store = array();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function mockWcSession()
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
            return array_key_exists($key, $this->session_store) ? $this->session_store[$key] : $default;
        });
        $session->shouldReceive('set')->andReturnUsing(function ($key, $value) {
            $this->session_store[$key] = $value;
        });

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;
        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    private function mockWcWithoutSession()
    {
        $wc = Mockery::mock('WooCommerce');
        $wc->session = null;
        WP_Mock::userFunction('WC', array('return' => $wc));
    }

    private function invoke($method, array $args = array())
    {
        $handler = new Checkout_Handler();
        $ref = new \ReflectionMethod(Checkout_Handler::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($handler, $args);
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 - the stash
    // ──────────────────────────────────────────────────────────────────────

    public function testClassicFormRoundTripsIntact(): void
    {
        $this->mockWcSession();

        $form = array(
            'billing_first_name' => 'Anna',
            'billing_city' => 'Stockholm',
            'my_delivery_date' => '2026-09-01',
        );

        $this->invoke('stash_posted_data', array($form, 'classic'));

        $this->assertSame($form, Checkout_Handler::get_stashed_posted_data());
        $this->assertSame('classic', Checkout_Handler::get_stashed_posted_data_source());
    }

    /**
     * T2.2 - nonces are request-scoped. Replaying a stale one into a later
     * request would fail any plugin that re-verifies it, and it is not data the
     * plugin needs.
     */
    public function testSecurityFieldsAreStripped(): void
    {
        $this->mockWcSession();

        $this->invoke('stash_posted_data', array(array(
            'billing_first_name' => 'Anna',
            'security' => 'abc',
            'nonce' => 'def',
            '_wpnonce' => 'ghi',
            '_wp_http_referer' => '/checkout',
            'woocommerce-process-checkout-nonce' => 'jkl',
        ), 'classic'));

        $this->assertSame(
            array('billing_first_name' => 'Anna'),
            Checkout_Handler::get_stashed_posted_data()
        );
    }

    /**
     * T2.4 - overwrite, never merge. A field the customer cleared must not
     * linger from an earlier sync and get replayed as if still set.
     */
    public function testLaterSyncOverwritesRatherThanMerging(): void
    {
        $this->mockWcSession();

        $this->invoke('stash_posted_data', array(
            array('billing_first_name' => 'Anna', 'gift_message' => 'Happy birthday'),
            'classic',
        ));
        $this->invoke('stash_posted_data', array(
            array('billing_first_name' => 'Anna'),
            'classic',
        ));

        $this->assertSame(
            array('billing_first_name' => 'Anna'),
            Checkout_Handler::get_stashed_posted_data(),
            'A cleared field must not survive into the replay.'
        );
    }

    /**
     * Found in live testing on Blocks checkout.
     *
     * A single ajax_get_session() request from Blocks carries BOTH 'blocks_data'
     * and an empty 'checkout_data', so both intake branches run. Because a stash
     * deliberately overwrites rather than merges, the empty classic write wiped the
     * six fields the Blocks branch had just captured and flipped the recorded
     * source to 'classic' - so the checkout hooks received nothing and the Blocks
     * normalisation never applied.
     */
    public function testAnEmptyStashDoesNotClobberAGoodOne(): void
    {
        $this->mockWcSession();

        $blocks = array('billing_address' => array('first_name' => 'Anna', 'city' => 'Stockholm'));

        $this->invoke('stash_posted_data', array($blocks, 'blocks'));
        // The classic branch then runs in the same request with an empty form.
        $this->invoke('stash_posted_data', array(array(), 'classic'));

        $this->assertSame(
            $blocks,
            Checkout_Handler::get_stashed_posted_data(),
            'The Blocks payload must survive the empty classic write.'
        );
        $this->assertSame(
            'blocks',
            Checkout_Handler::get_stashed_posted_data_source(),
            'The source must not be flipped to classic by an empty write.'
        );
    }

    /**
     * A form of nothing but stripped security fields is also empty, and must not
     * clobber either.
     */
    public function testAStashOfOnlySecurityFieldsIsTreatedAsEmpty(): void
    {
        $this->mockWcSession();

        $this->invoke('stash_posted_data', array(array('billing_city' => 'Stockholm'), 'classic'));
        $this->invoke('stash_posted_data', array(array('security' => 'abc', '_wpnonce' => 'def'), 'classic'));

        $this->assertSame(
            array('billing_city' => 'Stockholm'),
            Checkout_Handler::get_stashed_posted_data()
        );
    }

    /**
     * A non-empty later write must still replace, or a cleared field would be
     * replayed as if still set.
     */
    public function testANonEmptyStashStillOverwrites(): void
    {
        $this->mockWcSession();

        $this->invoke('stash_posted_data', array(array('a' => '1', 'b' => '2'), 'classic'));
        $this->invoke('stash_posted_data', array(array('a' => '1'), 'classic'));

        $this->assertSame(array('a' => '1'), Checkout_Handler::get_stashed_posted_data());
    }

    public function testMissingStashReturnsAnArrayNotNull(): void
    {
        $this->mockWcSession();

        $this->assertSame(array(), Checkout_Handler::get_stashed_posted_data());
        $this->assertSame('', Checkout_Handler::get_stashed_posted_data_source());
    }

    public function testClearingForgetsBothKeys(): void
    {
        $this->mockWcSession();

        $this->invoke('stash_posted_data', array(array('billing_city' => 'Stockholm'), 'classic'));
        Checkout_Handler::clear_stashed_posted_data();

        $this->assertSame(array(), Checkout_Handler::get_stashed_posted_data());
        $this->assertSame('', Checkout_Handler::get_stashed_posted_data_source());
    }

    /**
     * T2.6 - webhook context has no WC session at all. Every accessor must be a
     * safe no-op there, because the commit-hook fallback runs in exactly that
     * context.
     */
    public function testAllAccessorsAreSafeWithoutAWcSession(): void
    {
        $this->mockWcWithoutSession();

        $this->assertSame(array(), Checkout_Handler::get_stashed_posted_data());
        $this->assertSame('', Checkout_Handler::get_stashed_posted_data_source());

        // Neither of these may fatal.
        Checkout_Handler::clear_stashed_posted_data();
        $this->invoke('stash_posted_data', array(array('billing_city' => 'X'), 'classic'));

        $this->assertSame(array(), Checkout_Handler::get_stashed_posted_data());
    }

    public function testNonArrayStashIsIgnored(): void
    {
        $this->mockWcSession();
        $this->session_store[Checkout_Handler::POSTED_DATA_KEY] = 'corrupt';

        $this->assertSame(array(), Checkout_Handler::get_stashed_posted_data());
    }

    // ──────────────────────────────────────────────────────────────────────
    // T5 - $_POST superimposition
    // ──────────────────────────────────────────────────────────────────────

    public function testPostIsRestoredOnTheNormalPath(): void
    {
        $original = array('sessionId' => 'sess_1');
        $_POST = $original;
        $_REQUEST = $original;

        $seen = null;
        $this->invoke('with_posted_data', array(
            array('billing_first_name' => 'Anna'),
            function () use (&$seen) {
                $seen = $_POST;
            },
        ));

        $this->assertSame('Anna', $seen['billing_first_name'], 'Stash must be visible inside.');
        $this->assertSame($original, $_POST, '$_POST must be restored exactly.');
        $this->assertSame($original, $_REQUEST, '$_REQUEST must be restored exactly.');
    }

    /**
     * T5.2 - restoration must survive a throwing hook, or a mutated superglobal
     * leaks into the rest of the request.
     */
    public function testPostIsRestoredWhenTheCallbackThrows(): void
    {
        $original = array('sessionId' => 'sess_1');
        $_POST = $original;
        $_REQUEST = $original;

        try {
            $this->invoke('with_posted_data', array(
                array('billing_first_name' => 'Anna'),
                function () {
                    throw new \RuntimeException('plugin exploded');
                },
            ));
            $this->fail('The exception must propagate to fire_once()\'s catch.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame($original, $_POST);
        $this->assertSame($original, $_REQUEST);
    }

    /**
     * T5.3 - the real request must never be misrepresented, so live values win
     * over stashed ones.
     */
    public function testRealRequestValuesWinOverStashedOnes(): void
    {
        $_POST = array('billing_first_name' => 'Live');
        $_REQUEST = array('billing_first_name' => 'Live');

        $seen = null;
        $this->invoke('with_posted_data', array(
            array('billing_first_name' => 'Stashed', 'billing_city' => 'Stockholm'),
            function () use (&$seen) {
                $seen = $_POST;
            },
        ));

        $this->assertSame('Live', $seen['billing_first_name']);
        $this->assertSame('Stockholm', $seen['billing_city'], 'Stash still fills the gaps.');
    }

    public function testEmptyStashLeavesPostUntouched(): void
    {
        $original = array('sessionId' => 'sess_1');
        $_POST = $original;
        $_REQUEST = $original;

        $seen = null;
        $this->invoke('with_posted_data', array(
            array(),
            function () use (&$seen) {
                $seen = $_POST;
            },
        ));

        $this->assertSame($original, $seen);
        $this->assertSame($original, $_POST);
    }

    public function testNestedInvocationDoesNotCorruptTheOuterRestore(): void
    {
        $original = array('sessionId' => 'sess_1');
        $_POST = $original;
        $_REQUEST = $original;

        $this->invoke('with_posted_data', array(
            array('outer' => '1'),
            function () {
                $this->invoke('with_posted_data', array(
                    array('inner' => '2'),
                    function () {
                        // no-op
                    },
                ));
                // The inner restore must not have discarded the outer overlay.
                $this->assertSame('1', $_POST['outer']);
            },
        ));

        $this->assertSame($original, $_POST);
    }

    /**
     * The superimposition is the least elegant part of the design, so it gets
     * its own kill switch independent of the master gate.
     */
    public function testSuperimpositionCanBeDisabledByFilter(): void
    {
        WP_Mock::onFilter('briqpay_superimpose_post_data')
            ->with(true, array('billing_first_name' => 'Anna'))
            ->reply(false);

        $_POST = array('sessionId' => 'sess_1');
        $seen = null;

        $this->invoke('with_posted_data', array(
            array('billing_first_name' => 'Anna'),
            function () use (&$seen) {
                $seen = $_POST;
            },
        ));

        $this->assertArrayNotHasKey(
            'billing_first_name',
            $seen,
            'With the filter off, only the $data argument carries the form.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T8 - Blocks normalisation
    // ──────────────────────────────────────────────────────────────────────

    public function testBillingAddressMapsToClassicKeys(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'billing_address' => array(
                'first_name' => 'Anna',
                'last_name' => 'Andersson',
                'address_1' => 'Sveavagen 20',
                'postcode' => '11157',
                'city' => 'Stockholm',
                'country' => 'SE',
                'email' => 'anna@example.com',
                'phone' => '+46701234567',
                'company' => 'Briqpay AB',
            ),
        ));

        $this->assertSame('Anna', $result['billing_first_name']);
        $this->assertSame('Andersson', $result['billing_last_name']);
        $this->assertSame('Sveavagen 20', $result['billing_address_1']);
        $this->assertSame('11157', $result['billing_postcode']);
        $this->assertSame('Stockholm', $result['billing_city']);
        $this->assertSame('SE', $result['billing_country']);
        $this->assertSame('anna@example.com', $result['billing_email']);
        $this->assertSame('+46701234567', $result['billing_phone']);
        $this->assertSame('Briqpay AB', $result['billing_company']);
    }

    public function testShippingAddressMapsToClassicKeys(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'shipping_address' => array(
                'first_name' => 'Anna',
                'city' => 'Goteborg',
                'country' => 'SE',
            ),
        ));

        $this->assertSame('Anna', $result['shipping_first_name']);
        $this->assertSame('Goteborg', $result['shipping_city']);
        $this->assertSame('SE', $result['shipping_country']);
    }

    /**
     * T8.3 - a plugin that treats a present-but-empty key as "clear this" would
     * blank good data, so empty values are dropped rather than mapped.
     */
    public function testEmptyValuesAreDroppedNotMapped(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'billing_address' => array(
                'first_name' => 'Anna',
                'address_2' => '',
                'company' => '',
            ),
        ));

        $this->assertArrayHasKey('billing_first_name', $result);
        $this->assertArrayNotHasKey('billing_address_2', $result);
        $this->assertArrayNotHasKey('billing_company', $result);
    }

    public function testAbsentAddressBlocksProduceNoKeys(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array());

        $this->assertSame(array(), $result);
    }

    public function testNonArrayAddressBlockIsSkipped(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'billing_address' => 'corrupt',
        ));

        $this->assertArrayNotHasKey('billing_first_name', $result);
    }

    /**
     * T8.4 - custom Blocks extension data uses its own key names, and a plugin
     * reading it expects exactly what it sent.
     */
    public function testUnknownFieldsPassThroughUntouched(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'billing_address' => array('first_name' => 'Anna'),
            'my_extension_field' => 'keep me',
            'shipping_rates' => array(0 => 'flat_rate:1'),
        ));

        $this->assertSame('keep me', $result['my_extension_field']);
        $this->assertSame(array(0 => 'flat_rate:1'), $result['shipping_rates']);
        $this->assertArrayNotHasKey('billing_address', $result, 'The nested block itself is consumed.');
    }

    public function testNormalisedKeysWinOverPassThroughCollisions(): void
    {
        $result = Checkout_Handler::normalise_blocks_data(array(
            'billing_address' => array('first_name' => 'FromBlock'),
            'billing_first_name' => 'FromPassThrough',
        ));

        $this->assertSame(
            'FromBlock',
            $result['billing_first_name'],
            'The address block is the authoritative source for address fields.'
        );
    }
}
