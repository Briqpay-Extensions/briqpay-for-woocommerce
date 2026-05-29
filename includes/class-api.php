<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay API Class
 */
class API
{

    /**
     * @var string
     */
    private $mid;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var bool
     */
    private $testmode;

    /**
     * Constructor
     */
    public function __construct($mid, $secret, $testmode = true)
    {
        $this->mid = $mid;
        $this->secret = $secret;
        $this->testmode = $testmode;
    }

    /**
     * Get API URL
     */
    private function get_api_url()
    {
        return $this->testmode ? 'https://playground-api.briqpay.com' : 'https://api.briqpay.com';
    }

    /**
     * Request
     */
    public function request($method, $endpoint, $body = array())
    {
        $url = $this->get_api_url() . $endpoint;
        $this->log("Requesting ($method): $url");
        $this->log("MID Check: " . substr($this->mid, 0, 5) . "...");

        if (!empty($body)) {
            $this->log("Payload: " . wp_json_encode($body));
        }

        $auth = base64_encode($this->mid . ':' . $this->secret);

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => sprintf(
                    'Briqpay Payment Module WooCommerce: %s - PHP: %s - Wordpress: %s - WooCommerce: %s',
                    BRIQPAY_WC_VERSION,
                    PHP_VERSION,
                    $GLOBALS['wp_version'],
                    class_exists('WooCommerce') ? WC()->version : 'N/A'
                ),
            ),
            'timeout' => 30,
        );

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log('API Error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $msg = $data['message'] ?? 'API Error ' . $code;
            $this->log("API Error ($code): " . (is_array($data) ? wp_json_encode($data) : $data));
            return new \WP_Error($data['code'] ?? 'api_error', $msg, $data);
        }

        /**
         * Filter the API response before it is returned.
         *
         * @param array|null $data     Decoded response body.
         * @param string     $method   HTTP method (GET, POST, PATCH).
         * @param string     $endpoint API endpoint path.
         * @param int        $code     HTTP response code.
         */
        $data = apply_filters('briqpay_api_response', $data, $method, $endpoint, $code);

        return $data;
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
     * Create Session
     */
    public function create_session($data)
    {
        return $this->request('POST', '/v3/session', $data);
    }

    /**
     * Update Session
     */
    public function update_session($session_id, $data)
    {
        return $this->request('PATCH', '/v3/session/' . $session_id, $data);
    }

    /**
     * Get Session
     */
    public function get_session($session_id)
    {
        return $this->request('GET', '/v3/session/' . $session_id);
    }

    /**
     * Capture Order
     */
    public function capture_order($session_id, $data)
    {
        return $this->request('POST', '/v3/session/' . $session_id . '/order/capture', $data);
    }

    /**
     * Refund Order
     */
    public function refund_order($session_id, $data)
    {
        return $this->request('POST', '/v3/session/' . $session_id . '/order/refund', $data);
    }

    /**
     * Make Decision
     */
    public function make_decision($session_id, $decision = 'allow')
    {
        return $this->request('POST', '/v3/session/' . $session_id . '/decision', array(
            'decision' => $decision
        ));
    }

    /**
     * Update Metadata
     */
    public function update_metadata($session_id, $data)
    {
        return $this->request('PATCH', '/v3/session/' . $session_id . '/metadata', $data);
    }

    /**
     * Cancel Order
     */
    public function cancel_order($session_id)
    {
        return $this->request('POST', '/v3/session/' . $session_id . '/order/cancel');
    }
}
