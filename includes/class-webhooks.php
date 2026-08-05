<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Webhooks
 */
class Webhooks
{

    /**
     * Init
     */
    public function init()
    {
        add_action('woocommerce_api_briqpay_webhook', array($this, 'handle_webhook'));
        add_action('briqpay_v3_process_webhook_callback', array($this, 'process_webhook_callback'), 10, 1);
    }

    /**
     * Handle Webhook
     */
    public function handle_webhook()
    {
        // Webhooks are server-to-server POST requests from Briqpay with no user session.
        // Nonce verification is not applicable here. Instead, we verify payload authenticity
        // by fetching the session from the Briqpay API in process_webhook_callback().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Comparison only, no output.
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('POST' !== $request_method) {
            wp_die('Invalid request method', 'Briqpay Webhook', array('response' => 405));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading raw POST body for webhook; no WP alternative exists.
        $payload = file_get_contents('php://input');
        if (empty($payload)) {
            Logger::log('Empty webhook payload received.');
            wp_die('Empty payload', 'Briqpay Webhook', array('response' => 400));
        }

        // Log receipt of webhook but avoid logging signatures or broad headers unless debugging is active and necessary
        Logger::log('Webhook received. Processing payload.');

        // Decode and validate structure; individual fields are sanitized via sanitize_key() below.
        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['sessionId'])) {
            Logger::log('Invalid payload received (missing or invalid sessionId).');
            wp_die('Invalid payload', 'Briqpay Webhook', array('response' => 400));
        }

        // Sanitize all string values recursively, then apply stricter sanitization to identifiers.
        $data = map_deep($data, 'sanitize_text_field');
        $data['sessionId'] = sanitize_key($data['sessionId']);
        if (isset($data['action'])) {
            $data['action'] = sanitize_key($data['action']);
        }
        if (isset($data['event'])) {
            $data['event'] = sanitize_key($data['event']);
        }
        if (isset($data['status'])) {
            $data['status'] = sanitize_key($data['status']);
        }

        Logger::log('Webhook received for session: ' . $data['sessionId'] . '. Checking for duplicates.');

        $session_id = $data['sessionId'] ?? '';
        $action = $data['action'] ?? ($data['event'] ?? '');
        $status = $data['status'] ?? '';
        $event_id = $data['captureId'] ?? ($data['refundId'] ?? ($data['id'] ?? ''));
        $dedup_key = 'briqpay_wh_' . md5($session_id . '|' . $action . '|' . $status . '|' . $event_id);

        if (get_transient($dedup_key)) {
            Logger::log('Webhook duplicate skipped for session: ' . $session_id);
            wp_send_json_success(array('message' => 'Duplicate webhook ignored'));
        }

        set_transient($dedup_key, 1, 5 * MINUTE_IN_SECONDS);

