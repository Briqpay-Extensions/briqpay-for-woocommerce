<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped translation catalogues.
 *
 * A broken .mo fails silently at runtime - WordPress just falls back to English -
 * so the only way to notice is to check the binaries. The risks worth guarding:
 *
 *  - A .po edited without recompiling, so the .mo is stale.
 *  - A translation that drops or reorders the %1$s / %2$s placeholders, which makes
 *    vsprintf() emit the wrong text or nothing at all.
 *  - The textdomain never being loaded, which was the state before this work: the
 *    plugin header declared Text Domain and Domain Path, but nothing called
 *    load_plugin_textdomain(), so bundled catalogues were ignored entirely.
 */
class TranslationsTest extends TestCase
{
    const DOMAIN = 'briqpay-for-woocommerce';

    /**
     * The string this work exists to translate.
     */
    const ERROR_MSG = 'Fill in the customer details on this order before creating a "%1$s" hosted payment page. Missing: %2$s. That flow shows payment methods only, so the customer cannot enter these themselves - the page would never unlock. Choose "Business - Full Checkout" if you want Briqpay to collect the details instead.';

    private function languagesDir()
    {
        return dirname(__DIR__, 2) . '/languages';
    }

    /**
     * Minimal .mo reader - enough to pull the msgid => msgstr map.
     *
     * Deliberately parses the binary rather than trusting the .po: a stale .mo is
     * one of the failures being guarded against, and only the binary is what
     * WordPress actually reads.
     *
     * @param string $path
     * @return array<string,string>
     */
    private function readMo($path)
    {
        $data = file_get_contents($path);
        $magic = substr($data, 0, 4);

        if ("\xde\x12\x04\x95" === $magic) {
            $format = 'V'; // little endian
        } elseif ("\x95\x04\x12\xde" === $magic) {
            $format = 'N'; // big endian
        } else {
            $this->fail('Not a .mo file: ' . basename($path));
        }

        $header = unpack($format . '7', substr($data, 4, 28));
        $count = $header[2];
        $orig_offset = $header[3];
        $trans_offset = $header[4];

        $entries = array();

        for ($i = 0; $i < $count; $i++) {
            $o = unpack($format . '2', substr($data, $orig_offset + ($i * 8), 8));
            $t = unpack($format . '2', substr($data, $trans_offset + ($i * 8), 8));

            $msgid = substr($data, $o[2], $o[1]);
            $msgstr = substr($data, $t[2], $t[1]);

            $entries[$msgid] = $msgstr;
        }

        return $entries;
    }

    private function catalogues()
    {
        $files = glob($this->languagesDir() . '/' . self::DOMAIN . '-*.mo');
        return $files ? $files : array();
    }

    public function testCataloguesAreShipped(): void
    {
        $this->assertNotEmpty(
            $this->catalogues(),
            'No compiled .mo files - the .po files are useless without them.'
        );
    }

    public function testAPotTemplateExists(): void
    {
        $this->assertFileExists(
            $this->languagesDir() . '/' . self::DOMAIN . '.pot',
            'Translators need the template to add further languages.'
        );
    }

    /**
     * Every locale must have BOTH a source .po and a compiled .mo. A .po without a
     * .mo does nothing at runtime; a .mo without a .po cannot be maintained.
     */
    public function testEveryCatalogueHasBothFiles(): void
    {
        $pos = glob($this->languagesDir() . '/' . self::DOMAIN . '-*.po');
        $mos = $this->catalogues();

        $po_locales = array_map(function ($f) {
            return basename($f, '.po');
        }, $pos ? $pos : array());

        $mo_locales = array_map(function ($f) {
            return basename($f, '.mo');
        }, $mos);

        sort($po_locales);
        sort($mo_locales);

        $this->assertSame($po_locales, $mo_locales, 'A .po and .mo must exist for each locale.');
    }

    /**
     * @dataProvider catalogueProvider
     */
    public function testTheErrorMessageIsTranslated($mo): void
    {
        $entries = $this->readMo($mo);

        $this->assertArrayHasKey(
            self::ERROR_MSG,
            $entries,
            basename($mo) . ' has no entry for the customer-data error. If the English '
                . 'string was edited, regenerate the .pot and .po files - the old msgid no '
                . 'longer matches and the translation is dead.'
        );

        $translated = $entries[self::ERROR_MSG];

        $this->assertNotSame('', $translated, basename($mo) . ': entry present but empty.');
        $this->assertNotSame(self::ERROR_MSG, $translated, basename($mo) . ': still English.');
    }

