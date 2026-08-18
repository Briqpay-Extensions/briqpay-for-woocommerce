<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minor-Unit Money Conversion
 *
 * Briqpay amounts are integers in the currency's minor unit. This plugin has
 * historically hard-coded that conversion as x100 / /100 at every call site,
 * which is correct only for two-decimal currencies. For a zero-decimal currency
 * (JPY, ISK, KRW) every amount is sent 100x too large; for a three-decimal one
 * (KWD, BHD, TND) it is 10x too small.
 *
 * Two things are done about that here:
 *
 *  1. This class is the one place the conversion is defined, so there is a single
 *     thing to change rather than thirty.
 *  2. is_supported_precision() lets the gateway refuse to offer itself where the
 *     hard-coded assumption does not hold, instead of quietly sending wrong
 *     amounts. See Gateway::is_available().
 *
 * The remaining x100 call sites elsewhere in the plugin are equivalent to
 * to_minor() while precision is pinned at two decimals by that guard. Migrating
 * them is follow-up work; doing it without the guard in place would simply move
 * the bug around.
 */
class Money
{
    /**
     * Decimal places the store is configured for.
     *
     * wc_get_price_decimals() is WooCommerce's own accessor for
     * 'woocommerce_price_num_decimals'. Defaults to 2 when WooCommerce is not
     * loaded, matching the historical hard-coded behaviour.
     *
     * @return int
     */
    public static function decimals()
    {
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;

        /**
         * Filter the decimal precision used for Briqpay minor-unit conversion.
         *
         * @param int $decimals Decimal places.
         */
        return (int) apply_filters('briqpay_money_decimals', $decimals);
    }

    /**
     * Factor between major and minor units.
     *
     * @return int
     */
    public static function multiplier()
    {
        return (int) pow(10, max(0, self::decimals()));
    }

    /**
     * Convert a major-unit amount to Briqpay's integer minor units.
     *
     * @param float|int|string $amount Major-unit amount, e.g. 26.25.
     * @return int Minor units, e.g. 2625.
     */
    public static function to_minor($amount)
    {
        return (int) round(((float) $amount) * self::multiplier());
    }

    /**
     * Convert Briqpay integer minor units back to a major-unit amount.
     *
     * @param int|float|string $minor Minor units, e.g. 2625.
     * @return float Major-unit amount, e.g. 26.25.
     */
    public static function from_minor($minor)
    {
        $multiplier = self::multiplier();

        if (0 === $multiplier) {
            return (float) $minor;
        }

        return ((float) $minor) / $multiplier;
    }

    /**
     * Does the store's precision match what the plugin's conversions assume?
     *
     * Everything outside this class assumes two decimals. Anything else means the
     * amounts this plugin would send are wrong by a factor of ten or more, so the
     * gateway should not offer itself.
     *
     * @return bool
     */
    public static function is_supported_precision()
    {
        return 2 === self::decimals();
    }
}
