<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coupon lines for a Briqpay cart, with the tax rate each one actually carries.
 *
 * Briqpay validates every line on its own: taxRate × unitPrice × quantity has to
 * equal totalVatAmount. A coupon line used to be stamped with the rate of the
 * FIRST line in the cart, so on a cart mixing rates - 25% swabs and 6% nutrition
 * - a coupon that only touched the 6% products arrived as "25%" with a 6% VAT
 * amount, and Briqpay refused the whole session:
 *
 *   Cart item (name: Coupon: premiumkund övrigt, ...) -
 *   taxRate * unitPrice * quantity != totalVatAmount
 *
 * WooCommerce never had that problem, because it never had a single rate for a
 * coupon: it works out each coupon's share of every line and taxes that share
 * at the line's own rate. This class reproduces that, with the same tool
 * WooCommerce uses (WC_Discounts), and turns the result into one Briqpay line
 * per coupon and rate. A coupon that touches one rate gets exactly the line it
 * always got - same reference, same name. A coupon that spans several rates is
 * split, so each line's rate matches its VAT.
 *
 * Three tiers, so no path is ever worse than it was:
 *
 *  1. Replay WC_Discounts (cart or order) - exact per coupon and rate.
 *  2. If the replay disagrees with WooCommerce's recorded coupon totals (a
 *     virtual coupon from a discount plugin that cannot be re-evaluated later
 *     in the admin, say), aggregate per rate from the stored line data instead:
 *     subtotal − total per line, tax per rate id. Exact, but not per coupon.
 *  3. If even that is unavailable, one line per coupon as before, with the
 *     rate taken from the coupon's own VAT / amount ratio snapped to a rate
 *     present on the lines - which is right whenever a coupon covers one rate,
 *     i.e. every case reported so far.
 */
class Discount_Lines
{
    /**
     * Coupon lines for the current cart.
     *
     * @param \WC_Cart $cart         The cart.
     * @param callable $rate_of_item fn(array $cart_item, \WC_Product $product): int - applied rate in basis points.
     * @return array<int,array{reference:string,name:string,code:string,rate:int,ex:float,tax:float}>
     */
    public static function from_cart($cart, callable $rate_of_item)
    {
        $codes = $cart->get_applied_coupons();
        if (empty($codes)) {
            return array();
        }

        $recorded = array();
        foreach ($codes as $code) {
            $recorded[$code] = array(
                'ex' => (float) $cart->get_coupon_discount_amount($code),
                'tax' => (float) $cart->get_coupon_discount_tax_amount($code),
            );
        }

        $contents = $cart->get_cart();
        $rates = array();
        foreach ($contents as $key => $cart_item) {
            $rates[$key] = isset($cart_item['data']) && $cart_item['data'] instanceof \WC_Product
                ? (int) $rate_of_item($cart_item, $cart_item['data'])
                : 0;
        }

        // Tier 1: replay.
        try {
            $discounts = new \WC_Discounts($cart);
            foreach ($cart->get_coupons() as $coupon) {
                $discounts->apply_coupon($coupon, false);
            }
            $shares = $discounts->get_discounts(true);
            $include_tax = (bool) wc_prices_include_tax();

            $tax_of_share = function ($cents, $key) use ($contents, $rates, $cart, $include_tax) {
                $product = $contents[$key]['data'];
                if ($rates[$key] <= 0 || !$product->is_taxable() || !wc_tax_enabled()) {
                    return 0;
                }
                $tax_rates = \WC_Tax::get_rates($product->get_tax_class(), $cart->get_customer());
                return array_sum(\WC_Tax::calc_tax($cents, $tax_rates, $include_tax));
            };

            $groups = self::group_shares($shares, $rates, $tax_of_share, $include_tax);
            if (self::matches_recorded($groups, $recorded)) {
                return self::lines($groups);
            }
            Logger::log('Coupon replay did not match the recorded coupon totals - aggregating discounts per tax rate instead.');
        } catch (\Throwable $e) {
            Logger::log('Coupon replay unavailable (' . $e->getMessage() . ') - aggregating discounts per tax rate instead.');
        }

        // Tier 2: per rate, from the lines WooCommerce already settled.
        $by_rate = array();
        foreach ($contents as $key => $cart_item) {
            $ex = (float) ($cart_item['line_subtotal'] ?? 0) - (float) ($cart_item['line_total'] ?? 0);
            $tax = (float) ($cart_item['line_subtotal_tax'] ?? 0) - (float) ($cart_item['line_tax'] ?? 0);
            self::accumulate($by_rate, $rates[$key], $ex, $tax);
        }
        if (self::totals_match($by_rate, $recorded)) {
            return self::lines_per_rate($by_rate);
        }

        // Tier 3: one line per coupon, rate from its own amounts.
        $out = array();
        foreach ($recorded as $code => $amounts) {
            if ($amounts['ex'] <= 0 && $amounts['tax'] <= 0) {
                continue;
            }
            $out[] = self::line($code, self::rate_from_amounts($amounts['ex'], $amounts['tax'], $rates), $amounts['ex'], $amounts['tax'], false);
        }
        return $out;
    }

