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

        // Atomic claim. The previous get_transient()/set_transient() pair was a
        // check-then-set race: two concurrent deliveries of the same event could
        // both pass the read before either wrote, and both got processed.
        if (!Lock::claim_once($dedup_key, 5 * MINUTE_IN_SECONDS)) {
            Logger::log('Webhook duplicate skipped for session: ' . $session_id);
            wp_send_json_success(array('message' => 'Duplicate webhook ignored'));
        }

        // Enqueue async action for background processing
        $enqueued = false;
        if (function_exists('as_enqueue_async_action')) {
            $action_id = as_enqueue_async_action('briqpay_v3_process_webhook_callback', array('payload' => $data), 'briqpay');
            // Action Scheduler returns 0 when it could not schedule. Treating that
            // as success would silently drop the event, and because the dedup
            // marker is already claimed a Briqpay retry would be discarded as a
            // duplicate - losing the event entirely.
            $enqueued = !empty($action_id);
            if (!$enqueued) {
                Logger::error('Action Scheduler did not enqueue the webhook (returned empty). Processing inline instead.');
            }
        } else {
            Logger::log('Action Scheduler not found. Processing immediately.');
        }

        if (!$enqueued) {
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

        /**
         * Fires for every verified Briqpay webhook, before event routing.
         * Unlike briqpay_webhook_received (which only fires on the fall-through
         * session/order-status branch below), this fires for ALL subscribed
         * event types (order_status, capture_status, refund_status included).
         *
         * @param array     $data    Sanitized webhook payload.
         * @param array     $session Authoritative session fetched from the Briqpay API.
         * @param \WC_Order $order   The matched WooCommerce order.
         */
        do_action('briqpay_webhook_session_verified', $data, $session, $order);

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

                // A 'completed' session says the customer finished the checkout, not
                // that the money is secured. Block when the payload actively shows
                // no approved transaction (pending bank transfer, rejected card).
                // 'unknown' - no transactions in the payload - deliberately still
                // proceeds: refusing it would stop legitimately paid orders
                // completing on any flow whose payload omits transactions, which
                // would be a far worse regression than the case being guarded.
                if ('unapproved' === Order_Management::transaction_approval_state($session)) {
                    Logger::error(sprintf(
                        'Session %s is completed but no transaction is approved. Not marking order %s as paid; '
                        . 'awaiting an approving event.',
                        $session_id,
                        $order->get_id()
                    ));
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
     * Fire WooCommerce's "checkout completed" actions as a webhook fallback.
     */
    private function fire_commit_hooks_fallback($order)
    {
        // Fallback only. The storefront return handler is the primary site for
        // WooCommerce's "checkout completed" actions, because it still has the WC
        // session, the stashed checkout form and the logged-in user. This covers
        // the customer who pays and closes the browser without ever returning.
        //
        // Checkout_Handler::fire_once() means whichever path arrives first is the
        // only one that runs, so a normal purchase never fires these twice.
        //
        // Not tied to the order_approved_not_captured event specifically: that
        // event is skipped when the order is already further along, and
        // auto-capturing methods reach a paid state through the capture webhook
        // instead. It is called from the approved-status transition so it follows
        // the status, not one event name.
        //
        // Context here is degraded - a webhook is server-to-server, processed by
        // Action Scheduler, with no WC session, no cart and no current user. The
        // hooks receive an empty $data. Plugins that read only the order are
        // unaffected; ones expecting checkout context will no-op.
        // Check the gate before touching the order at all, so a disabled store
        // does no work here and the catch below never has to depend on the order
        // object still answering calls. fire_commit_hooks() re-checks; that is
        // cheap and keeps this path explicit.
        if (!Checkout_Handler::checkout_hooks_enabled()) {
            return;
        }

        try {
            (new Checkout_Handler())->fire_commit_hooks($order);
        } catch (\Throwable $e) {
            // The order id is already logged by fire_once(); keep this message
            // free of any further calls on the order.
            Logger::error('Commit hook webhook fallback failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle Order Status Webhook
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
                // Briqpay has approved the order, which is what the commit hooks
                // care about - so attempt them BEFORE the rank check below. That
                // check exists to avoid moving the WooCommerce status backwards,
                // but an order already advanced to 'processing' (e.g. by a capture
                // that arrived first) still needs its commit hooks if no earlier
                // path fired them. fire_once() makes this safe to attempt here.
                $this->fire_commit_hooks_fallback($order);

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
            // '_briqpay_psp_name' is what the "Briqpay Payment Details" meta box
            // reads (Admin_Order_Meta_Box::render_meta_box()). Checkout_Handler
            // also writes it, but only on the storefront iframe return - orders
            // that only ever go through the webhook path (e.g. hosted payment
            // pages) would otherwise show "PSP Name: N/A" forever.
            $order->update_meta_data('_briqpay_psp_name', $method_name);

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

            $order->update_meta_data(
                '_briqpay_auto_capture_enabled',
                Order_Management::session_has_auto_capture_enabled($session) ? 'yes' : 'no'
            );

            Legacy_B2b_Meta::apply($order, $session);

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

                // A capture can be the first thing that takes an order to paid,
                // arriving before or instead of an order_status webhook. Without
                // this, an order that reached paid purely through capture never
                // gets its commit hooks.
                $this->fire_commit_hooks_fallback($order);
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
