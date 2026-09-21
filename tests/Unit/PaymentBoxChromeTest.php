<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * WooCommerce draws every gateway's description area as div.payment_box: a lilac
 * panel with a ::before triangle pointing up at that gateway's radio button.
 *
 * When Briqpay is the only gateway we hide the radio, which leaves the triangle
 * pointing at nothing - a stray arrow above the top-left corner of the iframe -
 * and the panel itself becomes a second, unthemed box wrapping the checkout,
 * with 1em of padding above the iframe but 1em plus whatever bottom margin the
 * theme gives #briqpay below it, so the iframe sits visibly high inside it.
 * Both were reported from a live shop running 1.1.16.
 *
 * The cascade is the whole point here: a reset that loses to WooCommerce's own
 * rule is indistinguishable from no reset at all, so specificity is asserted
 * rather than assumed.
 */
class PaymentBoxChromeTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** The WooCommerce rule (woocommerce.css) this reset has to beat. */
    private const WOOCOMMERCE_SELECTOR = '.woocommerce-checkout #payment div.payment_box';

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
     * The stylesheet and the inline critical CSS both carry the reset.
     *
     * The stylesheet is the canonical copy; the inline block is printed in the
     * head so the chrome never flashes before the stylesheet arrives. A reset in
     * only one of them is a bug either way round - a flash, or a reset that
     * disappears when the stylesheet loads.
     *
     * @return array<string,array{0:string}>
     */
    public static function cssSources(): array
    {
        return array(
            'stylesheet'         => array('stylesheet'),
            'inline critical CSS' => array('inline'),
        );
    }

    /**
     * @dataProvider cssSources
     */
    public function testStripsThePanelAndItsArrowWhenBriqpayIsTheOnlyGateway(string $source): void
    {
        $rules = $this->rules($this->css($source));

        $panel = $this->findRule($rules, 'div.payment_box.payment_method_briqpay', false);
        $this->assertNotNull($panel, 'No rule resets the Briqpay payment_box panel.');

        $this->assertMatchesRegularExpression('/background:\s*none/', $panel['body']);
        $this->assertMatchesRegularExpression('/padding:\s*0/', $panel['body']);
        $this->assertMatchesRegularExpression('/margin:\s*0/', $panel['body']);

        $arrow = $this->findRule($rules, 'div.payment_box.payment_method_briqpay', true);
        $this->assertNotNull($arrow, 'The payment_box ::before triangle is never hidden.');
        $this->assertMatchesRegularExpression('/display:\s*none/', $arrow['body']);
    }

    /**
     * With a radio list to point at, the triangle and the panel are doing their
     * job - WooCommerce's styling has to stand for those shops.
     *
     * @dataProvider cssSources
     */
    public function testLeavesThePanelAloneWhenOtherGatewaysAreAvailable(string $source): void
    {
        $rules = $this->rules($this->css($source));

        foreach (array(false, true) as $pseudo) {
            $rule = $this->findRule($rules, 'div.payment_box.payment_method_briqpay', $pseudo);

            $this->assertStringContainsString(
                'body.briqpay-only-gateway',
                $rule['selector'],
                'The payment_box reset must be gated on Briqpay being the sole gateway.'
            );
        }
    }

    /**
     * @dataProvider cssSources
     */
    public function testTheResetOutranksWooCommercesOwnRule(string $source): void
    {
        $woo = $this->specificity(self::WOOCOMMERCE_SELECTOR);
        $rules = $this->rules($this->css($source));

        foreach (array(false, true) as $pseudo) {
            $rule = $this->findRule($rules, 'div.payment_box.payment_method_briqpay', $pseudo);
            $ours = $this->specificity($rule['selector']);

            $this->assertGreaterThan(
                $woo,
                $ours,
                sprintf(
                    '"%s" (%s) does not outrank WooCommerce\'s "%s" (%s), so the panel keeps its chrome.',
                    $rule['selector'],
                    implode(',', $ours),
                    self::WOOCOMMERCE_SELECTOR,
                    implode(',', $woo)
                )
            );
        }
    }

    /**
     * Removing the panel hands the framing to the theme, which only works if the
     * plugin then leaves no uneven spacing of its own inside it: 10px of top
     * padding on the container against whatever bottom margin the theme gives
     * #briqpay is what made the iframe look like it was sitting too high.
     *
     * @dataProvider cssSources
     */
    public function testLeavesNoSpacingOfItsOwnAroundTheIframe(string $source): void
    {
        $rules = $this->rules($this->css($source));

        $container = $this->findRuleBySelector($rules, 'body.briqpay-only-gateway #briqpay-iframe-container');
        $this->assertNotNull($container, 'The container keeps its top padding once the panel is gone.');
        $this->assertMatchesRegularExpression('/padding-top:\s*0/', $container['body']);

        $inner = $this->findRuleBySelector($rules, 'body.briqpay-only-gateway #briqpay-iframe-container > #briqpay:last-child');
        $this->assertNotNull($inner, 'Nothing cancels the theme bottom margin below the iframe.');
        $this->assertMatchesRegularExpression('/margin-bottom:\s*0/', $inner['body']);
    }

    /**
     * The margin reset is scoped to #briqpay actually being the last thing in the
     * container. If anything is ever rendered after the iframe, the theme's
     * spacing is separating it from something again and has to stand.
     *
     * @dataProvider cssSources
     */
    public function testTheMarginResetOnlyAppliesWhileTheIframeIsLast(string $source): void
    {
        $rule = $this->findRuleBySelector(
            $this->rules($this->css($source)),
            'body.briqpay-only-gateway #briqpay-iframe-container > #briqpay:last-child'
        );

        $this->assertStringContainsString(':last-child', $rule['selector']);
    }

    /**
     * Both spacing rules override a theme, so they must stay overridable in turn.
     *
     * @dataProvider cssSources
     */
    public function testTheSpacingResetsBeatTheirDefaultsWithoutImportant(string $source): void
    {
        $rules = $this->rules($this->css($source));

        $cases = array(
            // Our own #briqpay-iframe-container rule in this same stylesheet.
            'body.briqpay-only-gateway #briqpay-iframe-container' => '#briqpay-iframe-container',
            // A theme styling #briqpay directly, as the reporting shop does.
            'body.briqpay-only-gateway #briqpay-iframe-container > #briqpay:last-child' => '#briqpay',
        );

        foreach ($cases as $selector => $beaten) {
            $rule = $this->findRuleBySelector($rules, $selector);
            $this->assertNotNull($rule, sprintf('No rule for "%s".', $selector));

            $this->assertGreaterThan(
                $this->specificity($beaten),
                $this->specificity($rule['selector']),
                sprintf('"%s" does not outrank "%s".', $selector, $beaten)
            );
            $this->assertStringNotContainsString('!important', $rule['body']);
        }
    }

    /**
     * Neither copy may reach for !important: a theme has to stay able to put its
     * own panel back with an ordinary rule of its own.
     *
     * @dataProvider cssSources
     */
    public function testTheResetIsOverridableByATheme(string $source): void
    {
        $rules = $this->rules($this->css($source));

        foreach (array(false, true) as $pseudo) {
            $rule = $this->findRule($rules, 'div.payment_box.payment_method_briqpay', $pseudo);

            $this->assertStringNotContainsString('!important', $rule['body']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param string $source 'stylesheet' or 'inline'
     */
    private function css(string $source): string
    {
        if ('stylesheet' === $source) {
            $path = dirname(__DIR__, 2) . '/assets/css/briqpay-checkout.css';
            $this->assertFileExists($path);

            return (string) file_get_contents($path);
        }

        return $this->captureInlineCriticalCss();
    }

    /**
     * Run enqueue_critical_assets() and keep whatever it hands to
     * wp_add_inline_style(), rather than reading the PHP source: a reset sitting
     * in a string the method never reaches is not shipped.
     */
    private function captureInlineCriticalCss(): string
    {
        $captured = '';

        // get_option() is a real function from the test bootstrap, so WP_Mock
        // cannot intercept it - the option store is the way in.
        \Briqpay_Test_Options::$store['woocommerce_briqpay_settings'] = array('enabled' => 'yes');

        WP_Mock::userFunction('is_checkout', array('return' => true));
        WP_Mock::userFunction('is_order_received_page', array('return' => false));
        WP_Mock::userFunction('is_cart', array('return' => false));
        WP_Mock::userFunction('wp_add_inline_script', array('return' => true));
        WP_Mock::userFunction('wp_add_inline_style')->andReturnUsing(
            function ($handle, $data) use (&$captured) {
                $captured .= $data;
                return true;
            }
        );

        try {
            (new Checkout_Handler())->enqueue_critical_assets();
        } finally {
            \Briqpay_Test_Options::reset();
        }

        $this->assertNotSame('', $captured, 'enqueue_critical_assets() added no inline style at all.');

        return $captured;
    }

    /**
     * Split a stylesheet into {selector, body} pairs, comments stripped.
     *
     * @return array<int,array{selector:string,body:string}>
     */
    private function rules(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        $rules = array();
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', (string) $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $rules[] = array(
                    'selector' => trim(preg_replace('/\s+/', ' ', $match[1])),
                    'body'     => trim($match[2]),
                );
            }
        }

        $this->assertNotEmpty($rules, 'Parsed no rules out of the CSS.');

        return $rules;
    }

    /**
     * @param array<int,array{selector:string,body:string}> $rules
     * @param bool                                          $pseudo Match the ::before rule rather than the element rule.
     * @return array{selector:string,body:string}|null
     */
    private function findRule(array $rules, string $needle, bool $pseudo): ?array
    {
        foreach ($rules as $rule) {
            if (false === strpos($rule['selector'], $needle)) {
                continue;
            }

            if ($pseudo === (false !== strpos($rule['selector'], '::before'))) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<int,array{selector:string,body:string}> $rules
     * @return array{selector:string,body:string}|null
     */
    private function findRuleBySelector(array $rules, string $selector): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['selector'] === $selector) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * CSS specificity as [ids, classes, types], comparable with PHP's array
     * comparison (element-wise, left to right - which is exactly the cascade's
     * own ordering).
     *
     * @return array{0:int,1:int,2:int}
     */
    private function specificity(string $selector): array
    {
        $selector = preg_replace('/::[a-z-]+/', '', $selector);

        preg_match_all('/#[A-Za-z0-9_-]+/', (string) $selector, $ids);
        preg_match_all('/(?<!:):(?!:)[a-z-]+|\.[A-Za-z0-9_-]+|\[[^\]]+\]/', (string) $selector, $classes);
        preg_match_all('/(?:^|[\s>+~])([a-z][a-z0-9]*)/i', (string) $selector, $types);

        return array(count($ids[0]), count($classes[0]), count($types[1]));
    }
}