    /**
     * Coupon lines for an order - captures, refunds, the admin capture form and
     * hosted payment pages all build from the order.
     *
     * @param \WC_Order $order        The order.
     * @param callable  $rate_of_item fn(\WC_Order_Item $item): int - the line's rate in basis points.
     * @return array<int,array{reference:string,name:string,code:string,rate:int,ex:float,tax:float}>
     */
    public static function from_order($order, callable $rate_of_item)
    {
        $recorded = array();
        $coupon_items = array();
        foreach ($order->get_items('coupon') as $coupon_item) {
            // Defensive: a filter can hand back something other than a coupon item.
            try {
                $code = (string) $coupon_item->get_code();
                $recorded[$code] = array(
                    'ex' => (float) $coupon_item->get_discount(),
                    'tax' => (float) $coupon_item->get_discount_tax(),
                );
                $coupon_items[$code] = $coupon_item;
            } catch (\Throwable $e) {
                continue;
            }
        }
        if (empty($recorded)) {
            return array();
        }

        // For log lines only; never let the diagnostics be what throws.
        try {
            $order_id = (string) $order->get_id();
        } catch (\Throwable $e) {
            $order_id = '?';
        }

        $items = array();
        $rates = array();
        try {
            foreach ($order->get_items() as $id => $item) {
                $items[$id] = $item;
                $rates[$id] = (int) $rate_of_item($item);
            }
        } catch (\Throwable $e) {
            $items = array();
        }

        // Tier 1: replay from the order.
        if (!empty($items)) {
            try {
                $include_tax = (bool) $order->get_prices_include_tax();
                $discounts = new \WC_Discounts($order);
                foreach ($coupon_items as $code => $coupon_item) {
                    $coupon = self::order_coupon($coupon_item, $order, $include_tax, $recorded[$code]);
                    if ($coupon) {
                        $discounts->apply_coupon($coupon, false);
                    }
                }
                $shares = $discounts->get_discounts(true);

                // An order line's rate is known exactly, so its share's tax is too.
                $tax_of_share = function ($cents, $key) use ($rates, $include_tax) {
                    $rate = $rates[$key] ?? 0;
                    if ($rate <= 0) {
                        return 0;
                    }
                    return $include_tax
                        ? $cents - $cents / (1 + $rate / 10000)
                        : $cents * $rate / 10000;
                };

                $groups = self::group_shares($shares, $rates, $tax_of_share, $include_tax);
                if (self::matches_recorded($groups, $recorded)) {
                    return self::lines($groups);
                }
                Logger::log('Coupon replay for order ' . $order_id . ' did not match its recorded coupon totals - aggregating discounts per tax rate instead.');
            } catch (\Throwable $e) {
                Logger::log('Coupon replay for order ' . $order_id . ' unavailable (' . $e->getMessage() . ') - aggregating discounts per tax rate instead.');
            }

            // Tier 2: per rate, from the stored line totals.
            try {
                $by_rate = array();
                foreach ($items as $id => $item) {
                    $ex = (float) $item->get_subtotal() - (float) $item->get_total();
                    $tax = (float) $item->get_subtotal_tax() - (float) $item->get_total_tax();
                    self::accumulate($by_rate, $rates[$id], $ex, $tax);
                }
                if (self::totals_match($by_rate, $recorded)) {
                    return self::lines_per_rate($by_rate);
                }
            } catch (\Throwable $e) {
                // Fall through to tier 3.
            }
        }

        // Tier 3: one line per coupon, rate from its own amounts.
        $out = array();
        foreach ($recorded as $code => $amounts) {
            if ($amounts['ex'] <= 0 && $amounts['tax'] <= 0) {
                continue;
            }
            $out[] = self::line($code, self::rate_from_amounts($amounts['ex'], $amounts['tax'], $rates), $amounts['ex'], $amounts['tax'], false);
        }
        return $out;
    }

