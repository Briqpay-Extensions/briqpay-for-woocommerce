<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use Briqpay\WooCommerce\Hosted_Payment_Page;
use PHPUnit\Framework\TestCase;

/**
 * The reference the plugin stamps on a Briqpay session is the order NUMBER, not
 * the order ID.
 *
 * WC_Order::get_order_number() is what the merchant and the customer see on the
 * order; a sequential-order-number plugin changes it, and it falls back to the ID
 * by itself when no such plugin is installed. The storefront flow used to send
 * get_id() while hosted payment pages already sent get_order_number(), so the
 * same order could carry two different references in Briqpay's backoffice.
 */
class OrderReferenceTest extends TestCase
{
    private function methodSource($class, $name)
    {
        $method = new \ReflectionMethod($class, $name);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    public function testTheDecisionStampsTheOrderNumberOnTheSession(): void
    {
        $source = $this->methodSource(Checkout_Handler::class, 'ajax_make_decision');

        $this->assertStringContainsString(
            "'reference1' => (string) \$order->get_order_number()",
            $source,
            'The session reference must be the order number.'
        );
        $this->assertStringNotContainsString(
            "'reference1' => (string) \$order->get_id()",
            $source,
            'The order ID is not what the merchant sees on the order.'
        );
    }

    /**
     * Both entry points must agree, or one order carries two references.
     */
    public function testHostedPagesSendTheSameReference(): void
    {
        $source = $this->methodSource(Hosted_Payment_Page::class, 'build_session_payload');

        $this->assertStringContainsString("'reference1' => (string) \$order->get_order_number()", $source);
    }
}
