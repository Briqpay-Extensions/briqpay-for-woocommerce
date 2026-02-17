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
     * Log message
     */
    private function log($message)
    {
        if (defined('WC_LOG_DIR')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'briqpay-for-woocommerce'));
        }
    }

    /**
     * Init
     */
    public function init()
    {
        add_action('woocommerce_order_status_completed', array($this, 'capture_order'));
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_order'));
        add_filter('briqpay_process_refund', array($this, 'refund_order'), 10, 4);
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

        $this->execute_capture($order, $session_id, $remaining_items);
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

        foreach ($items_to_capture as $item) {
            $qty = (int) $item['quantity'];
            $unit_price_inc = (int) $item['unitPriceIncVat'];
            $tax_rate = (int) $item['taxRate'];

            // Precision fix: derive unit price ex VAT from unit price inc VAT
            if ($unit_price_inc < 0) {
                // For discounts, we must be careful with precision
                $unit_price_ex = (int) round($unit_price_inc / (1 + ($tax_rate / 10000)));
            } else {
                $unit_price_ex = (int) round($unit_price_inc / (1 + ($tax_rate / 10000)));
            }

            if ($is_us) {
                // For USA, we need to extract the tax portion from the 'inclusive' price sent from UI
                // so we can add it to the sales_tax item.
                $item_tax = ($unit_price_inc - $unit_price_ex) * $qty;
                $total_tax_to_capture += ($item_tax / 100); // UI sends minor units (*100), convert back to float for accumulation

                $unit_price_inc = $unit_price_ex;
                $tax_rate = 0;
            }

            $total_amount = $unit_price_inc * $qty;
            $total_vat = $is_us ? 0 : ($total_amount - ($unit_price_ex * $qty));

            $detailed_items[] = array(
                'productType' => $item['productType'] ?? 'physical',
                'reference' => sanitize_text_field($item['reference']),
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
        $api = $this->get_api();
        $billing_country = strtoupper(trim($order->get_billing_country()));
        $is_us = 'US' === $billing_country;

        $amount_inc_vat = 0;
        $amount_ex_vat = 0;
        $cart_for_api = array();

        foreach ($items as $item) {
            $api_item = $item;
            $is_sales_tax = ('sales_tax' === ($item['productType'] ?? ''));

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
            $order->add_order_note(sprintf(__('Briqpay: Captured. ID: %s', 'briqpay-for-woocommerce'), $capture_id));

            // Track history
            $history = $order->get_meta('_briqpay_capture_history') ?: array();
            $history[] = array(
                'captureId' => $capture_id,
                'date' => current_time('mysql'),
                'items' => $items,
                'amount' => $amount_inc_vat
            );
            $order->update_meta_data('_briqpay_capture_history', $history);

            // Legacy field for backward compatibility
            $captures = $order->get_meta('_briqpay_captures') ?: array();
            $captures[] = $capture_id;
            $order->update_meta_data('_briqpay_captures', $captures);

            $order->save();
            return $capture_id;
        }

        return new \WP_Error('api_error', __('Capture failed: No captureId returned.', 'briqpay-for-woocommerce'));
    }

    /**
     * Refund Order
     */
    public function refund_order($status, $order_id, $amount, $reason)
    {
        $this->log(sprintf('refund_order() called for order %s. Amount: %s', $order_id, $amount));
        if (!$this->is_enabled()) {
            return false;
        }

        $order = wc_get_order($order_id);
        $session_id = $order->get_meta('_briqpay_session_id');
        $capture_history = $order->get_meta('_briqpay_capture_history') ?: array();
        $refund_history = $order->get_meta('_briqpay_refund_history') ?: array();

        if (!$session_id || empty($capture_history)) {
            $this->log('Refund aborted: No session or no capture history.');
            return false;
        }

        // Get items from the latest refund object
        $refund_items = $this->get_refund_items($order);
        $this->log(sprintf('Detected %d refund items.', count($refund_items)));

        // If no specific items (full amount refund without item selection), 
        // we map it to the latest capture by default for simplicity, 
        // but it's better to treat it as a partial refund of the latest capture.
        if (empty($refund_items)) {
            $this->log('Generic amount refund. Mapping to latest capture.');
            $target_capture = end($capture_history);
            return $this->execute_single_refund($order, $session_id, $target_capture['captureId'], $this->get_order_cart($order), $amount);
        }

        // Calculate unrefunded quantities per capture
        $net_captures = $this->get_unrefunded_balances($capture_history, $refund_history);

        // Map refund items to captures
        $refunds_to_execute = array();
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
                } else {
                    $part_item['totalAmount'] = (int) round($ri['unitPriceIncVat'] * $taken);
                    $part_item['totalVatAmount'] = (int) round(($ri['unitPriceIncVat'] - $ri['unitPrice']) * $taken);
                }

                $refunds_to_execute[$nc['captureId']][] = $part_item;

                $nc['balances'][$ref] -= $taken;
                $qty_to_refund -= $taken;
            }

            if ($qty_to_refund > 0) {
                $this->log(sprintf('Warning: Could not find enough captured quantity for item %s (%d left).', $ref, $qty_to_refund));
                // We'll proceed with what we found, or fallback to the latest capture for the remainder
                $last_cap_id = end($capture_history)['captureId'];
                $refunds_to_execute[$last_cap_id][] = array_merge($ri, ['quantity' => $qty_to_refund]);
            }
        }

        // Execute API calls
        $success = true;
        foreach ($refunds_to_execute as $capture_id => $items) {
            $res = $this->execute_single_refund($order, $session_id, $capture_id, $items);
            if (!$res)
                $success = false;
        }

        return $success;
    }

    /**
     * Calculate available unrefunded quantities per capture
     */
    private function get_unrefunded_balances($capture_history, $refund_history)
    {
        $balances = array();
        foreach ($capture_history as $c) {
            $cap_items = array();
            foreach ($c['items'] as $it) {
                $cap_items[$it['reference']] = $it['quantity'];
            }
            $balances[] = array(
                'captureId' => $c['captureId'],
                'balances' => $cap_items
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
                    }
                }
            }
        }

        return $balances;
    }

    /**
     * Execute a single refund request to Briqpay
     */
    private function execute_single_refund($order, $session_id, $capture_id, $items, $override_amount = null)
    {
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

        foreach ($items as $item) {
            $api_item = $item;
            $is_sales_tax = ('sales_tax' === ($item['productType'] ?? ''));

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

        $this->log(sprintf('Executing refund for capture %s. Amount: %d', $capture_id, $amount_inc_vat));
        $response = $api->refund_order($session_id, $data);

        if (!is_wp_error($response)) {
            $order->add_order_note(sprintf(__('Briqpay: Refunded %s from capture %s.', 'briqpay-for-woocommerce'), wc_price($amount_inc_vat / 100), $capture_id));

            // Track in history
            $refund_history = $order->get_meta('_briqpay_refund_history') ?: array();
            $refund_history[] = array(
                'captureId' => $capture_id,
                'date' => current_time('mysql'),
                'items' => $items,
                'amount' => $amount_inc_vat
            );
            $order->update_meta_data('_briqpay_refund_history', $refund_history);
            $order->save();

            return true;
        }

        $this->log('Refund API failure: ' . print_r($response->get_error_message(), true));
        return false;
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

        foreach ($latest_refund->get_items() as $item) {
            $product = $item->get_product();
            $qty = abs($item->get_quantity());
            $item_total = abs((float) $item->get_total());
            $item_tax = abs((float) $item->get_total_tax());

            // Match back to parent item to get original subtotal price
            $parent_unit_inc = 0;
            $parent_unit_ex = 0;
            $parent_tax_rate = $this->get_item_tax_rate($item);

            foreach ($order->get_items() as $parent_item) {
                if ($parent_item->get_product_id() === $item->get_product_id() && $parent_item->get_variation_id() === $item->get_variation_id()) {
                    $p_qty = $parent_item->get_quantity();
                    $p_subtotal = (float) $parent_item->get_subtotal();
                    $p_subtotal_tax = (float) $parent_item->get_subtotal_tax();
                    $parent_unit_ex = $p_subtotal / $p_qty;
                    $parent_unit_inc = ($p_subtotal + $p_subtotal_tax) / $p_qty;
                    $parent_tax_rate = $this->get_item_tax_rate($parent_item);
                    break;
                }
            }

            // Fallback to current item logic if parent not found (shouldn't happen)
            if ($parent_unit_inc <= 0) {
                $parent_unit_ex = ($item_total / ($qty ?: 1));
                $parent_unit_inc = ($item_total + $item_tax) / ($qty ?: 1);
            }

            if ($qty > 0 || $item_total > 0) {
                $is_us = 'US' === $order->get_billing_country();
                $items[] = array(
                    'productType' => 'physical',
                    'reference' => (string) ($product ? ($product->get_sku() ?: $product->get_id()) : ''),
                    'name' => $item->get_name(),
                    'quantity' => $qty ?: 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($parent_unit_ex * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($parent_unit_ex * 100) : (int) round($parent_unit_inc * 100),
                    'taxRate' => $is_us ? 0 : $parent_tax_rate,
                    'totalAmount' => $is_us ? (int) round($parent_unit_ex * ($qty ?: 1) * 100) : (int) round($parent_unit_inc * ($qty ?: 1) * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round(($parent_unit_inc - $parent_unit_ex) * ($qty ?: 1) * 100),
                );
            }
        }

        $is_us = 'US' === $order->get_billing_country();
        $total_tax_to_refund = 0;
        // Recalculate tax collected from refund items if US
        if ($is_us) {
            foreach ($latest_refund->get_items() as $item) {
                $total_tax_to_refund += abs((float) $item->get_total_tax());
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
                    'name' => __('Shipping', 'woocommerce'),
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
        foreach ($latest_refund->get_items('coupon') as $item) {
            $code = $item->get_code();
            $discount_amount = abs((float) $item->get_discount());
            $discount_tax = abs((float) $item->get_discount_tax());

            if ($is_us) {
                $total_tax_to_refund -= $discount_tax;
            }

            // Map back to parent coupon to get reference format
            $ref = 'discount_' . $code;

            $amount_enc_vat_val = ($discount_amount + $discount_tax) * -1;
            $amount_ex_vat_val = $discount_amount * -1;
            $vat_amount_val = $discount_tax * -1;

            $items[] = array(
                'productType' => 'physical',
                'reference' => $ref,
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
        $this->log(sprintf('cancel_order() called for order %s.', $order_id));
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
            $this->log('Cancel aborted: No session ID found.');
            return;
        }

        if (!empty($history)) {
            $this->log('Cancel aborted: Order has already been partially or fully captured.');
            $order->add_order_note(__('Briqpay: Cannot cancel order after capture. Please use refund instead.', 'briqpay-for-woocommerce'));
            return;
        }

        $api = $this->get_api();
        $response = $api->cancel_order($session_id);

        if (!is_wp_error($response)) {
            $this->log('Cancel request successful.');
            $order->add_order_note(__('Briqpay: Cancel request sent successfully.', 'briqpay-for-woocommerce'));
        } else {
            $this->log('Cancel API failure: ' . print_r($response->get_error_message(), true));
            $order->add_order_note(sprintf(__('Briqpay: Cancel request failed: %s', 'briqpay-for-woocommerce'), $response->get_error_message()));
        }

        $order->save();
    }

    /**
     * Get API Instance
     */
    private function get_api()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
    }

    /**
     * Check if enabled
     */
    private function is_enabled()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return 'yes' === ($settings['order_management_enabled'] ?? 'no');
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
            $ref = $product->get_sku() ?: $product->get_id();
            $total_qty = $item->get_quantity();
            $remaining = $total_qty - ($captured_counts[$ref] ?? 0);

            if ($remaining > 0) {
                // Fix: Use get_subtotal() to get the price BEFORE cart-level discounts.
                // This matches the price sent to Briqpay during initial session creation.
                $item_total = (float) $item->get_subtotal();
                $item_tax = (float) $item->get_subtotal_tax();
                $tax_rate = $this->get_item_tax_rate($item);

                $total_inc = $item_total + $item_tax;
                $unit_inc = $total_inc / $total_qty;
                $unit_ex = $item_total / $total_qty;

                if ($is_us) {
                    $total_tax_to_capture += ($item_tax / $total_qty) * $remaining;
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
                    'name' => __('Shipping', 'woocommerce'),
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
            $ref = $fee->get_id();
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
        // Add coupons if not captured
        foreach ($order->get_items('coupon') as $item) {
            $code = $item->get_code();
            $ref = 'discount_' . $code;
            if (!isset($captured_counts[$ref])) {
                $discount_amount = (float) $item->get_discount();
                $discount_tax = (float) $item->get_discount_tax();
                $tax_rate = $this->get_coupon_tax_rate($order);

                $amount_enc_vat_val = ($discount_amount + $discount_tax) * -1;
                $amount_ex_vat_val = $discount_amount * -1;
                $vat_amount_val = $discount_tax * -1;

                if ($is_us) {
                    $total_tax_to_capture += $discount_tax * -1;
                }

                $items_to_capture[] = array(
                    'productType' => 'physical',
                    'reference' => $ref,
                    'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $code),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => (int) round($amount_ex_vat_val * 100),
                    'unitPriceIncVat' => $is_us ? (int) round($amount_ex_vat_val * 100) : (int) round($amount_enc_vat_val * 100),
                    'taxRate' => $is_us ? 0 : $tax_rate,
                    'totalAmount' => $is_us ? (int) round($amount_ex_vat_val * 100) : (int) round($amount_enc_vat_val * 100),
                    'totalVatAmount' => $is_us ? 0 : (int) round($vat_amount_val * 100),
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
     */
    private function get_order_cart($order)
    {
        $items = array();
        $is_us = 'US' === $order->get_billing_country();
        $total_tax = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $qty = $item->get_quantity();

            $item_total = (float) $item->get_subtotal();
            $item_tax = (float) $item->get_subtotal_tax();
            $tax_rate = $this->get_item_tax_rate($item);

            $unit_inc = ($item_total + $item_tax) / $qty;
            $unit_ex = $item_total / $qty;

            if ($is_us) {
                $total_tax += $item_tax;
            }

            $items[] = array(
                'productType' => 'physical',
                'reference' => (string) ($product->get_sku() ?: $product->get_id()),
                'name' => $item->get_name(),
                'quantity' => $qty,
                'quantityUnit' => 'pc',
                'unitPrice' => (int) round($unit_ex * 100),
                'unitPriceIncVat' => $is_us ? (int) round($unit_ex * 100) : (int) round($unit_inc * 100),
                'taxRate' => $is_us ? 0 : $tax_rate,
                'totalAmount' => $is_us ? (int) round($item_total * 100) : (int) round(($item_total + $item_tax) * 100),
                'totalVatAmount' => $is_us ? 0 : (int) round($item_tax * 100),
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
                'name' => __('Shipping', 'woocommerce'),
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

            if ($is_us) {
                $total_tax += $fee_tax;
            }

            $items[] = array(
                'productType' => 'physical',
                'reference' => $fee->get_id(),
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

        // Add Coupons
        foreach ($order->get_items('coupon') as $item) {
            $code = $item->get_code();
            $discount_amount = (float) $item->get_discount();
            $discount_tax = (float) $item->get_discount_tax();

            if ($is_us) {
                $total_tax -= $discount_tax;
            }

            $ref = 'discount_' . $code;
            $amount_enc_vat_val = ($discount_amount + $discount_tax) * -1;
            $amount_ex_vat_val = $discount_amount * -1;
            $vat_amount_val = $discount_tax * -1;

            $items[] = array(
                'productType' => 'physical',
                'reference' => $ref,
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
}