    /**
     * Split one coupon's refunded amount the way the order's lines for that
     * coupon are split, so a refund line carries the same rate as the line it
     * reverses. A coupon the order lines do not know falls back to its own
     * amounts.
     *
     * @param array  $order_lines Lines from from_order().
     * @param string $code        Coupon code on the refund.
     * @param float  $ex          Refunded discount, ex VAT (positive).
     * @param float  $tax         Refunded discount VAT (positive).
     * @return array<int,array{reference:string,name:string,code:string,rate:int,ex:float,tax:float}>
     */
    public static function split_refund(array $order_lines, $code, $ex, $tax)
    {
        $own = array_values(array_filter($order_lines, function ($l) use ($code) {
            return $l['code'] === $code;
        }));

        if (empty($own)) {
            $candidate_rates = array_map(function ($l) {
                return $l['rate'];
            }, $order_lines);
            return array(self::line($code, self::rate_from_amounts($ex, $tax, $candidate_rates), $ex, $tax, false));
        }

        if (1 === count($own)) {
            return array(self::line($code, $own[0]['rate'], $ex, $tax, false));
        }

        // Proportional to the order lines' ex amounts, remainder on the last.
        $total_ex = array_sum(array_column($own, 'ex'));
        $out = array();
        $ex_left = $ex;
        $tax_left = $tax;
        foreach ($own as $i => $l) {
            $last = ($i === count($own) - 1);
            $share = ($total_ex > 0) ? $l['ex'] / $total_ex : 1 / count($own);
            $line_ex = $last ? $ex_left : round($ex * $share, 2);
            $line_tax = $last ? $tax_left : round($tax * $share, 2);
            $ex_left -= $line_ex;
            $tax_left -= $line_tax;
            $out[] = self::line($code, $l['rate'], $line_ex, $line_tax, true);
        }
        return $out;
    }

    /**
     * The nearest of the rates in use to the ratio the amounts imply - so a
     * single-rate coupon reads 2500, never the 2501 that dividing the rounded
     * amounts can produce.
     *
     * @param float $ex    Ex-VAT amount.
     * @param float $tax   VAT amount.
     * @param int[] $rates Rates in use on the lines, basis points.
     * @return int
     */
    public static function rate_from_amounts($ex, $tax, array $rates)
    {
        if ($ex <= 0) {
            return 0;
        }
        $implied = (int) round(($tax / $ex) * 10000);
        $candidates = array_values(array_unique(array_map('intval', $rates)));
        if (empty($candidates)) {
            return $implied;
        }
        usort($candidates, function ($a, $b) use ($implied) {
            return abs($a - $implied) - abs($b - $implied);
        });
        // Snap only when the nearest rate is genuinely close; otherwise trust the arithmetic.
        return abs($candidates[0] - $implied) <= 50 ? $candidates[0] : $implied;
    }

