<?php
namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $code;
            public $message;
            public $data;
            public function __construct($code = '', $message = '', $data = '') {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }
            public function get_error_code() { return $this->code; }
            public function get_error_message() { return $this->message; }
            public function get_error_data() { return $this->data; }
        }
    }
}

namespace Briqpay\WooCommerce\Tests\Unit {

use Briqpay\WooCommerce\API;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class ApiHostedPageTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('is_wp_error', array(
            'return' => function ($thing) {
                return $thing instanceof \WP_Error;
            },
        ));
        WP_Mock::userFunction('wp_json_encode', array(
            'return' => function ($data) {
                return json_encode($data);
            },
        ));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function testCreateHostedPagePostsToV3HostedPageInTestMode()
    {
        $api = new API('mid', 'secret', true);

        $success_body = json_encode(array(
            'hostedPageId' => 'hp-1',
            'sessionId' => 'sess-1',
            'url' => 'https://hp.briqpay.com/payment/hp-1/tok-1',
        ));

        $captured_url = null;
        $captured_args = null;

        WP_Mock::userFunction('wp_remote_request', array(
            'return' => function ($url, $args) use (&$captured_url, &$captured_args, $success_body) {
                $captured_url = $url;
                $captured_args = $args;
                return array(
                    'response' => array('code' => 200),
                    'body' => $success_body,
                );
            },
        ));
        WP_Mock::userFunction('wp_remote_retrieve_response_code', array('return' => 200));
        WP_Mock::userFunction('wp_remote_retrieve_body', array('return' => $success_body));

        $result = $api->create_hosted_page(array(
            'sessionId' => 'sess-1',
            'config' => array('showCart' => true),
        ));

        $this->assertEquals('https://playground-api.briqpay.com/v3/hosted-page', $captured_url);
        $this->assertEquals('POST', $captured_args['method']);
        $this->assertEquals('Basic ' . base64_encode('mid:secret'), $captured_args['headers']['Authorization']);
        $this->assertEquals(
            array('sessionId' => 'sess-1', 'config' => array('showCart' => true)),
            json_decode($captured_args['body'], true)
        );

        $this->assertEquals('https://hp.briqpay.com/payment/hp-1/tok-1', $result['url']);
    }

    public function testCreateHostedPagePostsToV3HostedPageInLiveMode()
    {
        $api = new API('mid', 'secret', false);

        $captured_url = null;
        WP_Mock::userFunction('wp_remote_request', array(
            'return' => function ($url, $args) use (&$captured_url) {
                $captured_url = $url;
                return array(
                    'response' => array('code' => 200),
                    'body' => json_encode(array('sessionId' => 'sess-1', 'url' => 'https://hp.briqpay.com/x')),
                );
            },
        ));
        WP_Mock::userFunction('wp_remote_retrieve_response_code', array('return' => 200));
        WP_Mock::userFunction('wp_remote_retrieve_body', array('return' => json_encode(array('sessionId' => 'sess-1', 'url' => 'https://hp.briqpay.com/x'))));

        $api->create_hosted_page(array('sessionId' => 'sess-1'));

        $this->assertEquals('https://api.briqpay.com/v3/hosted-page', $captured_url);
    }

    public function testCreateHostedPageReturnsWpErrorOn400()
    {
        $api = new API('mid', 'secret', true);

        $error_body = json_encode(array(
            'error' => array('code' => 'INVALID_DATA', 'message' => 'sessionId is required'),
        ));

        WP_Mock::userFunction('wp_remote_request', array(
            'return' => array(
                'response' => array('code' => 400),
                'body' => $error_body,
            ),
        ));
        WP_Mock::userFunction('wp_remote_retrieve_response_code', array('return' => 400));
        WP_Mock::userFunction('wp_remote_retrieve_body', array('return' => $error_body));

        $result = $api->create_hosted_page(array());

        $this->assertTrue(is_wp_error($result));
    }
}

}