    /**
     * The message is built with sprintf(). A translation that drops a placeholder
     * loses the flow name or the field list; one that renumbers them swaps the two.
     *
     * @dataProvider catalogueProvider
     */
    public function testPlaceholdersSurviveTranslation($mo): void
    {
        $entries = $this->readMo($mo);
        $translated = $entries[self::ERROR_MSG] ?? '';

        foreach (array('%1$s', '%2$s') as $placeholder) {
            $this->assertStringContainsString(
                $placeholder,
                $translated,
                basename($mo) . ' lost ' . $placeholder . ' - sprintf() would drop that value.'
            );
        }

        $this->assertSame(
            1,
            substr_count($translated, '%1$s'),
            basename($mo) . ' repeats %1$s.'
        );
        $this->assertSame(
            1,
            substr_count($translated, '%2$s'),
            basename($mo) . ' repeats %2$s.'
        );
    }

    /**
     * The message interpolates other translated strings. If those are missing the
     * merchant gets a half-translated sentence, which reads worse than plain English.
     *
     * @dataProvider catalogueProvider
     */
    public function testTheInterpolatedStringsAreAlsoTranslated($mo): void
    {
        $entries = $this->readMo($mo);

        $required = array(
            // Field labels, which become %2$s.
            'billing address',
            'billing city',
            'billing postcode',
            'billing country',
            'billing email',
            'company name',
            // Flow labels, which become %1$s.
            'Consumer',
            'Business - Payment Methods Only',
            'Business - Full Checkout',
        );

        foreach ($required as $msgid) {
            $this->assertArrayHasKey($msgid, $entries, basename($mo) . ' is missing "' . $msgid . '".');
            $this->assertNotSame('', $entries[$msgid], basename($mo) . ': "' . $msgid . '" is empty.');
        }
    }

    /**
     * Real formatting, to prove the pieces fit together rather than merely existing.
     *
     * @dataProvider catalogueProvider
     */
    public function testTheTranslatedMessageFormatsCleanly($mo): void
    {
        $entries = $this->readMo($mo);

        $rendered = sprintf(
            $entries[self::ERROR_MSG],
            $entries['Business - Payment Methods Only'],
            $entries['billing city'] . ', ' . $entries['billing email']
        );

        $this->assertStringNotContainsString('%1$s', $rendered, 'A placeholder went unfilled.');
        $this->assertStringNotContainsString('%2$s', $rendered);
        $this->assertStringContainsString($entries['billing city'], $rendered);
        $this->assertStringContainsString($entries['Business - Payment Methods Only'], $rendered);
    }

    /**
     * @dataProvider catalogueProvider
     */
    public function testCataloguesAreValidUtf8($mo): void
    {
        foreach ($this->readMo($mo) as $msgid => $msgstr) {
            $this->assertTrue(
                '' === $msgstr || false !== mb_detect_encoding($msgstr, 'UTF-8', true),
                basename($mo) . ' contains a non-UTF-8 translation for "' . $msgid . '".'
            );
        }
    }

