<?php
namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\B2b_Checkout;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

class B2BCheckoutTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private $b2b;
    public static $wc_return = null;
    public static $wc_received_return = false;
    public static $ajax_return = false;
    public static $referer_return = '';

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        self::$wc_return = null;
        self::$wc_received_return = false;
        self::$ajax_return = false;
        self::$referer_return = '';

        // Dynamic mocks
        WP_Mock::userFunction('WC', array(
            'return' => function () {
                return self::$wc_return;
            }
        ));
        WP_Mock::userFunction('is_order_received_page', array(
            'return' => function () {
                return self::$wc_received_return;
            }
        ));
        WP_Mock::userFunction('wp_doing_ajax', array(
            'return' => function () {
                return self::$ajax_return;
            }
        ));
        WP_Mock::userFunction('wp_get_raw_referer', array(
            'return' => function () {
                return self::$referer_return;
            }
        ));

        // Static mocks
        WP_Mock::userFunction('is_singular', array('return' => false));
        WP_Mock::userFunction('has_shortcode', array('return' => false));
        WP_Mock::userFunction('__', array('return_arg' => 0));
        WP_Mock::userFunction('apply_filters', array('return_arg' => 1));

        $post = new \stdClass();
        $post->post_content = '';
        WP_Mock::userFunction('get_post', array('return' => $post));

        $this->b2b = new B2b_Checkout();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * Helper to mock WC Session
     */
    private function mockWCSession($active)
    {
        $session = Mockery::mock('WC_Session');
        $session->shouldReceive('get')->with('briqpay_b2b_active')->andReturn($active);

        $wc = Mockery::mock('WooCommerce');
        $wc->session = $session;

        self::$wc_return = $wc;
    }

    /**
     * Test B2B detection via Session
     */
    public function testIsB2bActiveWithSession()
    {
        $this->mockWCSession(true);

        $reflection = new \ReflectionClass($this->b2b);
        $method = $reflection->getMethod('is_b2b_active');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->b2b));
    }

    /**
     * Test B2B detection via Cookie fallback
     */
    public function testIsB2bActiveWithCookie()
    {
        $_COOKIE['briqpay_b2b_active'] = '1';
        self::$wc_return = null;

        $reflection = new \ReflectionClass($this->b2b);
        $method = $reflection->getMethod('is_b2b_active');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->b2b));
        unset($_COOKIE['briqpay_b2b_active']);
    }

    /**
     * Test B2B detection via AJAX Referer
     */
    public function testIsB2bActiveWithAjaxReferer()
    {
        self::$wc_return = null;
        self::$ajax_return = true;
        self::$referer_return = 'https://example.com/b2b-checkout/';

        $reflection = new \ReflectionClass($this->b2b);
        $method = $reflection->getMethod('is_b2b_active');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->b2b));
    }

    /**
     * Test that session regeneration IS forced on initial page load
     */
    public function testForceNewSessionOnPageLoad()
    {
        $this->mockWCSession(true);
        self::$ajax_return = false;

        $this->assertTrue($this->b2b->force_new_session(false));
    }

    /**
     * Test that session regeneration IS BLOCKED during AJAX
     */
    public function testForceNewSessionBlocksOnAjax()
    {
        $this->mockWCSession(true);
        self::$ajax_return = true;

        // Even if inherited value is TRUE, B2B AJAX MUST return FALSE
        $this->assertFalse($this->b2b->force_new_session(true));
    }

    /**
     * Test Session Data Filter on initial creation
     */
    public function testFilterB2bSessionDataOnCreate()
    {
        $this->mockWCSession(true);

        $data = array();
        $result = $this->b2b->filter_b2b_session_data($data, false);

        $this->assertArrayHasKey('customerType', $result);
        $this->assertEquals('business', $result['customerType']);
        $this->assertContains('company_lookup', $result['modules']['loadModules']);
    }

    /**
     * Test Session Data Filter on update (PATCH) purges restricted fields
     */
    public function testFilterB2bSessionDataOnUpdatePurgesFields()
    {
        $this->mockWCSession(true);

        $data = array(
            'customerType' => 'business',
            'modules' => array('...'),
            'amount' => 100,
            'country' => 'SE',
            'locale' => 'sv-se'
        );
        $result = $this->b2b->filter_b2b_session_data($data, true);

        $this->assertArrayNotHasKey('customerType', $result);
        $this->assertArrayNotHasKey('modules', $result);
        $this->assertArrayNotHasKey('country', $result);
        $this->assertArrayNotHasKey('locale', $result);
        $this->assertEquals(100, $result['amount']);
    }
}
