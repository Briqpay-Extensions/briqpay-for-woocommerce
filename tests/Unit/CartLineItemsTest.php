<?php

namespace Briqpay\WooCommerce\Tests\Unit;

use Briqpay\WooCommerce\Checkout_Handler;
use PHPUnit\Framework\TestCase;
use WP_Mock;
use Mockery;

/**
 * Line items the plugin builds at the decision must look, to any plugin
 * listening on woocommerce_checkout_create_order_line_item, exactly like the
 * ones WC_Checkout::create_order_line_items() builds.
 *
 * Product Add-Ons Ultimate (product_extras) and Discount Rules for WooCommerce
 * read $item->legacy_values on that action. The plugin never set it, so
 * add-ons and per-item rule details were missing from every Briqpay-built
 * order (about 3,000 items at Swemed SE).
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
class CartLineItemsTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        \Briqpay_Test_Actions::reset();
        WP_Mock::userFunction('__', array('return_arg' => 0));

        // Stand-in for WC_Order_Item_Product that records what is done to it.
        // __set() also notes how many actions had fired when a property was
        // assigned, so a test can prove it happened before the line-item action.
        if (!class_exists('WC_Order_Item_Product', false)) {
            eval('class WC_Order_Item_Product {
                public $props = array();
                public $calls = array();
                public $assigned_at = array();
                public function __set($name, $value) {
                    $this->props[$name] = $value;
                    $this->assigned_at[$name] = count(\Briqpay_Test_Actions::$fired);
                }
                public function __get($name) { return $this->props[$name] ?? null; }
                public function __isset($name) { return isset($this->props[$name]); }
                public function __call($name, $args) { $this->calls[] = array($name, $args); }
            }');
        }
    }

    public function tearDown(): void
    {
        \Briqpay_Test_Options::reset();
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function hooksEnabled($enabled)
    {
        // The bootstrap's get_option() reads this store; a WP_Mock userFunction
        // cannot replace a function that already exists.
        \Briqpay_Test_Options::$store['woocommerce_briqpay_settings'] = array('checkout_hooks_enabled' => $enabled ? 'yes' : 'no', 'logging' => 'no');
    }

    private function product($id, $type = 'simple', $parent = 0, $sku = '')
    {
        $p = Mockery::mock('WC_Product');
        $p->shouldReceive('get_name')->andReturn('Product ' . $id);
        $p->shouldReceive('get_type')->andReturn($type);
        $p->shouldReceive('get_id')->andReturn($id);
        $p->shouldReceive('get_parent_id')->andReturn($parent);
        $p->shouldReceive('get_tax_class')->andReturn('');
        $p->shouldReceive('get_sku')->andReturn($sku);
        return $p;
    }

    private function cartItem($product, array $extra = array())
    {
        return array_merge(array(
            'data' => $product,
            'quantity' => 1,
            'variation' => array(),
            'line_subtotal' => 90.0,
            'line_total' => 81.0,
            'line_subtotal_tax' => 5.4,
            'line_tax' => 4.86,
            'line_tax_data' => array('subtotal' => array(2 => 5.4), 'total' => array(2 => 4.86)),
        ), $extra);
    }

    private function run_items(array $cart)
    {
        $order = Mockery::mock('WC_Order');
        $added = array();
        $order->shouldReceive('add_item')->andReturnUsing(function ($item) use (&$added) {
            $added[] = $item;
        });

        $method = new \ReflectionMethod(Checkout_Handler::class, 'add_cart_line_items');
        $method->setAccessible(true);
        $method->invoke(new Checkout_Handler(), $order, $cart);

        return $added;
    }

    private function lineItemActions()
    {
        return array_values(array_filter(\Briqpay_Test_Actions::$fired, function ($f) {
            return $f['tag'] === 'woocommerce_checkout_create_order_line_item';
        }));
    }

    private function actionIndex($n)
    {
        $seen = 0;
        foreach (\Briqpay_Test_Actions::$fired as $i => $f) {
            if ($f['tag'] === 'woocommerce_checkout_create_order_line_item' && $seen++ === $n) {
                return $i;
            }
        }
        return -1;
    }

    public function provideGate()
    {
        return array('checkout hooks off (default for stores not opted in)' => array(false), 'checkout hooks on' => array(true));
    }

    /**
     * @dataProvider provideGate
     */
    public function testAddOnPluginsSeeTheCartItemOnTheLineItem($hooks_on): void
    {
        $this->hooksEnabled($hooks_on);
        $extras = array('groups' => array(array('label' => 'Gravyr', 'value' => 'Hej')));
        $cart = array(
            'key-a' => $this->cartItem($this->product(416, 'simple', 0, 'QA-REDUCED'), array('product_extras' => $extras)),
            'key-b' => $this->cartItem($this->product(159)),
        );

        $added = $this->run_items($cart);

        $this->assertCount(2, $added);
        $actions = $this->lineItemActions();
        $this->assertCount(2, $actions);
        foreach (array('key-a', 'key-b') as $n => $key) {
            $item = $actions[$n]['args'][0];
            $this->assertSame($added[$n], $item, 'The item the action sees is the one added to the order.');
            $this->assertSame($cart[$key], $item->legacy_values, 'legacy_values must be the full cart item, as core sets it.');
            $this->assertSame($key, $item->legacy_cart_item_key);
            $this->assertLessThanOrEqual($this->actionIndex($n), $item->assigned_at['legacy_values'], 'Set before the action, where plugins read it.');
            $this->assertLessThanOrEqual($this->actionIndex($n), $item->assigned_at['legacy_cart_item_key']);
        }
        $this->assertSame($extras, $actions[0]['args'][0]->legacy_values['product_extras']);
    }

    /**
     * Core stores attributes through set_variation(), which drops the
     * "attribute_" prefix ("farg", "pa_color"). The raw cart keys were stored
     * before, so Briqpay orders showed "attribute_farg".
     */
    public function testVariationAttributesAreStoredTheWayWooCommerceStoresThem(): void
    {
        $this->hooksEnabled(false);
        $variation = array('attribute_farg' => 'Vit', 'attribute_pa_size' => 'L');
        $added = $this->run_items(array(
            'key-v' => $this->cartItem($this->product(302, 'variation', 97, '4213asd'), array('variation' => $variation)),
        ));

        $calls = $added[0]->calls;
        $this->assertContains(array('set_variation', array($variation)), $calls);
        $this->assertContains(array('set_product_id', array(97)), $calls);
        $this->assertContains(array('set_variation_id', array(302)), $calls);
        foreach ($calls as $call) {
            if ($call[0] === 'add_meta_data') {
                $this->assertStringStartsNotWith('attribute_', $call[1][0], 'Attributes must not be stored under their raw cart key.');
            }
        }
        $this->assertContains(array('add_meta_data', array('_briqpay_item_reference', '4213asd-9000')), $calls);
    }

    public function testASimpleProductGetsNoVariationMeta(): void
    {
        $this->hooksEnabled(false);
        $added = $this->run_items(array('key-a' => $this->cartItem($this->product(416))));

        $this->assertNotContains('set_variation', array_column($added[0]->calls, 0));
    }
}
