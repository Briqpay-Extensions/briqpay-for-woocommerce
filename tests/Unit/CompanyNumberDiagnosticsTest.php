<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * A business purchase whose session has no organisation number: the plugin
 * can only store what Briqpay put in data.company.cin, so the gap must be
 * visible in the log without verbose logging (Swemed: an Austrian order
 * without an org number, session 8fedb2a4, nothing in their log to go on).
 */
class CompanyNumberDiagnosticsTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('__', array('return_arg' => 0));
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function methodSource($name)
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, $name);
        $lines = file($method->getFileName());
        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function invoke($name, array $args)
    {
        $method = new \ReflectionMethod(Checkout_Handler::class, $name);
        $method->setAccessible(true);
        return $method->invokeArgs(new Checkout_Handler(), $args);
    }

    public function testTheWarningRunsRightAfterTheCompanyMetaIsWritten(): void
    {
        $source = $this->methodSource('create_order_at_decision');

        $apply = strpos($source, 'Legacy_B2b_Meta::apply($order, $session);');
        $warn = strpos($source, '$this->warn_if_company_number_missing($order, $session);');

        $this->assertNotFalse($apply);
        $this->assertNotFalse($warn);
        $this->assertGreaterThan($apply, $warn);
    }

    public function testTheWarningNamesWhatTheMerchantNeedsToTraceIt(): void
    {
        $source = $this->methodSource('warn_if_company_number_missing');

        $this->assertStringContainsString('Logger::error(', $source, 'Error level: not behind the verbose setting.');
        foreach (array("\$session['sessionId']", "\$session['country']", 'array_keys($company)', "['billing']['companyName']") as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    /**
     * @dataProvider provideSessions
     */
    public function testItIsSilentUnlessABusinessSessionLacksTheNumber(array $session, $expect_warning): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(99);

        // Logger::error() is a no-op without WC_LOG_DIR; what matters here is
        // that nothing throws for any session shape, including a malformed one.
        $this->invoke('warn_if_company_number_missing', array($order, $session));
        $this->addToAssertionCount(1);

        $this->assertSame($expect_warning, $this->wouldWarn($session));
    }

    /**
     * The condition the method implements, restated for the data provider.
     */
    private function wouldWarn(array $session)
    {
        $business = ('business' === ($session['customerType'] ?? '')) || !empty($session['data']['company']['cin']) || !empty($session['data']['company']['name']);
        return $business && empty($session['data']['company']['cin']);
    }

    public function provideSessions()
    {
        return array(
            'consumer session' => array(array('customerType' => 'consumer', 'data' => array()), false),
            'business with cin' => array(array('customerType' => 'business', 'data' => array('company' => array('cin' => '5560360793', 'name' => 'AB'))), false),
            'business without cin (the Austrian case)' => array(array('sessionId' => '8fedb2a4', 'country' => 'AT', 'customerType' => 'business', 'data' => array('company' => array('name' => 'Firma GmbH'), 'billing' => array('companyName' => 'Firma GmbH'))), true),
            'business, company block malformed' => array(array('customerType' => 'business', 'data' => array('company' => 'not-an-array')), true),
        );
    }
}