        // Enqueue async action for background processing
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('briqpay_v3_process_webhook_callback', array('payload' => $data), 'briqpay');
        } else {
            // Fallback for older Action Scheduler or if not present
            Logger::log('Action Scheduler not found. Processing immediately.');
            $this->process_webhook_callback($data);
        }

        wp_send_json_success(array('message' => 'Webhook received and scheduled'));
    }

    /**
     * Process Webhook Callback (Action Scheduler Task)
     */
    public function process_webhook_callback($data)
    {
        $session_id = $data['sessionId'] ?? '';
        $action = $data['action'] ?? ($data['event'] ?? 'session');
        $retry_count = (int) ($data['_briqpay_retry_count'] ?? 0);

        if (!$session_id) {
            return;
        }

        Logger::log(sprintf('Background processing starting for session: %s | Action/Event: %s', $session_id, $action));

        $order = $this->get_order_by_session_id($session_id);
        if (!$order) {
            $this->retry_or_fail_webhook($data, $retry_count, 'Order not found for session ' . $session_id);
            return;
        }

        // Fetch session from Briqpay API to ensure webhook legitimacy and retrieve authoritative data
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            $this->retry_or_fail_webhook($data, $retry_count, 'API error: ' . $session->get_error_message());
            return;
        }

        // Route based on action or event
        if ('capture' === $action || 'capture_status' === $action) {
            $this->handle_capture_status($order, $data['status'] ?? '', $data, $session);
            return;
        }

        if ('refund' === $action || 'refund_status' === $action) {
            $this->handle_refund_status($order, $data['status'] ?? '', $data, $session);
            return;
        }

        if ('order_status' === $action) {
            $this->handle_order_status($order, $data['status'] ?? '', $data);
            return;
        }

        // Standard Session/Order Status Update logic
        do_action('briqpay_webhook_received', $data, $session, $order);

        $status = $session['status'] ?? '';
        $order_status = $session['order']['status'] ?? '';

        Logger::log('Session status: ' . $status . ' | Order status: ' . $order_status);

        $new_wc_status = '';
        $status_note = '';

        switch ($status) {
            case 'completed':
                if ($order->has_status('processing') || $order->has_status('completed')) {
                    Logger::log('Order already processed.');
                    return;
                }

                // Verify amount and currency match between WC order and Briqpay session
                $bp_amount = $session['data']['order']['amountIncVat'] ?? 0;
                $order_amount = (float) $order->get_total();
                $bp_currency = $session['data']['order']['currency'] ?? '';
                $order_currency = $order->get_currency();

                if (abs(($bp_amount / 100) - $order_amount) > 0.05 || strtolower($bp_currency) !== strtolower($order_currency)) {
                    Logger::log(sprintf('Security: Amount or currency mismatch on payment completion. BP: %s %s, WC: %s %s. Setting order to on-hold.', $bp_amount, $bp_currency, $order_amount, $order_currency));
                    $order->update_status('on-hold', sprintf(__('Briqpay: Payment amount/currency mismatch! BP: %s %s, WC: %s %s.', 'briqpay-for-woocommerce'), $bp_amount, $bp_currency, $order_amount, $order_currency));
                    return;
                }

                // Use payment_complete() to trigger WooCommerce analytics hooks,
                // set date_paid, reduce stock, and fire woocommerce_payment_complete.
                $this->update_payment_method_title($order, $session);
                $order->add_order_note(__('Briqpay: Payment confirmed via side-channel.', 'briqpay-for-woocommerce'));
                $order->payment_complete($session_id);
                Logger::log('Order payment_complete() called for order: ' . $order->get_id());
                return;

            case 'cancelled':
            case 'failed':
                // Guard against a delayed/out-of-order webhook regressing an order
                // that a later webhook has already moved past this point (e.g. a
                // stale 'failed' notification arriving after the order was approved
                // and captured) - mirrors the same rank guard handle_order_status()
                // already applies to order_status webhooks.
                if ($order->has_status(array('processing', 'completed'))) {
                    Logger::log(sprintf('Ignoring session %s webhook for order %s because order is already %s.', $status, $order->get_id(), $order->get_status()));
                    return;
                }

                if ('cancelled' === $status) {
                    $new_wc_status = 'cancelled';
                    $status_note = __('Briqpay: Session cancelled.', 'briqpay-for-woocommerce');
                } else {
                    $new_wc_status = 'failed';
                    $status_note = __('Briqpay: Session failed.', 'briqpay-for-woocommerce');
                }
                break;
        }

        if ($new_wc_status) {
            $new_wc_status = apply_filters('briqpay_webhook_order_status', $new_wc_status, $order, $session);
            $order->update_status($new_wc_status, $status_note);

            if ($status === 'completed') {
                $this->update_payment_method_title($order, $session);
            }
        }
    }

    /**
     * Retry a transient webhook processing failure (order not found yet, or a
     * Briqpay API error) up to 3 times with a 5-minute delay, mirroring
     * Order_Management's capture-retry pattern. Each attempt is a fresh Action
     * Scheduler action, so the failing one still shows up in the Scheduled Actions
     * log; once retries are exhausted, throw so the final failure stays visible
     * there too instead of processing silently stopping.
     */
    private function retry_or_fail_webhook($data, $retry_count, $message)
    {
        $max_retries = 3;
        $session_id = $data['sessionId'] ?? '';

        if ($retry_count >= $max_retries || !function_exists('as_schedule_single_action')) {
            Logger::log(sprintf('Webhook processing failed permanently for session %s after %d attempt(s): %s', $session_id, $retry_count + 1, $message));
            throw new \Exception($message);
        }

        $data['_briqpay_retry_count'] = $retry_count + 1;
        as_schedule_single_action(time() + 300, 'briqpay_v3_process_webhook_callback', array('payload' => $data), 'briqpay');
        Logger::log(sprintf('Webhook processing failed (attempt %d/%d) for session %s: %s. Retrying in 5 minutes.', $retry_count + 1, $max_retries, $session_id, $message));
    }

    /**
     * Get Order by Session ID
     */
    private function get_order_by_session_id($session_id)
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $order_ids = wc_get_orders(array(
            'meta_key' => '_briqpay_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'limit' => 1,
            'return' => 'ids',
        ));
        return !empty($order_ids) ? wc_get_order(reset($order_ids)) : null;
    }

    /**
     * Handle Order Status
     */
    private function handle_order_status($order, $status, $data)
    {
        $status_hierarchy = array(
            'pending' => 1,
            'on-hold' => 2,
            'processing' => 3,
            'completed' => 4,
        );

        $current_status = $order->get_status();
        $current_rank = $status_hierarchy[$current_status] ?? 0;

        switch ($status) {
            case 'order_pending':
                if ($current_rank >= 1) {
                    Logger::log(sprintf('Ignoring order_pending webhook for order %s because current status is %s', $order->get_id(), $current_status));
                    return;
                }
                $order->update_status('pending', __('Briqpay: Order pending.', 'briqpay-for-woocommerce'));
                break;
            case 'order_approved_not_captured':
                if ($current_rank >= 3) {
                    Logger::log(sprintf('Ignoring order_approved_not_captured webhook for order %s because current status is %s', $order->get_id(), $current_status));
                    return;
                }
                $order->update_status('processing', __('Briqpay: Order approved, ready for capture.', 'briqpay-for-woocommerce'));
                $this->update_payment_method_title($order);
                break;
            case 'order_rejected':
            case 'order_cancelled':
                if ($current_rank >= 3) {
                    Logger::log(sprintf('Ignoring %s webhook for order %s because order is already %s', $status, $order->get_id(), $current_status));
                    return;
                }
                /* translators: %s: order status */
                $order->update_status('cancelled', sprintf(__('Briqpay: Order %s.', 'briqpay-for-woocommerce'), $status));
                break;
        }
    }

    /**
     * Update Payment Method Title from Session
     */
    private function update_payment_method_title($order, $session = null)
    {
        if (!$session) {
            $session_id = $order->get_meta('_briqpay_session_id');
            $settings = get_option('woocommerce_briqpay_settings');
            $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
            $session = $api->get_session($session_id);
        }

        if (!is_wp_error($session)) {
            $method_name = 'Briqpay';
            if (!empty($session['paymentMethod']['name'])) {
                $method_name = $session['paymentMethod']['name'];
            } elseif (!empty($session['data']['transactions'])) {
                foreach ($session['data']['transactions'] as $tx) {
                    if (!empty($tx['pspDisplayName'])) {
                        $method_name = $tx['pspDisplayName'];
                        break;
                    }
                }
            } elseif (!empty($session['data']['paymentMethod']['name'])) {
                $method_name = $session['data']['paymentMethod']['name'];
            }

            $order->set_payment_method_title($method_name);

            // Extended metadata for Admin Box
            if (!empty($session['clientToken'])) {
                $order->update_meta_data('_briqpay_client_token', $session['clientToken']);
            }

            if (!empty($session['purchaseSession']['pspIntegrationName'])) {
                $order->update_meta_data('_briqpay_psp_integration_name', $session['purchaseSession']['pspIntegrationName']);
            } elseif (!empty($session['data']['transactions'][0]['pspIntegrationName'])) {
                $order->update_meta_data('_briqpay_psp_integration_name', $session['data']['transactions'][0]['pspIntegrationName']);
            }

            if (!empty($session['purchaseSession']['reservationId'])) {
                $order->update_meta_data('_briqpay_reservation_id', $session['purchaseSession']['reservationId']);
            } elseif (!empty($session['data']['transactions'][0]['reservationId'])) {
                $order->update_meta_data('_briqpay_reservation_id', $session['data']['transactions'][0]['reservationId']);
            }

            $order->save();
        }
    }

    /**
     * Handle Capture Status
     */
    private function handle_capture_status($order, $status, $data, $session)
    {
        $capture_id = $data['captureId'] ?? '';
        if (empty($capture_id)) {
            Logger::log('Webhook capture_status missing captureId.');
            return;
        }

        // Authoritatively check the capture list or transactions in the verified API session object
        $authoritative_capture = null;
        $captures_list = $session['captures'] ?? ($session['data']['captures'] ?? array());
        foreach ($captures_list as $cap) {
            if (isset($cap['captureId']) && $cap['captureId'] === $capture_id) {
                $authoritative_capture = $cap;
                break;
            }
        }

        if (!$authoritative_capture && !empty($session['data']['transactions'])) {
            foreach ($session['data']['transactions'] as $tx) {
                if (isset($tx['captureId']) && $tx['captureId'] === $capture_id) {
                    $authoritative_capture = $tx;
                    break;
                }
            }
        }

        if (!$authoritative_capture) {
            Logger::log('Security: Capture ID ' . $capture_id . ' not found in official Briqpay session data.');
            return;
        }

        // Use authoritative status from the API response
        $auth_status = $authoritative_capture['status'] ?? '';
        if ('approved' === $auth_status || 'successful' === $auth_status) {
            $captures = $order->get_meta('_briqpay_captures') ?: array();

            // Store capture ID for potential refunds if not already recorded
            if (!in_array($capture_id, $captures)) {
                // translators: %s: capture ID
                $order->add_order_note(sprintf(__('Briqpay: Capture approved. ID: %s', 'briqpay-for-woocommerce'), $capture_id));

                $captures[] = $capture_id;
                $order->update_meta_data('_briqpay_captures', $captures);

                // Populate history for Admin Box visibility using authoritative API details
                $history = $order->get_meta('_briqpay_capture_history') ?: array();
                $history[] = array(
                    'captureId' => $capture_id,
                    'date' => current_time('mysql'),
                    'amount' => $authoritative_capture['amountIncVat'] ?? ($authoritative_capture['amount'] ?? 0),
                    'items' => $authoritative_capture['cart'] ?? ($authoritative_capture['items'] ?? array()),
                );
                $order->update_meta_data('_briqpay_capture_history', $history);

                $order->save();
            } else {
                Logger::log('Capture ' . $capture_id . ' already recorded.');
            }

            // If the order is currently pending/awaiting payment, mark it as paid since we successfully captured funds
            if ($order->has_status(array('pending', 'failed', 'on-hold', 'checkout-draft'))) {
                $bp_amount = $authoritative_capture['amountIncVat'] ?? ($authoritative_capture['amount'] ?? 0);
                $order_amount = (float) $order->get_total();
                $bp_currency = $authoritative_capture['currency'] ?? ($session['data']['order']['currency'] ?? '');
                $order_currency = $order->get_currency();

                if (abs(($bp_amount / 100) - $order_amount) > 0.05 || (!empty($bp_currency) && strtolower($bp_currency) !== strtolower($order_currency))) {
                    Logger::log(sprintf('Security: Amount or currency mismatch on capture webhook. BP: %s %s, WC: %s %s. Setting order to on-hold.', $bp_amount, $bp_currency, $order_amount, $order_currency));
                    $order->update_status('on-hold', sprintf(__('Briqpay: Capture amount/currency mismatch! BP: %s %s, WC: %s %s.', 'briqpay-for-woocommerce'), $bp_amount, $bp_currency, $order_amount, $order_currency));
                    return;
                }

                Logger::log('Order is in unpaid status. Calling payment_complete() via capture webhook.');
                $order->payment_complete($capture_id);
            }
        }
    }

    /**
     * Handle Refund Status
     */
    private function handle_refund_status($order, $status, $data, $session)
    {
        $refund_id = $data['refundId'] ?? '';
        if (empty($refund_id)) {
            Logger::log('Webhook refund_status missing refundId.');
            return;
        }

        // Authoritatively check the refunds list in the verified API session object
        $authoritative_refund = null;
        $refunds_list = $session['refunds'] ?? ($session['data']['refunds'] ?? array());
        foreach ($refunds_list as $ref) {
            if (isset($ref['refundId']) && $ref['refundId'] === $refund_id) {
                $authoritative_refund = $ref;
                break;
            }
        }

        if (!$authoritative_refund) {
            Logger::log('Security: Refund ID ' . $refund_id . ' not found in official Briqpay session data.');
            return;
        }

        $auth_status = $authoritative_refund['status'] ?? '';
        if ('approved' === $auth_status || 'successful' === $auth_status) {
            $note = __('Briqpay: Refund approved.', 'briqpay-for-woocommerce');
            $note .= sprintf(' ID: %s', $refund_id);
            $order->add_order_note($note);
        }
    }
}
