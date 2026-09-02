<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use Briqpay\WooCommerce\Order_Management;
use Briqpay\WooCommerce\Session_Manager;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Two payload-shape defects that Briqpay rejected outright.
 *
 *   {"code":"INVALID_DATA","message":"body.locale pattern mismatch,
 *    body.locale has less length than allowed"}
 *
 * WordPress ships several languages with a bare code - Finnish is plain "fi", not
 * "fi_FI" - so strtolower(str_replace('_', '-', get_locale())) passed "fi"
 * straight through and every session on a Finnish store was refused.
 *
 * And a quantity of 400 was serialised as the string "400". WooCommerce stores the
 * cart quantity as whatever wc_stock_amount() returned, which is a string for a
 * plain integer input. It divides correctly in PHP, so the wrong type was invisible
 * until the API rejected it.
 */
class LocaleAndQuantityTest extends TestCase
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

    // ──────────────────────────────────────────────────────────────────────
    // Locale
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The reported failure: WordPress locale "fi" must become "fi-fi".
     */
    public function testTheReportedFinnishCaseIsFixed(): void
    {
        $this->assertSame('fi-fi', Session_Manager::normalize_locale('fi'));
    }

    /**
     * @dataProvider bareLanguageProvider
     */
    public function testBareLanguagesGainARegion($wp_locale, $expected): void
    {
        $this->assertSame($expected, Session_Manager::normalize_locale($wp_locale));
    }

    public function bareLanguageProvider()
    {
        return array(
            // Duplicating the language is correct for these.
            'finnish' => array('fi', 'fi-fi'),
            'german' => array('de', 'de-de'),
            'french' => array('fr', 'fr-fr'),
            'italian' => array('it', 'it-it'),
            'dutch' => array('nl', 'nl-nl'),
            'polish' => array('pl', 'pl-pl'),
            'spanish' => array('es', 'es-es'),
            'turkish' => array('tr', 'tr-tr'),

            // These would be wrong if duplicated, so they are mapped explicitly.
            'swedish' => array('sv', 'sv-se'),
            'danish' => array('da', 'da-dk'),
            'norwegian bokmal' => array('nb', 'nb-no'),
            'czech' => array('cs', 'cs-cz'),
            'estonian' => array('et', 'et-ee'),
            'greek' => array('el', 'el-gr'),
            'slovenian' => array('sl', 'sl-si'),
            'ukrainian' => array('uk', 'uk-ua'),
            'japanese' => array('ja', 'ja-jp'),
            'chinese' => array('zh', 'zh-cn'),
            'hebrew' => array('he', 'he-il'),
            'catalan' => array('ca', 'ca-es'),
        );
    }

    /**
     * @dataProvider alreadyTwoPartProvider
     */
    public function testTwoPartLocalesArePassedThroughLowercased($wp_locale, $expected): void
    {
        $this->assertSame($expected, Session_Manager::normalize_locale($wp_locale));
    }

    public function alreadyTwoPartProvider()
    {
        return array(
            array('sv_SE', 'sv-se'),
            array('fi_FI', 'fi-fi'),
            array('pt_BR', 'pt-br'),
            array('zh_CN', 'zh-cn'),
            array('nb_NO', 'nb-no'),
            // Region must be honoured, not overridden by the bare-language map.
            array('de_AT', 'de-at'),
            array('de_CH', 'de-ch'),
            array('fr_CA', 'fr-ca'),
        );
    }

    /**
     * Every English locale is sent as en-gb, whatever region WordPress reports.
     *
     * This deliberately OVERRIDES the region rather than honouring it, so it is the
     * one case where en_US does not become en-us. Asserted exhaustively because the
     * rule is surprising next to the others and easy to "fix" by mistake.
     *
     * @dataProvider englishLocaleProvider
     */
    public function testEveryEnglishLocaleBecomesEnGb($wp_locale): void
    {
        $this->assertSame(
            'en-gb',
            Session_Manager::normalize_locale($wp_locale),
            'All en_* locales must resolve to en-gb.'
        );
    }

    public function englishLocaleProvider()
    {
        return array(
            'bare' => array('en'),
            'united states' => array('en_US'),
            'united kingdom' => array('en_GB'),
            'australia' => array('en_AU'),
            'canada' => array('en_CA'),
            'new zealand' => array('en_NZ'),
            'south africa' => array('en_ZA'),
            'ireland' => array('en_IE'),
            'already normalised' => array('en-gb'),
            'uppercase' => array('EN_US'),
        );
    }

    /**
     * The English override must not leak into other languages that merely start
     * with an "e".
     */
    public function testTheEnglishOverrideDoesNotAffectOtherLanguages(): void
    {
        $this->assertSame('es-es', Session_Manager::normalize_locale('es'));
        $this->assertSame('et-ee', Session_Manager::normalize_locale('et'));
        $this->assertSame('el-gr', Session_Manager::normalize_locale('el'));
        $this->assertSame('eu-es', Session_Manager::normalize_locale('eu'));
    }

    /**
     * WordPress variant locales have a third segment, which fails the same pattern
     * as a bare code does - "de_DE_formal" would have become "de-de-formal".
     */
    public function testVariantLocalesAreTruncatedToTwoParts(): void
    {
        $this->assertSame('de-de', Session_Manager::normalize_locale('de_DE_formal'));
        $this->assertSame('de-ch', Session_Manager::normalize_locale('de_CH_informal'));
        $this->assertSame('nl-nl', Session_Manager::normalize_locale('nl_NL_formal'));
    }

    /**
     * @dataProvider unusableLocaleProvider
     */
    public function testUnusableInputFallsBackToAValidTag($wp_locale): void
    {
        $result = Session_Manager::normalize_locale($wp_locale);

        $this->assertSame(
            'en-gb',
            $result,
            'An unusable locale must still produce something Briqpay accepts.'
        );
    }

    public function unusableLocaleProvider()
    {
        return array(
            'empty' => array(''),
            'punctuation only' => array('___'),
            'digits' => array('12345'),
            'three letter language' => array('fil'),
            'null' => array(null),
        );
    }

    /**
     * Whatever the input, the result must satisfy the pattern that rejected "fi".
     *
     * @dataProvider everyLocaleProvider
     */
    public function testEveryResultMatchesTheRequiredPattern($wp_locale): void
    {
        $result = Session_Manager::normalize_locale($wp_locale);

        $this->assertMatchesRegularExpression(
            '/^[a-z]{2}-[a-z]{2,3}$/',
            $result,
            'Produced "' . $result . '" for "' . var_export($wp_locale, true) . '".'
        );
        $this->assertGreaterThanOrEqual(
            5,
            strlen($result),
            'The API rejected anything shorter than five characters.'
        );
    }

    public function everyLocaleProvider()
    {
        $cases = array();

        foreach ($this->bareLanguageProvider() as $label => $args) {
            $cases['bare ' . $label] = array($args[0]);
        }
        foreach ($this->alreadyTwoPartProvider() as $args) {
            $cases['two-part ' . $args[0]] = array($args[0]);
        }
        foreach ($this->unusableLocaleProvider() as $label => $args) {
            $cases['unusable ' . $label] = array($args[0]);
        }
        foreach ($this->englishLocaleProvider() as $label => $args) {
            $cases['english ' . $label] = array($args[0]);
        }
        $cases['variant'] = array('de_DE_formal');

        return $cases;
    }

    public function testBothSessionAndHostedPageUseTheSameNormalisation(): void
    {
        foreach (array(Session_Manager::class, Hosted_Payment_Page::class) as $class) {
            $method = new \ReflectionMethod($class, 'get_locale');
            $lines = file($method->getFileName());
            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $this->assertStringContainsString(
                'normalize_locale(get_locale())',
                $body,
                $class . ' must normalise the locale, not pass get_locale() through.'
            );
            $this->assertStringNotContainsString(
                "str_replace('_', '-', get_locale())",
                $body,
                $class . ' still uses the raw conversion that produced "fi".'
            );
        }
    }

    public function testTheSessionLocaleIsFilterable(): void
    {
        $method = new \ReflectionMethod(Session_Manager::class, 'get_locale');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("apply_filters('briqpay_locale'", $body);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Quantity
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The cart line quantity must be cast where it enters the payload.
     */
    public function testCartQuantityIsCastToInt(): void
    {
        $method = new \ReflectionMethod(Session_Manager::class, 'get_cart_items');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString(
            "\$quantity = (int) \$cart_item['quantity'];",
            $body,
            'A string quantity reached the JSON payload as "400".'
        );
        $this->assertStringNotContainsString(
            "\$quantity = \$cart_item['quantity'];",
            $body
        );
    }

    /**
     * A zero quantity would divide by zero two lines later. WooCommerce should never
     * produce one, but the division makes refusing the line the safer choice.
     */
    public function testAZeroQuantityLineIsRefusedRatherThanDividedBy(): void
    {
        $method = new \ReflectionMethod(Session_Manager::class, 'get_cart_items');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $guard_pos = strpos($body, 'if ($quantity < 1)');
        $divide_pos = strpos($body, '$line_total_exc_tax / $quantity');

        $this->assertNotFalse($guard_pos, 'The quantity must be sanity-checked.');
        $this->assertNotFalse($divide_pos);
        $this->assertLessThan(
            $divide_pos,
            $guard_pos,
            'The guard must precede the division it protects.'
        );
    }

    /**
     * The order-item write, so get_quantity() hands back an int later.
     */
    public function testOrderItemQuantityIsStoredAsInt(): void
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, 'create_order_at_decision');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString(
            "\$item->set_quantity((int) \$values['quantity']);",
            $body
        );
    }

    /**
     * And the read side, for orders created before that cast existed.
     */
    public function testOrderCartQuantityIsCastOnRead(): void
    {
        $method = new \ReflectionMethod(Order_Management::class, 'get_order_cart');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('$qty = (int) $item->get_quantity();', $body);
    }

    /**
     * Every payload builder must produce a real integer, not a numeric string.
     * PHP divides happily with either, so only the type check catches this.
     *
     * @dataProvider quantityCastSiteProvider
     */
    public function testPayloadQuantitiesAreIntegers($class, $method, $needle): void
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());
        $body = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));

        $this->assertStringContainsString($needle, $body, $class . '::' . $method . '()');
    }

    public function quantityCastSiteProvider()
    {
        return array(
            'session cart' => array(
                Session_Manager::class,
                'get_cart_items',
                "(int) \$cart_item['quantity']",
            ),
            'session totals' => array(
                Session_Manager::class,
                'get_session_data',
                "(int) \$item['quantity']",
            ),
            'order cart' => array(
                Order_Management::class,
                'get_order_cart',
                '(int) $item->get_quantity()',
            ),
            'hosted page totals' => array(
                Hosted_Payment_Page::class,
                'sum_cart_amounts',
                "(int) \$item['quantity']",
            ),
        );
    }

    /**
     * A real cast, proving PHP's silence about the difference.
     */
    public function testAStringQuantityBecomesAnIntegerNotAStringDigit(): void
    {
        $raw = '400';

        // The bug: arithmetic works, so nothing local ever complained.
        $this->assertSame(200.0, 80000 / $raw / 1.0);

        // Only the encoded type differs, which is what the API validated.
        // json_encode() rather than wp_json_encode(): identical for this shape, and
        // WordPress's wrapper is not loaded in the unit bootstrap.
        $this->assertSame('{"quantity":"400"}', json_encode(array('quantity' => $raw)));
        $this->assertSame('{"quantity":400}', json_encode(array('quantity' => (int) $raw)));
    }
}