    public function catalogueProvider()
    {
        $cases = array();

        foreach (glob(dirname(__DIR__, 2) . '/languages/' . self::DOMAIN . '-*.mo') as $mo) {
            $cases[basename($mo, '.mo')] = array($mo);
        }

        // An empty provider would silently skip every test above.
        if (empty($cases)) {
            $cases['none found'] = array('');
        }

        return $cases;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Loading
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Bundled catalogues are not picked up automatically on the WordPress versions
     * this plugin supports. Declaring Text Domain and Domain Path in the header is
     * not enough - before this was added, every shipped .mo was ignored.
     */
    public function testTheTextdomainIsActuallyLoaded(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/briqpay-for-woocommerce.php');

        $this->assertStringContainsString(
            "load_plugin_textdomain(",
            $plugin,
            'Nothing loads the bundled translations.'
        );
        $this->assertStringContainsString(
            "'" . self::DOMAIN . "'",
            $plugin
        );
        $this->assertStringContainsString(
            "/languages",
            $plugin,
            'The Domain Path must be passed to load_plugin_textdomain().'
        );
    }

    /**
     * WordPress has far more locales than any plugin ships catalogues for. A site
     * set to de_DE_formal, de_AT, pt_BR, es_MX, fr_CA, nl_BE or sv_FI finds no exact
     * file and used to fall back to English - even though the LANGUAGE is one we
     * translated. The catalogue for the same base language is reused instead.
     *
     * @dataProvider variantLocaleProvider
     */
    public function testARegionalVariantResolvesToItsBaseLanguage($locale, $expected_locale): void
    {
        $resolved = $this->resolveFallback($locale);

        $this->assertNotFalse(
            $resolved,
            'A site set to ' . $locale . ' would fall back to English.'
        );
        $this->assertSame(
            self::DOMAIN . '-' . $expected_locale . '.mo',
            basename($resolved),
            $locale . ' should reuse the ' . $expected_locale . ' catalogue.'
        );
    }

    public function variantLocaleProvider()
    {
        return array(
            // WordPress variant locales - a third segment.
            'german formal' => array('de_DE_formal', 'de_DE'),
            'dutch formal' => array('nl_NL_formal', 'nl_NL'),
            // Same language, different country.
            'austrian german' => array('de_AT', 'de_DE'),
            'swiss german' => array('de_CH', 'de_DE'),
            'brazilian portuguese' => array('pt_BR', 'pt_PT'),
            'mexican spanish' => array('es_MX', 'es_ES'),
            'argentine spanish' => array('es_AR', 'es_ES'),
            'canadian french' => array('fr_CA', 'fr_FR'),
            'belgian french' => array('fr_BE', 'fr_FR'),
            'belgian dutch' => array('nl_BE', 'nl_NL'),
            // Swedish as spoken in Finland - a real WordPress locale.
            'finland swedish' => array('sv_FI', 'sv_SE'),
            // Norwegian: nynorsk and the bare code share the bokmal catalogue.
            'nynorsk' => array('nn_NO', 'nb_NO'),
            'bare norwegian' => array('no', 'nb_NO'),
            // Exact matches must still resolve to themselves.
            'exact swedish' => array('sv_SE', 'sv_SE'),
            'bare finnish' => array('fi', 'fi'),
        );
    }

    /**
     * English is the source language, so there is no catalogue and none should be
     * invented - returning one would mean loading another language's strings.
     *
     * @dataProvider untranslatedLocaleProvider
     */
    public function testLanguagesWeDoNotShipResolveToNothing($locale): void
    {
        $this->assertFalse(
            $this->resolveFallback($locale),
            $locale . ' has no catalogue and must not borrow another language\'s.'
        );
    }

    public function untranslatedLocaleProvider()
    {
        return array(
            'english' => array('en_US'),
            'british english' => array('en_GB'),
            'czech' => array('cs_CZ'),
            'estonian' => array('et'),
            'japanese' => array('ja'),
            'garbage' => array(''),
        );
    }

    /**
     * Mirrors Briqpay_WooCommerce::find_language_fallback(), which is private on a
     * class the unit bootstrap does not load (it lives in the main plugin file and
     * needs WordPress). Kept in step by asserting the real implementation still
     * scans the directory and applies the Norwegian aliases.
     *
     * @param string $locale
     * @return string|false
     */
    private function resolveFallback($locale)
    {
        $parts = preg_split('/[_-]/', (string) $locale);
        $language = isset($parts[0]) ? strtolower($parts[0]) : '';

        if ('' === $language) {
            return false;
        }

        $aliases = array('nn' => 'nb', 'no' => 'nb');
        $language = isset($aliases[$language]) ? $aliases[$language] : $language;

        foreach ($this->catalogues() as $file) {
            $candidate = substr(basename($file, '.mo'), strlen(self::DOMAIN) + 1);
            $candidate_parts = preg_split('/[_-]/', $candidate);

            if (strtolower($candidate_parts[0]) === $language) {
                return $file;
            }
        }

        return false;
    }

    public function testTheFallbackIsDirectoryDrivenNotHardcoded(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/briqpay-for-woocommerce.php');

        $this->assertStringContainsString(
            "glob(\$dir . \$domain . '-*.mo')",
            $plugin,
            'Adding a catalogue must cover its regional variants automatically.'
        );
        $this->assertStringContainsString(
            "array('nn' => 'nb', 'no' => 'nb')",
            $plugin,
            'Norwegian written standards share a catalogue.'
        );
    }

    /**
     * An official translation in WP_LANG_DIR must win. The fallback only runs when
     * the exact lookup found nothing.
     */
    public function testTheExactLookupIsTriedFirst(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/briqpay-for-woocommerce.php');

        $exact_pos = strpos($plugin, 'if (load_plugin_textdomain($domain, false,');
        $fallback_pos = strpos($plugin, 'self::find_language_fallback($domain, $locale)');

        $this->assertNotFalse($exact_pos, 'The exact lookup must come first.');
        $this->assertNotFalse($fallback_pos);
        $this->assertLessThan($fallback_pos, $exact_pos);
        $this->assertStringContainsString(
            "                return;
",
            substr($plugin, $exact_pos, $fallback_pos - $exact_pos),
            'A successful exact load must return before the fallback runs.'
        );
    }

    /**
     * determine_locale() is what WordPress itself resolves the language to - the
     * user's admin language when set, otherwise the site language. Using
     * get_locale() here would ignore a per-user choice.
     */
    public function testTheFallbackUsesTheLocaleWordPressResolved(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/briqpay-for-woocommerce.php');

        $this->assertStringContainsString(
            '$locale = determine_locale();',
            $plugin,
            'The admin message must follow the language WordPress resolved.'
        );
    }

    /**
     * On init, not plugins_loaded: WordPress 6.7+ warns when a translation is
     * loaded before init.
     */
    public function testTheTextdomainLoadsOnInit(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/briqpay-for-woocommerce.php');

        $this->assertStringContainsString(
            "add_action('init', array(\$this, 'load_textdomain'))",
            $plugin,
            'Loading earlier than init triggers a deprecation notice in WordPress 6.7+.'
        );
    }
}
