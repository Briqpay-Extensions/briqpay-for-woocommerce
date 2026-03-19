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
            $this->log('Empty webhook payload received.');
            wp_die('Empty payload', 'Briqpay Webhook', array('response' => 400));
        }

        // Log receipt of webhook but avoid logging signatures or broad headers unless debugging is active and necessary
        $this->log('Webhook received. Processing payload.');

        // Decode and validate structure; individual fields are sanitized via sanitize_key() below.
        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['sessionId'])) {
            $this->log('Invalid payload received (missing or invalid sessionId).');
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

        $this->log('Webhook received for session: ' . $data['sessionId'] . '. Enqueuing background task for API verification.');

        // Enqueue async action for background processing
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('briqpay_v3_process_webhook_callback', array('payload' => $data), 'briqpay');
        } else {
            // Fallback for older Action Scheduler or if not present
            $this->log('Action Scheduler not found. Processing immediately.');
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

        if (!$session_id) {
            return;
        }

        $this->log(sprintf('Background processing starting for session: %s | Action/Event: %s', $session_id, $action));

        $order = $this->get_order_by_session_id($session_id);
        if (!$order) {
            $this->log('Error: Order not found for session ' . $session_id);
            return;
        }

        // Route based on action or event
        if ('capture' === $action || 'capture_status' === $action) {
            $this->handle_capture_status($order, $data['status'] ?? '', $data);
            return;
        }

        if ('refund' === $action || 'refund_status' === $action) {
            $this->handle_refund_status($order, $data['status'] ?? '', $data);
            return;
        }

        // Standard Session/Order Status Update logic
        // Validate session via API to ensure data integrity
        $settings = get_option('woocommerce_briqpay_settings');
        $api = new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
        $session = $api->get_session($session_id);

        if (is_wp_error($session)) {
            $this->log('Error retrieving session from API: ' . $session->get_error_message());
            return;
        }

        do_action('briqpay_webhook_received', $data, $session, $order);

        $status = $session['status'] ?? '';
        $order_status = $session['order']['status'] ?? '';

        $this->log('Session status: ' . $status . ' | Order status: ' . $order_status);

        $new_wc_status = '';
        $status_note = '';

        switch ($status) {
            case 'completed':
                if ($order->has_status('processing') || $order->has_status('completed')) {
                    $this->log('Order already processed.');
                    return;
                }
                // Use payment_complete() to trigger WooCommerce analytics hooks,
                // set date_paid, reduce stock, and fire woocommerce_payment_complete.
                $this->update_payment_method_title($order, $session);
                $order->add_order_note(__('Briqpay: Payment confirmed via side-channel.', 'briqpay-for-woocommerce'));
                $order->payment_complete($session_id);
                $this->log('Order payment_complete() called for order: ' . $order->get_id());
                return;

            case 'cancelled':
                $new_wc_status = 'cancelled';
                $status_note = __('Briqpay: Session cancelled.', 'briqpay-for-woocommerce');
                break;

            case 'failed':
                $new_wc_status = 'failed';
                $status_note = __('Briqpay: Session failed.', 'briqpay-for-woocommerce');
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
     * Get Order by Session ID
     */
    private function get_order_by_session_id($session_id)
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $orders = wc_get_orders(array(
            'meta_key' => '_briqpay_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'limit' => 1,
        ));
        return !empty($orders) ? reset($orders) : null;
    }

    /**
     * Handle Order Status
     */
    private function handle_order_status($order, $status, $data)
    {
        switch ($status) {
            case 'order_pending':
                $order->update_status('pending', __('Briqpay: Order pending.', 'briqpay-for-woocommerce'));
                break;
            case 'order_approved_not_captured':
                $order->update_status('processing', __('Briqpay: Order approved, ready for capture.', 'briqpay-for-woocommerce'));

                // Update payment method title from session
                $this->update_payment_method_title($order);
                break;
            case 'order_rejected':
            case 'order_cancelled':
                // translators: %s: order status
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
            $order->set_created_via('Briqpay');
            $order->update_meta_data('_created_via', 'Briqpay');
            $order->update_meta_data('_order_origin', 'Briqpay');
            // WC Order Attribution
            $order->update_meta_data('_wc_order_attribution_source_type', 'utm');
            $order->update_meta_data('_wc_order_attribution_utm_source', 'Briqpay');

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
    private function handle_capture_status($order, $status, $data)
    {
        if ('approved' === $status && !empty($data['captureId'])) {
            $capture_id = $data['captureId'];
            $captures = $order->get_meta('_briqpay_captures') ?: array();

            // Deduplicate: If this captureId is already recorded, skip
            if (in_array($capture_id, $captures)) {
                $this->log('Capture ' . $capture_id . ' already recorded. Skipping.');
                return;
            }

            // translators: %s: capture ID
            $order->add_order_note(sprintf(__('Briqpay: Capture approved. ID: %s', 'briqpay-for-woocommerce'), $capture_id));

            // Store capture ID for potential refunds
            $captures[] = $capture_id;
            $order->update_meta_data('_briqpay_captures', $captures);

            // Populate history for Admin Box visibility
            $history = $order->get_meta('_briqpay_capture_history') ?: array();
            $capture_data = $data['capture'] ?? array();
            $history[] = array(
                'captureId' => $capture_id,
                'date' => current_time('mysql'),
                'amount' => $capture_data['amountIncVat'] ?? ($data['amountIncVat'] ?? 0),
                'items' => $capture_data['cart'] ?? array(),
            );
            $order->update_meta_data('_briqpay_capture_history', $history);

            $order->save();
        }
    }

    /**
     * Handle Refund Status
     */
    private function handle_refund_status($order, $status, $data)
    {
        if ('approved' === $status) {
            $note = __('Briqpay: Refund approved.', 'briqpay-for-woocommerce');
            if (!empty($data['refundId'])) {
                $note .= sprintf(' ID: %s', $data['refundId']);
            }
            $order->add_order_note($note);
        }
    }
}