    /**
     * "25%" / "25.5%" for a basis-point rate.
     */
    public static function rate_label($rate)
    {
        $percent = $rate / 100;
        return (floor($percent) == $percent ? (string) (int) $percent : rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')) . '%';
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * The coupon to replay for an order's coupon item, built the way
     * WC_Order::recalculate_coupons() builds it: by ID when the coupon still
     * exists, otherwise from the snapshot WooCommerce stored on the item at
     * checkout. That is what makes a virtual coupon replayable in the admin -
     * Discount Rules for WooCommerce applies its cart rules as coupons that only
     * ever existed in the cart, and Session_Manager split them per rate from the
     * live cart. Capture must reproduce those exact lines (Briqpay matches
     * capture lines to the session by reference, name and unit price), so the
     * order side has to reach the same answer. The same filter WooCommerce
     * applies lets such plugins restore their coupon's product scope.
     *
     * @param \WC_Order_Item_Coupon $coupon_item
     * @param \WC_Order             $order
     * @param bool                  $include_tax Order prices include tax.
     * @param array{ex:float,tax:float} $recorded The coupon's recorded totals.
     * @return \WC_Coupon|null
     */
    private static function order_coupon($coupon_item, $order, $include_tax, array $recorded)
    {
        $code = (string) $coupon_item->get_code();
        $coupon_id = function_exists('wc_get_coupon_id_by_code') ? (int) wc_get_coupon_id_by_code($code) : 0;

        if ($coupon_id) {
            $coupon = new \WC_Coupon($coupon_id);
        } else {
            $coupon = new \WC_Coupon();
            $info = $coupon_item->get_meta('coupon_info', true);
            if ($info && is_callable(array($coupon, 'set_short_info'))) {
                $coupon->set_short_info($info); // WC 8.7+
            } else {
                $data = $coupon_item->get_meta('coupon_data', true);
                if (!empty($data) && is_array($data)) {
                    $coupon->read_manual_coupon($code, $data);
                }
            }
            $coupon->set_code($code);
            if (is_callable(array($coupon, 'set_virtual'))) {
                $coupon->set_virtual(true);
            }
            // A dynamic coupon stores no amount; apply what it took off.
            if (!$coupon->get_amount()) {
                $coupon->set_amount($include_tax ? $recorded['ex'] + $recorded['tax'] : $recorded['ex']);
                $coupon->set_discount_type('fixed_cart');
            }
        }

        $coupon = apply_filters('woocommerce_order_recalculate_coupons_coupon_object', $coupon, $code, $coupon_item, $order);

        return $coupon ? $coupon : null;
    }

    /**
     * @param array    $shares       [code][item_key] => precision cents, from WC_Discounts.
     * @param int[]    $rates        item_key => rate.
     * @param callable $tax_of_share fn(float $cents, $key): float precision-cents tax.
     * @param bool     $include_tax  Whether the shares are inc VAT.
     * @return array [code][rate] => ['ex' => float, 'tax' => float]
     */
    private static function group_shares(array $shares, array $rates, callable $tax_of_share, $include_tax)
    {
        $groups = array();
        foreach ($shares as $code => $per_item) {
            foreach ((array) $per_item as $key => $cents) {
                if (!isset($rates[$key]) || !$cents) {
                    continue;
                }
                $tax_cents = $tax_of_share((float) $cents, $key);
                $ex_cents = $include_tax ? $cents - $tax_cents : $cents;
                $groups[$code][$rates[$key]]['ex'] = ($groups[$code][$rates[$key]]['ex'] ?? 0) + wc_remove_number_precision($ex_cents);
                $groups[$code][$rates[$key]]['tax'] = ($groups[$code][$rates[$key]]['tax'] ?? 0) + wc_remove_number_precision($tax_cents);
            }
        }
        return $groups;
    }

    private static function matches_recorded(array $groups, array $recorded)
    {
        foreach ($recorded as $code => $amounts) {
            $ex = 0;
            $tax = 0;
            foreach ($groups[$code] ?? array() as $g) {
                $ex += $g['ex'];
                $tax += $g['tax'];
            }
            if (abs($ex - $amounts['ex']) > 0.011 || abs($tax - $amounts['tax']) > 0.011) {
                return false;
            }
        }
        return true;
    }

    private static function totals_match(array $by_rate, array $recorded)
    {
        $ex = array_sum(array_column($by_rate, 'ex'));
        $tax = array_sum(array_column($by_rate, 'tax'));
        $rec_ex = array_sum(array_column($recorded, 'ex'));
        $rec_tax = array_sum(array_column($recorded, 'tax'));
        return abs($ex - $rec_ex) <= 0.011 && abs($tax - $rec_tax) <= 0.011 && ($ex > 0 || $tax > 0);
    }

    private static function accumulate(array &$by_rate, $rate, $ex, $tax)
    {
        if (abs($ex) < 0.005 && abs($tax) < 0.005) {
            return;
        }
        $by_rate[$rate]['ex'] = ($by_rate[$rate]['ex'] ?? 0) + $ex;
        $by_rate[$rate]['tax'] = ($by_rate[$rate]['tax'] ?? 0) + $tax;
    }

    private static function lines(array $groups)
    {
        $out = array();
        foreach ($groups as $code => $by_rate) {
            $by_rate = array_filter($by_rate, function ($g) {
                return abs($g['ex']) >= 0.005 || abs($g['tax']) >= 0.005;
            });
            ksort($by_rate);
            $split = count($by_rate) > 1;
            foreach ($by_rate as $rate => $g) {
                $out[] = self::line($code, (int) $rate, $g['ex'], $g['tax'], $split);
            }
        }
        return $out;
    }

    private static function lines_per_rate(array $by_rate)
    {
        ksort($by_rate);
        $out = array();
        foreach ($by_rate as $rate => $g) {
            $out[] = array(
                'reference' => 'discount_' . (int) $rate,
                // translators: %s: VAT rate, e.g. 25%
                'name' => sprintf(__('Discount (%s)', 'briqpay-for-woocommerce'), self::rate_label((int) $rate)),
                'code' => '',
                'rate' => (int) $rate,
                'ex' => $g['ex'],
                'tax' => $g['tax'],
            );
        }
        return $out;
    }

    private static function line($code, $rate, $ex, $tax, $split)
    {
        return array(
            // Unchanged for the single-rate coupon every merchant has today.
            'reference' => 'discount_' . $code . ($split ? '_' . (int) $rate : ''),
            'name' => $split
                // translators: 1: coupon code, 2: VAT rate, e.g. 25%
                ? sprintf(__('Coupon: %1$s (%2$s)', 'briqpay-for-woocommerce'), $code, self::rate_label((int) $rate))
                // translators: %s: coupon code
                : sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
            'code' => (string) $code,
            'rate' => (int) $rate,
            'ex' => (float) $ex,
            'tax' => (float) $tax,
        );
    }
}
