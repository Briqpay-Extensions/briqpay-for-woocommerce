<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Order Management
 */
class Order_Management
{


    /**
     * Init
     */
    public function init()
    {
        add_action('woocommerce_order_status_completed', array($this, 'capture_order'));
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_order'));
        add_filter('briqpay_process_refund', array($this, 'refund_order'), 10, 4);
        add_action('briqpay_retry_capture', array($this, 'retry_capture'));
    }

    /**
     * Capture Order (triggered by status change to completed)
     */
    public function capture_order($order_id)
    {
        if (!$this->is_enabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'briqpay') {
            return;
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        if (!$session_id) {
            return;
        }

        // Calculate missing items for full capture
        $remaining_items = $this->get_remaining_items_to_capture($order);
        if (empty($remaining_items)) {
            return;
        }

        $result = $this->execute_capture($order, $session_id, $remaining_items);

        if (is_wp_error($result)) {
            Logger::log(sprintf('Capture failed for order %s: %s', $order_id, $result->get_error_message()));
            /* translators: %s: error message */
            $order->update_status('on-hold', sprintf(__('Briqpay capture failed: %s. Merchant action required.', 'briqpay-for-woocommerce'), $result->get_error_message()));
            do_action('briqpay_capture_failed', $order, $result);

            // Schedule automatic retry via Action Scheduler (up to 3 retries)
            if (function_exists('as_schedule_single_action')) {
                $retry_count = (int) $order->get_meta('_briqpay_capture_retry_count');
                if ($retry_count < 3) {
                    $order->update_meta_data('_briqpay_capture_retry_count', $retry_count + 1);
                    $order->save();
                    as_schedule_single_action(time() + 300, 'briqpay_retry_capture', array($order_id), 'briqpay');
                    Logger::log(sprintf('Scheduled capture retry %d/3 for order %s in 5 minutes.', $retry_count + 1, $order_id));
                } else {
                    Logger::log(sprintf('Capture for order %s has exhausted all 3 retries. Manual intervention required.', $order_id));
                }
            }
        }
    }

    /**
     * Retry a failed capture (Action Scheduler callback)
     *
     * Only retries if the order is still on-hold (not manually resolved).
     * On success, moves the order back to completed.
     */
    public function retry_capture($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'briqpay') {
            return;
        }

        // Only retry if the order is still on-hold (merchant hasn't manually resolved it)
        if (!$order->has_status('on-hold')) {
            Logger::log(sprintf('Capture retry skipped for order %s — status is %s, not on-hold.', $order_id, $order->get_status()));
            return;
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        if (!$session_id) {
            return;
        }

        $remaining_items = $this->get_remaining_items_to_capture($order);
        if (empty($remaining_items)) {
            return;
        }

        $retry_count = (int) $order->get_meta('_briqpay_capture_retry_count');
        Logger::log(sprintf('Executing capture retry %d/3 for order %s.', $retry_count, $order_id));

        $result = $this->execute_capture($order, $session_id, $remaining_items);

        if (is_wp_error($result)) {
            /* translators: %1$d: retry number, %2$s: error message */
            $order->add_order_note(sprintf(__('Briqpay: Capture retry %1$d/3 failed: %2$s', 'briqpay-for-woocommerce'), $retry_count, $result->get_error_message()));

            if ($retry_count < 3 && function_exists('as_schedule_single_action')) {
                $order->update_meta_data('_briqpay_capture_retry_count', $retry_count + 1);
                $order->save();
                as_schedule_single_action(time() + 300, 'briqpay_retry_capture', array($order_id), 'briqpay');
                Logger::log(sprintf('Scheduled capture retry %d/3 for order %s.', $retry_count + 1, $order_id));
            } else {
                Logger::log(sprintf('Capture for order %s failed after 3 retries. Manual intervention required.', $order_id));
            }
        } else {
            // Capture succeeded on retry — restore completed status
            $order->update_status('completed', __('Briqpay: Capture succeeded on retry.', 'briqpay-for-woocommerce'));
            $order->delete_meta_data('_briqpay_capture_retry_count');
            $order->save();
            Logger::log(sprintf('Capture retry succeeded for order %s. Status restored to completed.', $order_id));
        }
    }

    /**
     * AJAX/Manual Partial Capture
     */
    public function manual_capture($order, $items_to_capture)
    {
        if (!$this->is_enabled()) {
            return new \WP_Error('disabled', __('Order management is disabled.', 'briqpay-for-woocommerce'));
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        if (!$session_id) {
            return new \WP_Error('no_session', __('No Briqpay session found.', 'briqpay-for-woocommerce'));
        }

        // Map the manual input to the detailed format needed for capture
        $detailed_items = array();
        $is_us = 'US' === $order->get_billing_country();
        $total_tax_to_capture = 0;

        $consolidated_detailed = array();
        foreach ($items_to_capture as $item) {
            $qty = (int) $item['quantity'];
            $unit_price_inc = (int) $item['unitPriceIncVat'];
            $tax_rate = (int) $item['taxRate'];

            // Precision fix: derive unit price ex VAT from unit price inc VAT
            $unit_price_ex = (int) round($unit_price_inc / (1 + ($tax_rate / 10000)));

            if ($is_us) {
                $item_tax = ($unit_price_inc - $unit_price_ex) * $qty;
                $total_tax_to_capture += ($item_tax / 100);
                $unit_price_inc = $unit_price_ex;
                $tax_rate = 0;
            }

            $total_amount = $unit_price_inc * $qty;
            $total_vat = $is_us ? 0 : ($total_amount - ($unit_price_ex * $qty));

            $ref = sanitize_text_field($item['reference']);
            if (isset($consolidated_detailed[$ref])) {
                $consolidated_detailed[$ref]['quantity'] += $qty;
                $consolidated_detailed[$ref]['totalAmount'] += $total_amount;
                $consolidated_detailed[$ref]['totalVatAmount'] += (int) $total_vat;
            } else {
                $consolidated_detailed[$ref] = array(
                    'productType' => $item['productType'] ?? 'physical',
                    'reference' => $ref,
                    'name' => sanitize_text_field($item['name']),
                    'quantity' => $qty,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $unit_price_ex,
                    'unitPriceIncVat' => $unit_price_inc,
                    'taxRate' => $tax_rate,
                    'totalAmount' => $total_amount,
                    'totalVatAmount' => (int) $total_vat,
                );
            }
        }
        $detailed_items = array_values($consolidated_detailed);

        // Add USA Sales Tax Item
        if ($is_us && $total_tax_to_capture > 0) {
            $detailed_items[] = array(
                'productType' => 'sales_tax',
                'name' => __('Sales tax', 'briqpay-for-woocommerce'),
                'reference' => 'sales_tax',
                'quantity' => (int) round($total_tax_to_capture * 100), // Internal tracking
                'totalTaxAmount' => (int) round($total_tax_to_capture * 100),
            );
        }

        return $this->execute_capture($order, $session_id, $detailed_items);
    }

    /**
     * Execute Capture Request
     */
    private function execute_capture($order, $session_id, $items)
    {
        // 1. Sync with session first to get canonical data and ensure history is up to date
        $session = $this->sync_with_briqpay_session($order);
        if (is_wp_error($session)) {
            return $session;
        }

        $api = $this->get_api();
        $billing_country = strtoupper(trim($order->get_billing_country()));
        $is_us = 'US' === $billing_country;

        $amount_inc_vat = 0;
        $amount_ex_vat = 0;
        $cart_for_api = array();

        // Consolidate capture items by reference
        $consolidated_items = array();
        foreach ($items as $item) {
            $ref = $item['reference'];
            if (isset($consolidated_items[$ref])) {
                $consolidated_items[$ref]['quantity'] += $item['quantity'];
                if (isset($item['totalAmount'])) {
                    $consolidated_items[$ref]['totalAmount'] += $item['totalAmount'];
                }
                if (isset($item['totalVatAmount'])) {
                    $consolidated_items[$ref]['totalVatAmount'] += $item['totalVatAmount'];
                }
            } else {
                $consolidated_items[$ref] = $item;
            }
        }

        foreach ($consolidated_items as $item) {
            $api_item = $item;
            $is_sales_tax = ('sales_tax' === ($item['productType'] ?? ''));

            // Try to find canonical item in session to fix rounding
            if (!$is_sales_tax) {
                $canonical = $this->get_canonical_session_item($session, $item['reference']);
                if ($canonical) {
                    $qty = $item['quantity'] ?? 1;
                    $api_item['unitPrice'] = $canonical['unitPrice'];
                    $api_item['unitPriceIncVat'] = $canonical['unitPriceIncVat'];
                    $api_item['taxRate'] = $canonical['taxRate'];
                    $already_captured = $this->get_already_captured_from_session($session, $item['reference']);
                    $this->apply_canonical_totals($api_item, $canonical, $qty, $already_captured);
                }
            }

            if ($is_us && !$is_sales_tax) {
                // Safety net: Force zero tax for US items
                $api_item['taxRate'] = 0;
                $api_item['totalVatAmount'] = 0;
                $api_item['unitPriceIncVat'] = $api_item['unitPrice'];
                $api_item['totalAmount'] = $api_item['unitPrice'] * ($api_item['quantity'] ?? 1);
            }

            if ($is_sales_tax) {
                $amount_inc_vat += $item['totalTaxAmount'];
                unset($api_item['quantity']);
            } else {
                $amount_inc_vat += $api_item['totalAmount'];
                $amount_ex_vat += ($api_item['totalAmount'] - $api_item['totalVatAmount']);
            }
            $cart_for_api[] = $api_item;
        }

        $payload = array(
            'data' => array(
                'order' => array(
                    'amountIncVat' => $amount_inc_vat,
                    'amountExVat' => $amount_ex_vat,
                    'currency' => $order->get_currency(),
                    'cart' => $cart_for_api
                )
            )
        );

        $response = $api->capture_order($session_id, $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!empty($response['captureId'])) {
            $capture_id = $response['captureId'];
            // translators: %s: capture ID
            $order->add_order_note(sprintf(__('Briqpay: Captured. ID: %s', 'briqpay-for-woocommerce'), $capture_id));

            // Track history using the actual amounts sent to Briqpay (cart_for_api),
            // not the pre-canonical-correction $items - refund balance calculations
            // rely on this being the authoritative captured amount per reference.
            $history = $order->get_meta('_briqpay_capture_history') ?: array();
            $history[] = array(
                'captureId' => $capture_id,
                'date' => current_time('mysql'),
                'items' => $cart_for_api,
                'amount' => $amount_inc_vat
            );
            $order->update_meta_data('_briqpay_capture_history', $history);

            // Legacy field for backward compatibility
            $captures = $order->get_meta('_briqpay_captures') ?: array();
            $captures[] = $capture_id;
            $order->update_meta_data('_briqpay_captures', $captures);

            $order->save();

            // If the order is currently pending/awaiting payment, mark it as paid since we successfully captured funds
            if ($order->has_status(array('pending', 'failed', 'on-hold', 'checkout-draft'))) {
                $order_amount = (float) $order->get_total();
                if (abs(($amount_inc_vat / 100) - $order_amount) > 0.05) {
                    Logger::log(sprintf('Capture amount mismatch on manual capture. Captured: %s, Order Total: %s. Not marking order payment complete.', $amount_inc_vat / 100, $order_amount));
                    $order->add_order_note(sprintf(__('Briqpay: Capture amount mismatch! Captured: %s, Order Total: %s. Order not marked as payment complete.', 'briqpay-for-woocommerce'), wc_price($amount_inc_vat / 100), wc_price($order_amount)));
                } else {
                    Logger::log('Order is in unpaid status. Calling payment_complete() via manual capture.');
                    $order->payment_complete($capture_id);
                }
            }

            return $capture_id;
        }

        return new \WP_Error('api_error', __('Capture failed: No captureId returned.', 'briqpay-for-woocommerce'));
    }

    /**
     * Refund Order
     */
    public function refund_order($status, $order_id, $amount, $reason)
    {
        Logger::log(sprintf('refund_order() called for order %s. Amount: %s', $order_id, $amount));
        if (!$this->is_enabled()) {
            return false;
        }

        $order = wc_get_order($order_id);
        $session_id = $order->get_meta('_briqpay_session_id');
        $capture_history = $order->get_meta('_briqpay_capture_history') ?: array();
        $refund_history = $order->get_meta('_briqpay_refund_history') ?: array();

        if (!$session_id || empty($capture_history)) {
            Logger::log('Refund aborted: No session or no capture history.');
            return false;
        }

        // Get items from the latest refund object
        $refund_items_raw = $this->get_refund_items($order);
        Logger::log(sprintf('Detected %d raw refund items.', count($refund_items_raw)));

        // Consolidate items by reference to prevent duplicate lines in the same refund request
        $consolidated_refund_items = array();
        foreach ($refund_items_raw as $ri) {
            $ref = $ri['reference'];
            if (isset($consolidated_refund_items[$ref])) {
                $consolidated_refund_items[$ref]['quantity'] += $ri['quantity'];
                if (isset($ri['totalAmount'])) {
                    $consolidated_refund_items[$ref]['totalAmount'] += $ri['totalAmount'];
                }
                if (isset($ri['totalVatAmount'])) {
                    $consolidated_refund_items[$ref]['totalVatAmount'] += $ri['totalVatAmount'];
                }
            } else {
                $consolidated_refund_items[$ref] = $ri;
            }
        }
        $refund_items = array_values($consolidated_refund_items);

        // If no specific items (full amount refund without item selection), 
        // we map it to the latest capture by default for simplicity, 
        // but it's better to treat it as a partial refund of the latest capture.
        if (empty($refund_items)) {
            Logger::log('Generic amount refund. Mapping to latest capture.');
            $target_capture = end($capture_history);
            return $this->execute_single_refund($order, $session_id, $target_capture['captureId'], $this->get_order_cart($order), $amount);
        }

        // Calculate unrefunded quantities per capture
        $net_captures = $this->get_unrefunded_balances($capture_history, $refund_history);

        // Map refund items to captures
        $refunds_to_execute = array();
        // Tracks, per reference, how much the exact remaining-balance correction
        // below differs from the naive unitPrice * qty amount WooCommerce's own
        // refund record was created with - used to reconcile WC's displayed
        // "Refunded"/"Net Payment" totals with what was actually sent to Briqpay.
        $wc_amount_diffs = array();
        foreach ($refund_items as $ri) {
            $ref = $ri['reference'];
            $qty_to_refund = $ri['quantity'];

            foreach ($net_captures as &$nc) {
                if ($qty_to_refund <= 0)
                    break;

                $available_qty = $nc['balances'][$ref] ?? 0;
                if ($available_qty <= 0)
                    continue;

                $taken = min($qty_to_refund, $available_qty);

                // Construct the refund item detail for this part
                $part_item = $ri;
                $part_item['quantity'] = $taken;

                if ('sales_tax' === ($ri['productType'] ?? '')) {
                    $part_item['totalTaxAmount'] = $taken;
                } elseif ($taken === $available_qty && isset($nc['amount_balances'][$ref])) {
                    // This exhausts everything still captured for this reference on this
                    // capture. Use the exact remaining captured amount rather than a
                    // proportional unitPrice * qty estimate, so the full captured total
                    // always ends up refunded instead of leaving a residual ore stuck.
                    $naive_amount = (int) round($ri['unitPriceIncVat'] * $taken);
                    $true_amount = (int) $nc['amount_balances'][$ref];
                    $true_vat = (int) ($nc['vat_balances'][$ref] ?? round(($ri['unitPriceIncVat'] - $ri['unitPrice']) * $taken));

                    $exact = $this->resolve_exact_refund_totals($true_amount, $true_vat, $taken);
                    $part_item['unitPrice'] = $exact['unitPrice'];
                    $part_item['unitPriceIncVat'] = $exact['unitPriceIncVat'];
                    $part_item['totalAmount'] = $exact['totalAmount'];
                    $part_item['totalVatAmount'] = $exact['totalVatAmount'];
                    $part_item['_exact'] = true;

                    $diff = $part_item['totalAmount'] - $naive_amount;
                    if (0 !== $diff) {
                        $wc_amount_diffs[$ref] = ($wc_amount_diffs[$ref] ?? 0) + $diff;
                    }
                } else {
                    $part_item['totalAmount'] = (int) round($ri['unitPriceIncVat'] * $taken);
                    $part_item['totalVatAmount'] = (int) round(($ri['unitPriceIncVat'] - $ri['unitPrice']) * $taken);
                }

                $refunds_to_execute[$nc['captureId']][] = $part_item;

                $nc['balances'][$ref] -= $taken;
                if (isset($nc['amount_balances'][$ref])) {
                    $nc['amount_balances'][$ref] -= $part_item['totalAmount'];
                }
                if (isset($nc['vat_balances'][$ref])) {
                    $nc['vat_balances'][$ref] -= $part_item['totalVatAmount'];
                }
                $qty_to_refund -= $taken;
            }

            if ($qty_to_refund > 0) {
                Logger::log(sprintf('Warning: Could not find enough captured quantity for item %s (%d left).', $ref, $qty_to_refund));
                // We'll proceed with what we found, or fallback to the latest capture for the remainder.
                // Recompute totalAmount/totalVatAmount for the reduced quantity - $ri's
                // totals are for the ORIGINAL full quantity, so reusing them unchanged
                // alongside the smaller leftover quantity would again produce
                // unitPriceIncVat * quantity != totalAmount and get rejected by Briqpay.
                $last_cap_id = end($capture_history)['captureId'];
                $leftover_item = $ri;
                $leftover_item['quantity'] = $qty_to_refund;
                if ('sales_tax' === ($ri['productType'] ?? '')) {
                    $leftover_item['totalTaxAmount'] = $qty_to_refund;
                } else {
                    $leftover_item['totalAmount'] = (int) round($ri['unitPriceIncVat'] * $qty_to_refund);
                    $leftover_item['totalVatAmount'] = (int) round(($ri['unitPriceIncVat'] - $ri['unitPrice']) * $qty_to_refund);
                }
                $refunds_to_execute[$last_cap_id][] = $leftover_item;
            }
        }

        // Enforce single-capture refund requirement
        if (count($refunds_to_execute) > 1) {
            Logger::log('Multi-capture refund rejected: refund spans multiple captures.');
            return new \WP_Error('multi_capture_refund', __('This refund spans multiple captures. Please perform separate refunds per capture.', 'briqpay-for-woocommerce'));
        }

        // Execute API calls
        foreach ($refunds_to_execute as $capture_id => $items) {
            $res = $this->execute_single_refund($order, $session_id, $capture_id, $items, null, $amount);
            if (is_wp_error($res)) {
                return $res;
            }
        }

        // If the exact-remaining-balance correction above changed what was actually
        // refunded from what WooCommerce's own refund record was created with (e.g.
        // WooCommerce's per-unit split entered 236.71 but Briqpay was refunded
        // 236.72 to exactly zero out the captured balance), reconcile WooCommerce's
        // refund record so its "Refunded"/"Net Payment" totals match reality instead
        // of showing a residual amount that was, in fact, already refunded.
        if (!empty($wc_amount_diffs)) {
            $this->reconcile_wc_refund_amounts($order, $wc_amount_diffs);
        }

        return true;
    }

    /**
     * Correct WooCommerce's own refund record to match what was actually refunded
     * with Briqpay, when the exact-remaining-balance correction changed a line's
     * amount away from WooCommerce's own (WC-UI-rounded) figure.
     *
     * Without this, WooCommerce's own bookkeeping (and its "Refunded"/"Net Payment"
     * order totals) can permanently disagree with Briqpay even though the full
     * captured amount was, in fact, refunded.
     *
     * @param \WC_Order $order
     * @param array     $diffs_by_reference Reference => diff in ore (actual - WC's original amount).
     */
    private function reconcile_wc_refund_amounts($order, $diffs_by_reference)
    {
        $refunds = $order->get_refunds();
        if (empty($refunds)) {
            return;
        }
        $latest_refund = reset($refunds);
        $total_diff_cents = 0;

        foreach ($latest_refund->get_items() as $item) {
            $product = $item->get_product();
            $ref = $item->get_meta('_briqpay_item_reference');
            if (empty($ref) && $product) {
                $sku = $product->get_sku();
                $id = $product->get_id();
                $ref = !empty($sku) ? $sku : (string) $id;
            }

            if (!isset($diffs_by_reference[$ref])) {
                continue;
            }

            $diff = $diffs_by_reference[$ref] / 100;
            // Refund line totals are stored as negative values in WooCommerce.
            $item->set_total((float) $item->get_total() - $diff);
            $item->save();
            $total_diff_cents += $diffs_by_reference[$ref];
        }

        if (0 !== $total_diff_cents) {
            $latest_refund->set_total((float) $latest_refund->get_total() - ($total_diff_cents / 100));
            $latest_refund->save();
            Logger::log(sprintf('Reconciled WooCommerce refund total by %.2f to match the exact amount refunded with Briqpay.', -$total_diff_cents / 100));
        }
    }

    /**
     * Calculate available unrefunded quantities (and amounts) per capture
     */
    private function get_unrefunded_balances($capture_history, $refund_history)
    {
        $balances = array();
        foreach ($capture_history as $c) {
            $cap_items = array();
            $cap_amounts = array();
            $cap_vat = array();
            foreach ($c['items'] as $it) {
                $ref = $it['reference'];
                $cap_items[$ref] = ($cap_items[$ref] ?? 0) + $it['quantity'];
                $cap_amounts[$ref] = ($cap_amounts[$ref] ?? 0) + (int) ($it['totalAmount'] ?? 0);
                $cap_vat[$ref] = ($cap_vat[$ref] ?? 0) + (int) ($it['totalVatAmount'] ?? 0);
            }
            $balances[] = array(
                'captureId' => $c['captureId'],
                'balances' => $cap_items,
                // Remaining captured amount per reference. This is the source of truth
                // for what a fully-exhausting refund of that reference must total -
                // using it (instead of a proportional unitPrice * qty estimate) is what
                // guarantees the full captured amount always ends up refunded, with no
                // residual ore left stuck due to per-unit rounding.
                'amount_balances' => $cap_amounts,
                'vat_balances' => $cap_vat,
            );
        }

        // Subtract previous refunds
        foreach ($refund_history as $r) {
            foreach ($balances as &$b) {
                if ($b['captureId'] === $r['captureId']) {
                    foreach ($r['items'] as $rit) {
                        $ref = $rit['reference'];
                        if (isset($b['balances'][$ref])) {
                            $b['balances'][$ref] -= $rit['quantity'];
                        }
                        if (isset($b['amount_balances'][$ref])) {
                            $b['amount_balances'][$ref] -= (int) ($rit['totalAmount'] ?? 0);
                        }
                        if (isset($b['vat_balances'][$ref])) {
                            $b['vat_balances'][$ref] -= (int) ($rit['totalVatAmount'] ?? 0);
                        }
                    }
                }
            }
        }

        Logger::log('Calculated unrefunded balances: ' . print_r($balances, true));
        return $balances;
    }

    /**
     * Execute a single refund request to Briqpay
     */
    private function execute_single_refund($order, $session_id, $capture_id, $items, $override_amount = null, $target_amount = null)
    {
        // 1. Sync with session first
        $session = $this->sync_with_briqpay_session($order);
        if (is_wp_error($session)) {
            return false;
        }

        $api = $this->get_api();
        $billing_country = strtoupper(trim($order->get_billing_country()));
        $is_us = 'US' === $billing_country;

        $amount_inc_vat = 0;
        $amount_ex_vat = 0;
        $cart_for_api = array();

        if ($override_amount !== null) {
            $amount_inc_vat = (int) round($override_amount * 100);
            // Rough ex-vat estimate if only amount provided
            $amount_ex_vat = $is_us ? $amount_inc_vat : (int) round($amount_inc_vat / 1.25);

            // Re-map items to a single generic refund item to ensure it matches the override amount
            $items = array(
                array(
                    'productType' => 'physical',
                    'reference' => 'refund',
                    'name' => __('Refund', 'briqpay-for-woocommerce'),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $amount_ex_vat,
                    'unitPriceIncVat' => $amount_inc_vat,
                    'taxRate' => (!$is_us && $amount_ex_vat > 0) ? (int) round((($amount_inc_vat - $amount_ex_vat) / $amount_ex_vat) * 10000) : 0,
                    'totalAmount' => $amount_inc_vat,
                    'totalVatAmount' => $is_us ? 0 : ($amount_inc_vat - $amount_ex_vat),
                )
            );
        }

        // Consolidate items by reference before processing
        $consolidated_items = array();
        foreach ($items as $item) {
            $ref = $item['reference'];
            if (isset($consolidated_items[$ref])) {
                $consolidated_items[$ref]['quantity'] += $item['quantity'];
                if (isset($item['totalAmount'])) {
                    $consolidated_items[$ref]['totalAmount'] += $item['totalAmount'];
                }
                if (isset($item['totalVatAmount'])) {
                    $consolidated_items[$ref]['totalVatAmount'] += $item['totalVatAmount'];
                }
                // Only stays exact if every consolidated part was independently exact.
                $consolidated_items[$ref]['_exact'] = !empty($consolidated_items[$ref]['_exact']) && !empty($item['_exact']);
            } else {
                $consolidated_items[$ref] = $item;
            }
        }
        $items = array_values($consolidated_items);

        foreach ($items as $item) {
            $api_item = $item;
            $is_sales_tax = ('sales_tax' === ($item['productType'] ?? ''));
            $is_exact = !empty($item['_exact']);

            // Look up the canonical (authorized) item for this reference. taxRate is
            // ALWAYS synced from it below, regardless of $is_exact: Briqpay validates
            // a refund's taxRate for a reference against what it has on record from
            // authorization and rejects the request with CART_ITEM_NOT_FOUND /
            // "mismatching taxRate" if it drifts even slightly - which it will
            // whenever WooCommerce's own tax-class lookup (a nominal rate, e.g.
            // 25.00%) differs from the amount-derived rate actually sent to Briqpay
            // at authorization (e.g. 24.99%, from rounding ship_tax/ship_total for a
            // total that doesn't divide evenly - see Session_Manager::get_cart_items()).
            //
            // unitPrice/unitPriceIncVat/totalAmount/totalVatAmount are handled
            // separately and are NOT re-derived from canonical for items already
            // marked exact - their totals are the precise remaining captured balance
            // for that reference (see resolve_exact_refund_totals()), which is more
            // trustworthy than re-deriving it from the order-level canonical unit
            // price, and are already consistent with the canonical taxRate since the
            // capture that produced that balance synced it the same way.
            $canonical = (!$is_sales_tax && $item['reference'] !== 'refund')
                ? $this->get_canonical_session_item($session, $item['reference'])
                : null;

            if ($canonical) {
                $api_item['taxRate'] = $canonical['taxRate'];

                if (!$is_exact) {
                    $qty = $item['quantity'] ?? 1;
                    $api_item['unitPrice'] = $canonical['unitPrice'];
                    $api_item['unitPriceIncVat'] = $canonical['unitPriceIncVat'];
                    $this->apply_canonical_totals($api_item, $canonical, $qty);
                }
            }

            if ($is_us && !$is_sales_tax) {
                // Safety net: Force zero tax for US items
                $api_item['taxRate'] = 0;
                $api_item['totalVatAmount'] = 0;
                $api_item['unitPriceIncVat'] = $api_item['unitPrice'];
                $api_item['totalAmount'] = $api_item['unitPrice'] * ($api_item['quantity'] ?? 1);
            }

            if ($is_sales_tax) {
                $amount_inc_vat += $item['totalTaxAmount'];
                unset($api_item['quantity']);
            } else {
                $amount_inc_vat += ($api_item['totalAmount'] ?? 0);
                $amount_ex_vat += (($api_item['totalAmount'] ?? 0) - ($api_item['totalVatAmount'] ?? 0));
            }
            $cart_for_api[] = $api_item;
        }

        // Apply rounding correction if we have a target refund total
        if ($target_amount !== null && $override_amount === null) {
            $target_amount_cents = (int) round($target_amount * 100);

            $current_sum = 0;
            foreach ($cart_for_api as $item) {
                if ('sales_tax' === ($item['productType'] ?? '')) {
                    $current_sum += ($item['totalTaxAmount'] ?? 0);
                } else {
                    $current_sum += ($item['totalAmount'] ?? 0);
                }
            }

            $diff = $target_amount_cents - $current_sum;
            if ($diff !== 0) {
                Logger::log(sprintf('Refund rounding diff detected: %d cents. Target: %d, Current: %d', $diff, $target_amount_cents, $current_sum));
                // Adjust the last physical item or shipping fee. Never adjust an item
                // already marked exact - its totalAmount is the precise remaining
                // captured balance, and WooCommerce's own target amount (which is what
                // caused this diff in the first place) is not authoritative over it.
                $adjusted = false;
                for ($i = count($cart_for_api) - 1; $i >= 0; $i--) {
                    if ('sales_tax' === ($cart_for_api[$i]['productType'] ?? '')) {
                        continue;
                    }
                    if (!empty($cart_for_api[$i]['_exact'])) {
                        continue;
                    }
                    $cart_for_api[$i]['totalAmount'] += $diff;
                    if (isset($cart_for_api[$i]['totalVatAmount'])) {
                        $cart_for_api[$i]['totalVatAmount'] += $diff;
                    }
                    Logger::log(sprintf('Adjusted refund item %s totalAmount by %d cents to match target refund total.', $cart_for_api[$i]['reference'] ?? 'unknown', $diff));
                    $adjusted = true;
                    break;
                }
                if (!$adjusted) {
                    Logger::log('Skipped target refund total adjustment - all items are exact remaining-balance amounts.');
                }
            }
        }

        // Recalculate amountIncVat and amountExVat from the final (potentially adjusted) cart items
        $amount_inc_vat = 0;
        $amount_ex_vat = 0;
        foreach ($cart_for_api as &$api_item) {
            $is_sales_tax = ('sales_tax' === ($api_item['productType'] ?? ''));
            if ($is_sales_tax) {
                $amount_inc_vat += ($api_item['totalTaxAmount'] ?? 0);
            } else {
                $amount_inc_vat += ($api_item['totalAmount'] ?? 0);
                $amount_ex_vat += (($api_item['totalAmount'] ?? 0) - ($api_item['totalVatAmount'] ?? 0));
            }
            // Internal bookkeeping flag only - must not be sent to the Briqpay API.
            unset($api_item['_exact']);
        }
        unset($api_item);

        $data = array(
            'captureId' => $capture_id,
            'data' => array(
                'order' => array(
                    'amountIncVat' => $amount_inc_vat,
                    'amountExVat' => $amount_ex_vat,
                    'currency' => $order->get_currency(),
                    'cart' => $cart_for_api
                )
            )
        );

        Logger::log(sprintf('Executing refund for capture %s. Amount: %d', $capture_id, $amount_inc_vat));
        $response = $api->refund_order($session_id, $data);

        if (!is_wp_error($response)) {
            // translators: %1$s: refund amount, %2$s: capture ID
            $order->add_order_note(sprintf(__('Briqpay: Refunded %1$s from capture %2$s.', 'briqpay-for-woocommerce'), wc_price($amount_inc_vat / 100), $capture_id));

            // Track in history using the actual amounts sent to Briqpay (cart_for_api),
            // not the pre-adjustment $items - later refunds rely on this for accurate
            // remaining-balance calculations.
            $refund_history = $order->get_meta('_briqpay_refund_history') ?: array();
            $refund_history[] = array(
                'captureId' => $capture_id,
                'refundId'  => $response['refundId'] ?? null,
                'date'      => current_time('mysql'),
                'items'     => $cart_for_api,
                'amount'    => $amount_inc_vat
            );
            $order->update_meta_data('_briqpay_refund_history', $refund_history);
            $order->save();

            return true;
        }

        $error_message = $response->get_error_message();
        Logger::log('Refund API failure: ' . $error_message);
        return $response;
    }

    /**
     * Get items from the latest refund
     */
    private function get_refund_items($order)
    {
        $refunds = $order->get_refunds();
        if (empty($refunds)) {
            return array();
        }

        $latest_refund = reset($refunds);
        $items = array();
        $is_us = 'US' === $order->get_billing_country();
        $total_tax_to_refund = 0;

        foreach ($latest_refund->get_items() as $item) {
            $product = $item->get_product();
            $qty = abs($item->get_quantity());
            $item_total = abs((float) $item->get_total());
            $item_tax = abs((float) $item->get_total_tax());

            $parent_tax_rate = $this->get_item_tax_rate($item);
            $ref = $item->get_meta('_briqpay_item_reference');
            if (empty($ref) && $product) {
                $sku = $product->get_sku();
                $id = $product->get_id();
                $ref = !empty($sku) ? $sku : (string) $id;
            }

            if ($qty > 0) {
                $unit_ex = $item_total / $qty;
                $unit_inc = ($item_total + $item_tax) / $qty;
            } else {
                $unit_ex = $item_total;
                $unit_inc = $item_total + $item_tax;
                $qty = 1;
            }

            $items[] = array(
                'productType' => 'physical',
                'reference' => $ref ?: 'refund_item',
                'name' => $item->get_name(),
                'quantity' => abs($qty),
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($unit_ex * 100),
                'unitPriceIncVat' => $is_us ? (int) round($unit_ex * 100) : (int) round($unit_inc * 100),
                'taxRate' => $is_us ? 0 : $parent_tax_rate,
                'totalAmount' => $is_us ? (int) round($unit_ex * abs($qty) * 100) : (int) round($unit_inc * abs($qty) * 100),
                'totalVatAmount' => $is_us ? 0 : (int) round(($unit_inc - $unit_ex) * abs($qty) * 100),
            );

            if ($is_us) {
                $total_tax_to_refund += abs($item_tax);
            }
        }

        foreach ($latest_refund->get_shipping_methods() as $shipping) {
            $ship_total = abs((float) $shipping->get_total());
            $ship_tax = abs((float) $shipping->get_total_tax());

            if ($ship_total > 0) {
                if ($is_us) {
                    $total_tax_to_refund += $ship_tax;
                }
                $items[] = array(
                    'productType' => 'shipping_fee',
                    'reference' => 'shipping',
                    'name' => __('Shipping', 'briqpay-for-woocommerce'),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($ship_total * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($ship_total * 100) : (int) round(($ship_total + $ship_tax) * 100),
                    'taxRate' => $is_us ? 0 : $this->get_shipping_tax_rate($order),
                    'totalAmount' => $is_us ? (int) round($ship_total * 100) : (int) round(($ship_total + $ship_tax) * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($ship_tax * 100),
                );
            }
        }

        // Coupons in refund
        $refund_coupon_items = $latest_refund->get_items('coupon');

        if (!empty($refund_coupon_items)) {
            // Refund object explicitly includes coupon items — use them directly
            foreach ($refund_coupon_items as $item) {
                $code = $item->get_code();
                $discount_amount = abs((float) $item->get_discount());
                $discount_tax = abs((float) $item->get_discount_tax());

                if ($is_us) {
                    $total_tax_to_refund -= $discount_tax;
                }

                $ref = 'discount_' . $code;

                $amount_enc_vat_val = ($discount_amount + $discount_tax) * -1;
                $amount_ex_vat_val = $discount_amount * -1;
                $vat_amount_val = $discount_tax * -1;

                $items[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    // translators: %s: coupon code
                    'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($amount_ex_vat_val * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($amount_ex_vat_val * 100) : (int) round($amount_enc_vat_val * 100),
                    'taxRate' => $is_us ? 0 : $this->get_coupon_tax_rate($order),
                    'totalAmount' => $is_us ? (int) round($amount_ex_vat_val * 100) : (int) round($amount_enc_vat_val * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($vat_amount_val * 100),
                );
            }
        }

        // Add USA Sales Tax Item for refund
        if ($is_us && $total_tax_to_refund > 0) {
            $items[] = array(
                'productType' => 'sales_tax',
                'name' => __('Sales tax', 'briqpay-for-woocommerce'),
                'reference' => 'sales_tax',
                'quantity' => (int) round($total_tax_to_refund * 100), // Internal tracking
                'totalTaxAmount' => (int) round($total_tax_to_refund * 100),
            );
        }

        return $items;
    }

    /**
     * Cancel Order
     */
    public function cancel_order($order_id)
    {
        Logger::log(sprintf('cancel_order() called for order %s.', $order_id));
        if (!$this->is_enabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'briqpay') {
            return;
        }

        $session_id = $order->get_meta('_briqpay_session_id');
        $history = $order->get_meta('_briqpay_capture_history') ?: array();

        if (!$session_id) {
            Logger::log('Cancel aborted: No session ID found.');
            return;
        }

        if (!empty($history)) {
            Logger::log('Cancel aborted: Order has already been partially or fully captured.');
            $order->add_order_note(__('Briqpay: Cannot cancel order after capture. Please use refund instead.', 'briqpay-for-woocommerce'));
            return;
        }

        $api = $this->get_api();
        $response = $api->cancel_order($session_id);

        if (!is_wp_error($response)) {
            Logger::log('Cancel request successful.');
            $order->add_order_note(__('Briqpay: Cancel request sent successfully.', 'briqpay-for-woocommerce'));
        } else {
            Logger::log('Cancel API failure: ' . $response->get_error_message());
            // translators: %s: error message
            $order->add_order_note(sprintf(__('Briqpay: Cancel request failed: %s', 'briqpay-for-woocommerce'), $response->get_error_message()));
        }

        $order->save();
    }

    /**
     * Get API Instance
     */
    protected function get_api()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
    }

    /**
     * Check if enabled
     */
    protected function is_enabled()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return 'yes' === ($settings['order_management_enabled'] ?? 'no');
    }

    /**
     * Whether a Briqpay session indicates the payment method used has
     * auto-capture enabled (data.transactions[].autoCaptureEnabled). When
     * true, Briqpay captures the order automatically and merchants should
     * not be offered a manual capture button.
     *
     * Shared between Webhooks and Checkout_Handler, which each independently
     * parse session/transaction data at their own points of contact with the
     * Briqpay API.
     *
     * @param array $session Briqpay session data (from get_session() or a webhook payload).
     * @return bool
     */
    public static function session_has_auto_capture_enabled($session)
    {
        if (empty($session['data']['transactions'])) {
            return false;
        }

        foreach ($session['data']['transactions'] as $transaction) {
            if (!empty($transaction['autoCaptureEnabled'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get remaining items to capture
     */
    private function get_remaining_items_to_capture($order)
    {
        $history = $order->get_meta('_briqpay_capture_history') ?: array();
        $captured_counts = array();
        foreach ($history as $h) {
            foreach ($h['items'] as $item) {
                $ref = $item['reference'];
                $captured_counts[$ref] = ($captured_counts[$ref] ?? 0) + $item['quantity'];
            }
        }

        $is_us = 'US' === $order->get_billing_country();
        $total_tax_to_capture = 0;

        $items_to_capture = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $ref = $item->get_meta('_briqpay_item_reference');
            if (empty($ref)) {
                if (!$product) continue;
                $sku = $product->get_sku();
                $id = $product->get_id();
                $ref = !empty($sku) ? $sku : (string) $id;
            }

            $total_qty = $item->get_quantity();
            $remaining = $total_qty - ($captured_counts[$ref] ?? 0);

            if ($remaining > 0) {
                $item_total = (float) $item->get_subtotal();
                $item_tax = (float) $item->get_subtotal_tax();
                $tax_rate = $this->get_item_tax_rate($item);

                $total_inc = $item_total + $item_tax;
                $unit_inc = $total_qty > 0 ? ($total_inc / $total_qty) : 0;
                $unit_ex = $total_qty > 0 ? ($item_total / $total_qty) : 0;

                if ($is_us) {
                    $total_tax_to_capture += ($item_tax / ($total_qty ?: 1)) * $remaining;
                }

                $items_to_capture[] = array(
                    'productType' => 'physical',
                    'reference' => (string) $ref,
                    'name' => $item->get_name(),
                    'quantity' => $remaining,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($unit_ex * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($unit_ex * 100) : (int) round($unit_inc * 100),
                    'taxRate' => $is_us ? 0 : $tax_rate,
                    'totalAmount' => $is_us ? (int) round($unit_ex * $remaining * 100) : (int) round($unit_inc * $remaining * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round(($unit_inc - $unit_ex) * $remaining * 100),
                );
            }
        }

        // Add shipping if not captured
        $shipping_total = (float) $order->get_shipping_total();
        if ($shipping_total > 0) {
            $ship_ref = 'shipping';
            if (!isset($captured_counts[$ship_ref])) {
                $shipping_tax = (float) $order->get_shipping_tax();
                $tax_rate = $this->get_shipping_tax_rate($order);

                if ($is_us) {
                    $total_tax_to_capture += $shipping_tax;
                }

                $items_to_capture[] = array(
                    'productType' => 'shipping_fee',
                    'reference' => $ship_ref,
                    'name' => __('Shipping', 'briqpay-for-woocommerce'),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($shipping_total * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($shipping_total * 100) : (int) round(($shipping_total + $shipping_tax) * 100),
                    'taxRate' => $is_us ? 0 : $tax_rate,
                    'totalAmount' => $is_us ? (int) round($shipping_total * 100) : (int) round(($shipping_total + $shipping_tax) * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($shipping_tax * 100),
                );
            }
        }

        // Add Fees
        foreach ($order->get_fees() as $fee) {
            $ref = $fee->get_meta('_briqpay_fee_reference') ?: (string) $fee->get_id();
            if (!isset($captured_counts[$ref])) {
                $fee_total = (float) $fee->get_total();
                $fee_tax = (float) $fee->get_total_tax();
                $tax_rate = $this->get_item_tax_rate($fee);

                if ($is_us) {
                    $total_tax_to_capture += $fee_tax;
                }

                $items_to_capture[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    'name' => $fee->get_name(),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($fee_total * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($fee_total * 100) : (int) round(($fee_total + $fee_tax) * 100),
                    'taxRate' => $is_us ? 0 : $tax_rate,
                    'totalAmount' => $is_us ? (int) round($fee_total * 100) : (int) round(($fee_total + $fee_tax) * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($fee_tax * 100),
                );
            }
        }

        // Coupons are now handled as separate lines
        foreach ($order->get_items('coupon') as $coupon) {
            $code = $coupon->get_code();
            $ref = 'discount_' . $code;
            if (!isset($captured_counts[$ref])) {
                $discount_amount = (float) $coupon->get_discount();
                $discount_tax = (float) $coupon->get_discount_tax();
                $tax_rate = $this->get_coupon_tax_rate($order);

                $items_to_capture[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    // translators: %s: coupon code
                    'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($discount_amount * -100),
                    'unitPriceIncVat' => $is_us ? (int) round($discount_amount * -100) : (int) round(($discount_amount + $discount_tax) * -100),
                    'taxRate' => $is_us ? 0 : $tax_rate,
                    'totalAmount' => $is_us ? (int) round($discount_amount * -100) : (int) round(($discount_amount + $discount_tax) * -100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($discount_tax * -100),
                );
            }
        }

        // Add USA Sales Tax Item
        if ($is_us && $total_tax_to_capture > 0) {
            $items_to_capture[] = array(
                'productType' => 'sales_tax',
                'name' => __('Sales tax', 'briqpay-for-woocommerce'),
                'reference' => 'sales_tax',
                'quantity' => (int) round($total_tax_to_capture * 100), // Internal tracking
                'totalTaxAmount' => (int) round($total_tax_to_capture * 100),
            );
        }

        return $items_to_capture;
    }

    /**
     * Map Order items to Briqpay cart format
     *
     * Public: also used by Hosted_Payment_Page::build_session_payload() so that
     * hosted payment page sessions use the exact same reference derivation
     * (_briqpay_item_reference / _briqpay_fee_reference / SKU / ID) that
     * captures and refunds later rely on.
     */
    public function get_order_cart($order)
    {
        $items = array();
        $is_us = 'US' === $order->get_billing_country();
        $total_tax = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $ref = $item->get_meta('_briqpay_item_reference');
            if (empty($ref)) {
                if ($product) {
                    $sku = $product->get_sku();
                    $id = $product->get_id();
                    $ref = !empty($sku) ? $sku : (string) $id;
                } else {
                    $ref = 'item_' . $item->get_id();
                }
            }

            $qty = $item->get_quantity();
            $item_total = (float) $item->get_subtotal();
            $item_tax = (float) $item->get_subtotal_tax();
            $tax_rate = $this->get_item_tax_rate($item);

            $unit_inc = $qty > 0 ? (($item_total + $item_tax) / $qty) : 0;
            $unit_ex = $qty > 0 ? ($item_total / $qty) : 0;

            if ($is_us) {
                $total_tax += $item_tax;
                $unit_inc = $unit_ex;
                $tax_rate = 0;
            }

            $items[] = array(
                'productType' => 'physical',
                'reference' => (string) $ref,
                'name' => $item->get_name(),
                'quantity' => $qty,
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($unit_ex * 100),
                'unitPriceIncVat' => (int) round($unit_inc * 100),
                'taxRate' => $tax_rate,
                'totalAmount' => (int) round($unit_inc * $qty * 100),
                'totalVatAmount' => $is_us ? 0 : (int) round($item_tax * 100),
            );
        }

        foreach ($order->get_items('coupon') as $coupon) {
            $code = $coupon->get_code();
            $discount_amount = (float) $coupon->get_discount();
            $discount_tax = (float) $coupon->get_discount_tax();
            $tax_rate = $this->get_coupon_tax_rate($order);

            $items[] = array(
                'productType' => 'physical',
                'reference' => 'discount_' . $code,
                // translators: %s: coupon code
                'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($discount_amount * -100),
                'unitPriceIncVat' => (int) round(($discount_amount + $discount_tax) * -100),
                'taxRate' => $tax_rate,
                'totalAmount' => (int) round(($discount_amount + $discount_tax) * -100),
                'totalVatAmount' => (int) round($discount_tax * -100),
            );
        }

        // Add Shipping
        $shipping_total = (float) $order->get_shipping_total();
        if ($shipping_total > 0) {
            $shipping_tax = (float) $order->get_shipping_tax();
            if ($is_us) {
                $total_tax += $shipping_tax;
            }
            $items[] = array(
                'productType' => 'shipping_fee',
                'reference' => 'shipping',
                'name' => __('Shipping', 'briqpay-for-woocommerce'),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($shipping_total * 100),
                'unitPriceIncVat' => $is_us ? (int) round($shipping_total * 100) : (int) round(($shipping_total + $shipping_tax) * 100),
                'taxRate' => $is_us ? 0 : $this->get_shipping_tax_rate($order),
                'totalAmount' => $is_us ? (int) round($shipping_total * 100) : (int) round(($shipping_total + $shipping_tax) * 100),
                'totalVatAmount' => $is_us ? 0 : (int) round($shipping_tax * 100),
            );
        }

        // Add Fees
        foreach ($order->get_fees() as $fee) {
            $fee_total = (float) $fee->get_total();
            $fee_tax = (float) $fee->get_total_tax();
            $fee_ref = $fee->get_meta('_briqpay_fee_reference') ?: (string) $fee->get_id();

            if ($is_us) {
                $total_tax += $fee_tax;
            }

            $items[] = array(
                'productType' => 'physical',
                'reference' => $fee_ref,
                'name' => $fee->get_name(),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($fee_total * 100),
                'unitPriceIncVat' => $is_us ? (int) round($fee_total * 100) : (int) round(($fee_total + $fee_tax) * 100),
                'taxRate' => $is_us ? 0 : $this->get_item_tax_rate($fee),
                'totalAmount' => $is_us ? (int) round($fee_total * 100) : (int) round(($fee_total + $fee_tax) * 100),
                'totalVatAmount' => $is_us ? 0 : (int) round($fee_tax * 100),
            );
        }

        // Add USA Sales Tax Item
        if ($is_us && $total_tax > 0) {
            $items[] = array(
                'productType' => 'sales_tax',
                'name' => __('Sales tax', 'briqpay-for-woocommerce'),
                'reference' => 'sales_tax',
                'quantity' => (int) round($total_tax * 100), // Internal tracking
                'totalTaxAmount' => (int) round($total_tax * 100),
            );
        }

        return $items;
    }

    /**
     * Get tax rate for an item
     */
    private function get_item_tax_rate($item)
    {
        $tax_data = $item->get_taxes();
        if (!empty($tax_data['total'])) {
            $tax_rate_id = key($tax_data['total']);
            return (int) round(\WC_Tax::get_rate_percent_value($tax_rate_id) * 100);
        }

        $total = (float) $item->get_total();
        $tax = (float) $item->get_total_tax();
        return ($total > 0) ? (int) round(($tax / $total) * 10000) : 0;
    }

    /**
     * Get tax rate for shipping
     */
    private function get_shipping_tax_rate($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $method) {
            return $this->get_item_tax_rate($method);
        }

        $total = (float) $order->get_shipping_total();
        $tax = (float) $order->get_shipping_tax();
        return ($total > 0) ? (int) round(($tax / $total) * 10000) : 0;
    }


    /**
     * Get tax rate for coupons from the order's first line item.
     * Coupons inherit the tax rate of the items they discount,
     * so we use the first item's tax rate instead of deriving it
     * from amounts (which causes precision errors like 25.01%).
     */
    private function get_coupon_tax_rate($order)
    {
        $items = $order->get_items();
        foreach ($items as $item) {
            return $this->get_item_tax_rate($item);
        }
        return 0;
    }

    /**
     * Get session data from Briqpay
     *
     * @param string $session_id
     * @return array|\WP_Error
     */
    private function get_session_data($session_id)
    {
        $api = $this->get_api();
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            return $session;
        }

        if (!isset($session['sessionId'])) {
            return new \WP_Error('invalid_session', __('Failed to retrieve valid session from Briqpay.', 'briqpay-for-woocommerce'));
        }

        return $session;
    }

    /**
     * Fetch session and sync local history with Briqpay record.
     *
     * @param \WC_Order $order
     * @return array|\WP_Error
     */
    private function sync_with_briqpay_session($order)
    {
        $session_id = $order->get_meta('_briqpay_session_id');
        if (!$session_id) {
            return new \WP_Error('no_session', __('No Briqpay session ID found for this order.', 'briqpay-for-woocommerce'));
        }

        $session = $this->get_session_data($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $needs_save = false;

        // 1. Sync Captures
        $local_capture_history = $order->get_meta('_briqpay_capture_history') ?: array();
        $local_capture_ids = array_column($local_capture_history, 'captureId');
        $remote_captures = $session['data']['captures'] ?? array();

        foreach ($remote_captures as $rc) {
            if ($rc['status'] === 'approved' && !in_array($rc['captureId'], $local_capture_ids)) {
                $local_capture_history[] = array(
                    'captureId' => $rc['captureId'],
                    'date' => $rc['createdAt'],
                    'items' => $rc['cart'],
                    'amount' => $rc['amountIncVat']
                );
                $needs_save = true;
            }
        }

        if ($needs_save) {
            $order->update_meta_data('_briqpay_capture_history', $local_capture_history);
            $order->save();
            $needs_save = false;
        }

        // 2. Sync Refunds
        $local_refund_history = $order->get_meta('_briqpay_refund_history') ?: array();
        // Check for refundId if available, otherwise we use a combination of captureId and amount for matching if needed, 
        // but Briqpay refunds have unique refundId.
        $local_refund_ids = array_column($local_refund_history, 'refundId');
        $remote_refunds = $session['data']['refunds'] ?? array();

        foreach ($remote_refunds as $rr) {
            $ref_id = $rr['refundId'] ?? null;
            if ($rr['status'] === 'approved' && $ref_id && !in_array($ref_id, $local_refund_ids)) {
                $local_refund_history[] = array(
                    'captureId' => $rr['captureId'],
                    'refundId' => $ref_id,
                    'date' => $rr['createdAt'],
                    'items' => $rr['cart'],
                    'amount' => $rr['amountIncVat']
                );
                $needs_save = true;
            }
        }

        if ($needs_save) {
            $order->update_meta_data('_briqpay_refund_history', $local_refund_history);
            $order->save();
        }

        return $session;
    }

    /**
     * Find an item in the session cart to get canonical prices.
     *
     * @param array  $session
     * @param string $reference
     * @return array|null
     */
    private function get_canonical_session_item($session, $reference)
    {
        // Prefer the authorized transaction cart: it reflects exactly what the PSP
        // (e.g. Klarna) approved, which is the hard ceiling for what a capture/refund
        // can request. The session's top-level order.cart can keep drifting after
        // authorization (e.g. via later session PATCHes), so using it as the primary
        // source can produce a capture amount that exceeds what was ever authorized
        // and gets rejected with CAPTURE_NOT_ALLOWED.
        if (isset($session['data']['transactions'])) {
            foreach ($session['data']['transactions'] as $tx) {
                if (isset($tx['cart'])) {
                    foreach ($tx['cart'] as $item) {
                        if ($item['reference'] === $reference) {
                            return $item;
                        }
                    }
                }
            }
        }

        // Fallback: no authorized transaction recorded yet.
        if (isset($session['data']['order']['cart'])) {
            foreach ($session['data']['order']['cart'] as $item) {
                if ($item['reference'] === $reference) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Sum already-approved-captured quantity/amount for a reference, read directly
     * from the Briqpay session's own capture records - the authoritative source of
     * what has actually been captured with the PSP so far.
     *
     * @param array  $session
     * @param string $reference
     * @return array{quantity:int,totalAmount:int,totalVatAmount:int}
     */
    private function get_already_captured_from_session($session, $reference)
    {
        $totals = array('quantity' => 0, 'totalAmount' => 0, 'totalVatAmount' => 0);

        foreach (($session['data']['captures'] ?? array()) as $capture) {
            if ('approved' !== ($capture['status'] ?? '')) {
                continue;
            }
            foreach (($capture['cart'] ?? array()) as $it) {
                if (($it['reference'] ?? null) !== $reference) {
                    continue;
                }
                $totals['quantity'] += (int) ($it['quantity'] ?? 0);
                $totals['totalAmount'] += (int) ($it['totalAmount'] ?? 0);
                $totals['totalVatAmount'] += (int) ($it['totalVatAmount'] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Apply the canonical line total to a capture/refund API item.
     *
     * When the total authorized amount for a line isn't evenly divisible by its
     * quantity (e.g. 473.43 SEK / 2 units), the canonical unitPriceIncVat is itself
     * a rounded value (236.72). Recomputing round(unitPriceIncVat * qty) then
     * reproduces a total that can be higher than what was ever authorized, which
     * Klarna rejects with CAPTURE_NOT_ALLOWED - and doing that on each of several
     * partial captures compounds into an over-capture even though each individual
     * request looked correct in isolation. When the requested quantity, combined
     * with whatever has already been captured for this reference, covers the full
     * canonical line, use the exact remaining authorized amount (canonical total
     * minus what the session shows as already captured) instead of recomputing it.
     * Only fall back to a proportional unitPrice * qty calculation for a genuine
     * partial capture/refund that still leaves some quantity for later.
     *
     * @param array $api_item Item to mutate (by reference).
     * @param array $canonical Canonical session/transaction cart item.
     * @param int   $qty Quantity being captured/refunded now.
     * @param array $already_captured Quantity/amount already captured for this reference, e.g. from get_already_captured_from_session().
     */
    private function apply_canonical_totals(&$api_item, $canonical, $qty, $already_captured = array())
    {
        $canonical_qty = isset($canonical['quantity']) ? (int) $canonical['quantity'] : $qty;
        $already_qty = (int) ($already_captured['quantity'] ?? 0);

        if (($already_qty + $qty) >= $canonical_qty && isset($canonical['totalAmount'])) {
            $api_item['totalAmount'] = (int) $canonical['totalAmount'] - (int) ($already_captured['totalAmount'] ?? 0);
            $api_item['totalVatAmount'] = (int) ($canonical['totalVatAmount'] ?? 0) - (int) ($already_captured['totalVatAmount'] ?? 0);
            return;
        }

        $api_item['totalAmount'] = (int) round($canonical['unitPriceIncVat'] * $qty);
        $api_item['totalVatAmount'] = (int) round(($canonical['unitPriceIncVat'] - $canonical['unitPrice']) * $qty);
    }

    /**
     * Recompute unitPrice/unitPriceIncVat/totalAmount/totalVatAmount for a refund
     * line item that exhausts the true remaining captured balance for a reference.
     *
     * Deriving unitPriceIncVat from the true balance and then computing
     * totalAmount FROM that rounded per-unit price - rather than sending the true
     * total alongside a stale, independently-computed per-unit price left over from
     * whatever WooCommerce's own refund line happened to compute - guarantees
     * unitPriceIncVat * quantity === totalAmount. Briqpay validates that
     * relationship server-side and rejects the ENTIRE refund with INVALID_DATA if
     * it doesn't hold, which it won't whenever the true remaining balance differs
     * from what WooCommerce's line naively computed (e.g. the admin adjusted a
     * refund line's amount, or per-unit rounding drifted during capture).
     *
     * For the common quantity=1 case (shipping, fees, coupons, single-unit
     * products) this introduces zero rounding drift. A multi-quantity full
     * exhaustion could absorb up to (quantity - 1) minor units of drift versus the
     * true balance - an acceptable trade-off against an outright API rejection.
     *
     * @param int $true_amount Exact remaining captured amount (minor units).
     * @param int $true_vat    Exact remaining captured VAT amount (minor units).
     * @param int $quantity    Quantity being refunded now.
     * @return array{unitPrice:int,unitPriceIncVat:int,totalAmount:int,totalVatAmount:int}
     */
    private function resolve_exact_refund_totals($true_amount, $true_vat, $quantity)
    {
        if ($quantity <= 0) {
            return array(
                'unitPrice' => 0,
                'unitPriceIncVat' => 0,
                'totalAmount' => $true_amount,
                'totalVatAmount' => $true_vat,
            );
        }

        $unit_price_inc_vat = (int) round($true_amount / $quantity);
        $unit_price = (int) round(($true_amount - $true_vat) / $quantity);

        return array(
            'unitPrice' => $unit_price,
            'unitPriceIncVat' => $unit_price_inc_vat,
            'totalAmount' => $unit_price_inc_vat * $quantity,
            'totalVatAmount' => ($unit_price_inc_vat - $unit_price) * $quantity,
        );
    }
}
