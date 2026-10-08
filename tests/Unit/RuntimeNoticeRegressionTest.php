<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Gateway;
use Briqpay\WooCommerce\Session_Manager;
use PHPUnit\Framework\TestCase;

/**
 * PHP warnings seen in the web server log while exercising a checkout. None
 * broke a purchase, but each was a real defect: an undefined variable, a
 * wrong argument, a loop over a string that then cleared the chosen shipping,
 * and a dynamic property that PHP 8.2+ deprecates.
 */
class RuntimeNoticeRegressionTest extends TestCase
{
    private function methodSource($class, $name)
    {
        $method = new \ReflectionMethod($class, $name);
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /**
     * With nothing in the WC session, $order was never assigned before
     * "if (!$order)" and "if ($order)" read it.
     */
    public function testTheOrderVariableIsInitialisedBeforeTheDraftLookups(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'create_order_at_decision');

        $init = strpos($source, '$order = null;');
        $first_read = strpos($source, 'if (!$order) {');

        $this->assertNotFalse($init);
        $this->assertNotFalse($first_read);
        $this->assertLessThan($first_read, $init);
    }

    /**
     * The hash helper was handed an undefined $session_id; the new session's
     * id is the one the briqpay_update_session_data filter should receive.
     */
    public function testTheStoredPayloadHashIsBuiltForTheNewSession(): void
    {
        $source = $this->methodSource(Session_Manager::class, 'create_session');

        $this->assertStringContainsString("store_update_payload_hash(\$session['sessionId'])", $source);
        $this->assertStringNotContainsString('store_update_payload_hash($session_id)', $source);
    }

    /**
     * Blocks sends shipping_rates in several shapes. A string raised a warning
     * and then overwrote the chosen shipping methods with an empty array.
     */
    public function testBlocksShippingRatesAreOnlyAppliedWhenTheyAreAnArray(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_get_session');

        $this->assertStringContainsString("isset(\$blocks_data['shipping_rates']) && is_array(\$blocks_data['shipping_rates'])", $source);
    }

    public function testGatewayDeclaresVerboseLogging(): void
    {
        $this->assertTrue((new \ReflectionClass(Gateway::class))->hasProperty('verbose_logging'));
    }
}
