<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Briqpay Session Manager
 */
class Session_Manager
{
    /**
     * Cache for product image URLs.
     *
     * @var array
     */
    private static $image_cache = array();

    /**
     * Cache for tax rates.
     *
     * @var array
     */
    private static $tax_rate_cache = array();


    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialization
    }

    /**
     * Get Briqpay Session ID from WC Session
     */
    public static function get_session_id()
    {
        $session_id = (null !== WC() && null !== WC()->session) ? WC()->session->get('briqpay_session_id') : null;

        // Fail-safe: recover from cookie if session is lost
        if (!$session_id && isset($_COOKIE['briqpay_session_id'])) {
            $session_id = sanitize_text_field(wp_unslash($_COOKIE['briqpay_session_id']));
        }

        return $session_id;
    }

    /**
     * Set Briqpay Session ID in WC Session
     */
    public static function set_session_id($session_id)
    {
        if (null !== WC() && null !== WC()->session) {
            WC()->session->set('briqpay_session_id', $session_id);
        }

        if ($session_id) {
            setcookie('briqpay_session_id', $session_id, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        } else {
            setcookie('briqpay_session_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    /**
     * Create or Update Briqpay Session
     */
    public function get_or_create_session()
    {
        Logger::log('get_or_create_session() entered.');

        $force_new_input = false;
        $force_new = apply_filters('briqpay_force_new_session', $force_new_input);
        if ($force_new) {
            Logger::log(sprintf('Forcing new session via filter briqpay_force_new_session: TRUE (Input was: %s)', $force_new_input ? 'TRUE' : 'FALSE'));
            self::set_session_id(null);
        } else {
            Logger::log('briqpay_force_new_session filter: FALSE');
        }

        $session_id = self::get_session_id();
        $type_changed = $this->has_customer_type_changed();

        Logger::log(sprintf(
            'Session Stats - ID: %s, Force New: %s, Type Changed: %s',
            $session_id ?: 'NONE',
            $force_new ? 'YES' : 'NO',
            $type_changed ? 'YES' : 'NO'
        ));

        if ($force_new || $type_changed || !$session_id) {
            if (!$session_id) {
                Logger::log('No session ID found or forced new, creating new one.');
            }
            return $this->create_session();
        }

        Logger::log('Found existing session: ' . $session_id);
        $api = $this->get_api();

        // Check if session is still valid/exists
        $session = $api->get_session($session_id);
        if (!is_wp_error($session) && !empty($session['sessionId']) && $session['status'] !== 'completed') {
            Logger::log('Existing session is not completed, updating...');
            // Update session with latest cart
            $updated_session = $this->update_session($session_id, $session);
            if (is_wp_error($updated_session)) {
                Logger::log('Update failed: ' . $updated_session->get_error_message());
                return $session; // Fallback to old one if update failed
            }
            return $updated_session;
        }

        Logger::log('Existing session invalid, expired, or completed. Creating new one.');
        return $this->create_session();
    }

    /**
     * Create a new Briqpay session
     */
    private function create_session()
    {
        Logger::log('Creating new session...');
        $api = $this->get_api();
        $data = $this->get_session_data();

        /**
         * Filter the session data before creating a new session.
         *
         * @param array $data Full session payload.
         */
        $data = apply_filters('briqpay_create_session_data', $data);

        /**
         * Action before a new Briqpay session is created.
         *
         * @param array $data Session payload.
         */
        do_action('briqpay_before_create_session', $data);

        $session = $api->create_session($data);

        if (is_wp_error($session)) {
            Logger::log('Error creating session: ' . $session->get_error_message());
        } elseif (!empty($session['sessionId'])) {
            Logger::log('Session created: ' . $session['sessionId']);
            self::set_session_id($session['sessionId']);

            /**
             * Action after a new Briqpay session is created.
             *
             * @param array $session  API response.
             * @param array $data     Session payload that was sent.
             */
            do_action('briqpay_after_create_session', $session, $data);
        } else {
            Logger::log('Session creation returned unexpected data.');
        }

        return $session;
    }

    /**
     * Update Session
     */
    public function update_session($session_id, $existing_session = null)
    {
        $api = $this->get_api();
        $data = $this->get_session_data(true); // Partial update data

        /**
         * Filter the update payload before PATCH.
         *
         * @param array  $data       Update payload.
         * @param string $session_id Briqpay session ID.
         */
        $data = apply_filters('briqpay_update_session_data', $data, $session_id);

        // Compute hash
        $new_hash = md5(wp_json_encode($data));
        $stored_hash = null !== WC()->session ? WC()->session->get('briqpay_payload_hash') : null;

        if ($stored_hash === $new_hash && $existing_session !== null && !is_wp_error($existing_session)) {
            Logger::log('Skipping PATCH request: payload hash unchanged.');
            return $existing_session;
        }

        /**
         * Action before a Briqpay session is updated.
         *
         * @param string $session_id Briqpay session ID.
         * @param array  $data       Update payload.
         */
        do_action('briqpay_before_update_session', $session_id, $data);

        $result = $api->update_session($session_id, $data);

        // If update was successful, optionally fetch the full session to get the latest htmlSnippet
        if (!is_wp_error($result) && isset($result['sessionId'])) {
            if (null !== WC()->session) {
                WC()->session->set('briqpay_payload_hash', $new_hash);
            }

            if (empty($result['htmlSnippet'])) {
                Logger::log('Update successful, fetching full session to ensure latest snippet.');
                $result = $api->get_session($session_id);
            } else {
                Logger::log('Update successful, using snippet from PATCH response.');
            }
        }

        /**
         * Action after a Briqpay session is updated.
         *
         * @param array|WP_Error $result     API response.
         * @param string         $session_id Briqpay session ID.
         */
        do_action('briqpay_after_update_session', $result, $session_id);

        return $result;
    }

    /**
     * Get API Instance
     */
    private function get_api()
    {
        $settings = get_option('woocommerce_briqpay_settings');
        return new API($settings['merchant_id'], $settings['shared_secret'], 'yes' === $settings['testmode']);
    }

    /**
     * Prepare Session Data
     */
    public function get_session_data($update = false)
    {
        Logger::log('get_session_data() started. Update: ' . ($update ? 'yes' : 'no'));

        if (!function_exists('WC') || null === WC() || null === WC()->cart) {
            Logger::log('Error: WC()->cart is not available.');
            return array();
        }

        try {
            Logger::log('Building order data...');
            $cart_items = $this->get_cart_items();

            // Calculate totals from items to ensure perfect match with Briqpay validation
            $sum_inc = 0;
            $sum_ex = 0;
            foreach ($cart_items as $item) {
                // sales_tax productType has totalTaxAmount instead of unitPrice/totalAmount
                if ('sales_tax' === ($item['productType'] ?? '')) {
                    $sum_inc += $item['totalTaxAmount'];
                    continue;
                }

                $item_qty = isset($item['quantity']) ? $item['quantity'] : 1;
                // Briqpay calculates total as (unitPrice * quantity) + (sum of totalVatAmount)
                // However, they also validate that (unitPrice * quantity) == totalAmount - totalVatAmount
                $sum_ex += $item['unitPrice'] * $item_qty;
                $sum_inc += $item['totalAmount'];
            }

            Logger::log(sprintf('Final Summed Totals: Inc=%d, Ex=%d', $sum_inc, $sum_ex));

            $data = array(
                'data' => array(
                    'order' => array(
                        'currency' => get_woocommerce_currency(),
                        'amountIncVat' => $sum_inc,
                        'amountExVat' => $sum_ex,
                        'cart' => $cart_items,
                    ),
                ),
            );

            // Only include billing/shipping if data exists
            $billing = $this->get_billing_address();
            if ($billing !== null) {
                $data['data']['billing'] = $billing;
            }

            $shipping = $this->get_shipping_address();
            if ($shipping !== null) {
                $data['data']['shipping'] = $shipping;
            }

            // Handle B2B Company/Billing/Shipping Data
            $is_b2b_shortcode = (bool) apply_filters('briqpay_is_b2b_active', (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_b2b_active')));

            // Omit company/billing/shipping data if we are in B2B mode and updating (PATCH)
            // because these modules are active and Briqpay owns the data.
            if ($is_b2b_shortcode && $update) {
                unset($data['data']['billing']);
                unset($data['data']['shipping']);
            }

            if ($this->get_customer_type() === 'business' && !$is_b2b_shortcode) {
                $company_name = (null !== WC()->customer) ? WC()->customer->get_billing_company() : '';
                $data['data']['company'] = array(
                    'name' => $company_name
                );
            }

            if (!$update) {
                Logger::log('Building extra session data (non-update)...');
                $data['product'] = array('type' => 'payment', 'intent' => 'payment_one_time');
                $data['customerType'] = $this->get_customer_type();

                // Country should strictly follow store base or sell-to location
                // If selling to specific countries, use the first one as default if customer hasn't chosen one
                // But user wants it locked to the selling country.
                $base_country = WC()->countries->get_base_country();
                $data['country'] = $base_country ?: 'SE';

                $data['locale'] = $this->get_locale();

                Logger::log('Setting URLs...');
                $data['urls'] = array(
                    'terms' => get_permalink(wc_get_page_id('terms')) ?: get_home_url(),
                    'redirect' => $this->get_redirect_url(),
                );

                Logger::log('Setting hooks...');
                $data['hooks'] = $this->get_webhooks();
                $data['modules'] = array(
                    'loadModules' => array('payment'),
                    'config' => array(
                        'payment' => array(
                            'decision' => array(
                                'enabled' => true
                            )
                        )
                    )
                );
            }

            Logger::log('get_session_data() completed.');

            /**
             * Filter the complete session data before it is used.
             *
             * @param array $data   Session data array.
             * @param bool  $update Whether this is an update (true) or create (false).
             */
            $data = apply_filters('briqpay_session_data', $data, $update);

            return $data;
        } catch (\Exception $e) {
            Logger::log('EXCEPTION in get_session_data: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Get Billing Address
     */
    private function get_billing_address()
    {
        if (null === WC()->customer) {
            return null;
        }

        $customer = WC()->customer;

        // Get address fields
        $email = $customer->get_billing_email();
        $address = $customer->get_billing_address_1();
        $city = $customer->get_billing_city();
        $zip = $customer->get_billing_postcode();
        $country = $customer->get_billing_country();

        // Only return address if we have complete address data
        // Require all essential fields: address, city, zip, and country
        if (empty($address) || empty($city) || empty($zip) || empty($country)) {
            return null;
        }

        $billing = array(
            'firstName' => $customer->get_billing_first_name(),
            'lastName' => $customer->get_billing_last_name(),
            'email' => $email,
            'streetAddress' => $address,
            'streetAddress2' => $customer->get_billing_address_2(),
            'zip' => $zip,
            'city' => $city,
            'region' => $customer->get_billing_state(),
            'country' => $country,
            'phoneNumber' => $customer->get_billing_phone(),
        );

        /**
         * Filter the billing address sent to Briqpay.
         *
         * @param array $billing Billing address fields.
         */
        return apply_filters('briqpay_billing_address', $billing);
    }

    /**
     * Get Shipping Address
     */
    private function get_shipping_address()
    {
        if (null === WC()->customer) {
            return null;
        }

        $customer = WC()->customer;

        // Get address fields
        $address = $customer->get_shipping_address_1();
        $city = $customer->get_shipping_city();
        $zip = $customer->get_shipping_postcode();
        $country = $customer->get_shipping_country();

        // Only return address if we have complete address data
        // Require all essential fields: address, city, zip, and country
        if (empty($address) || empty($city) || empty($zip) || empty($country)) {
            return null;
        }

        $shipping = array(
            'firstName' => $customer->get_shipping_first_name(),
            'lastName' => $customer->get_shipping_last_name(),
            'email' => $customer->get_billing_email(),
            'streetAddress' => $address,
            'streetAddress2' => $customer->get_shipping_address_2(),
            'zip' => $zip,
            'city' => $city,
            'region' => $customer->get_shipping_state(),
            'country' => $country,
            'phoneNumber' => $customer->get_billing_phone(),
        );

        /**
         * Filter the shipping address sent to Briqpay.
         *
         * @param array $shipping Shipping address fields.
         */
        return apply_filters('briqpay_shipping_address', $shipping);
    }

    /**
     * Get Cart Items
     */
    private function get_cart_items()
    {
        Logger::log('get_cart_items() started.');
        $items = array();
        $cart = WC()->cart;

        $is_us = (null !== WC()->customer) ? 'US' === WC()->customer->get_billing_country() : false;
        $total_tax_amount_float = 0;

        // Products
        $consolidated_items = array();

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            /** @var \WC_Product $product */
            $product = $cart_item['data'];
            Logger::log('Processing item: ' . (is_object($product) ? $product->get_name() : 'non-object'));

            // Calculate V3 cart fields
            $quantity = $cart_item['quantity'];
            $line_total_exc_tax = $cart_item['line_subtotal'];
            $line_tax = $cart_item['line_subtotal_tax'];
            $line_total_inc_tax = $line_total_exc_tax + $line_tax;

            // Unit price excluding VAT in minor units
            $unit_price = $this->to_int($line_total_exc_tax / $quantity);

            // Tax rate (e.g. 2500 for 25%)
            $tax_rate = $is_us ? 0 : $this->get_tax_rate($product);

            // Unit price including VAT
            $unit_price_inc_vat = $is_us ? $unit_price : $this->to_int($line_total_inc_tax / $quantity);

            // Total VAT amount
            $total_vat_amount = $is_us ? 0 : $this->to_int($line_tax);

            // Total amount including VAT (before discounts)
            $total_amount = $is_us ? ($unit_price * $quantity) : $this->to_int($line_total_inc_tax);

            if ($is_us) {
                $total_tax_amount_float += $line_tax;
            }

            // Get product image URL
            $image_id = $product->get_image_id();
            if (!isset(self::$image_cache[$image_id])) {
                self::$image_cache[$image_id] = wp_get_attachment_image_url($image_id, 'medium');
            }
            $image_url = self::$image_cache[$image_id];

            // Robust unique reference: SKU-ID or just ID
            $sku = $product->get_sku();
            $id = $product->get_id();
            $reference = !empty($sku) ? $sku . '-' . $id : (string) $id;

            if (isset($consolidated_items[$reference])) {
                $consolidated_items[$reference]['quantity'] += $quantity;
                $consolidated_items[$reference]['totalVatAmount'] += $total_vat_amount;
                $consolidated_items[$reference]['totalAmount'] += $total_amount;
            } else {
                $item = array(
                    'productType' => 'physical',
                    'reference' => $reference,
                    'name' => $product->get_name() ?: __('Product', 'briqpay-for-woocommerce'),
                    'quantity' => $quantity,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $unit_price,
                    'taxRate' => $tax_rate,
                    'unitPriceIncVat' => $unit_price_inc_vat,
                    'totalVatAmount' => $total_vat_amount,
                    'totalAmount' => $total_amount,
                );

                // Only add imageUrl if image exists
                if ($image_url) {
                    $item['imageUrl'] = $image_url;
                }
                $consolidated_items[$reference] = $item;
            }
        }

        $items = array_values($consolidated_items);

        // Shipping
        Logger::log('Shipping Total: ' . $cart->get_shipping_total());
        if ($cart->get_shipping_total() > 0) {
            Logger::log('Processing shipping...');
            $ship_total = $cart->get_shipping_total();
            $ship_tax = $cart->get_shipping_tax();

            $items[] = array(
                'productType' => 'shipping_fee',
                'reference' => 'shipping',
                'name' => __('Shipping', 'briqpay-for-woocommerce'),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => $this->to_int($ship_total),
                'taxRate' => $is_us ? 0 : (int) (($ship_total > 0) ? ($ship_tax / $ship_total * 10000) : 0),
                'unitPriceIncVat' => $is_us ? $this->to_int($ship_total) : $this->to_int($ship_total + $ship_tax),
                'totalVatAmount' => $is_us ? 0 : $this->to_int($ship_tax),
                'totalAmount' => $is_us ? $this->to_int($ship_total) : $this->to_int($ship_total + $ship_tax),
            );

            if ($is_us) {
                $total_tax_amount_float += $ship_tax;
            }
        }

        // Fees
        foreach ($cart->get_fees() as $fee) {
            Logger::log('Processing fee: ' . $fee->name);
            $items[] = array(
                'productType' => 'physical',
                'reference' => $fee->id,
                'name' => $fee->name,
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => $this->to_int($fee->total),
                'taxRate' => $is_us ? 0 : (int) (($fee->total > 0) ? ($fee->tax / $fee->total * 10000) : 0),
                'unitPriceIncVat' => $is_us ? $this->to_int($fee->total) : $this->to_int($fee->total + $fee->tax),
                'totalVatAmount' => $is_us ? 0 : $this->to_int($fee->tax),
                'totalAmount' => $is_us ? $this->to_int($fee->total) : $this->to_int($fee->total + $fee->tax),
            );

            if ($is_us) {
                $total_tax_amount_float += $fee->tax;
            }
        }
        // Coupons/Discounts
        foreach ($cart->get_applied_coupons() as $coupon_code) {
            Logger::log('Processing coupon: ' . $coupon_code);
            $discount_amount = (float) $cart->get_coupon_discount_amount($coupon_code);
            $discount_tax = (float) $cart->get_coupon_discount_tax_amount($coupon_code);

            if ($discount_amount > 0 || $discount_tax > 0) {
                // Get the actual tax rate from the first cart item's product
                // instead of deriving it from amounts (which causes precision errors like 25.01%)
                $coupon_tax_rate = 0;
                $cart_contents = $cart->get_cart();
                if (!empty($cart_contents)) {
                    $first_item = reset($cart_contents);
                    if (isset($first_item['data']) && $first_item['data'] instanceof \WC_Product) {
                        $coupon_tax_rate = $this->get_tax_rate($first_item['data']);
                    }
                }

                $items[] = array(
                    'productType' => 'physical',
                    'reference' => 'discount_' . $coupon_code,
                    // translators: %s: coupon code
                    'name' => sprintf(__('Coupon: %s', 'briqpay-for-woocommerce'), $coupon_code),
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $this->to_int($discount_amount * -1),
                    'taxRate' => $is_us ? 0 : $coupon_tax_rate,
                    'unitPriceIncVat' => $is_us ? $this->to_int($discount_amount * -1) : $this->to_int(($discount_amount + $discount_tax) * -1),
                    'totalVatAmount' => $is_us ? 0 : $this->to_int($discount_tax * -1),
                    'totalAmount' => $is_us ? $this->to_int($discount_amount * -1) : $this->to_int(($discount_amount + $discount_tax) * -1),
                );

                if ($is_us) {
                    $total_tax_amount_float -= $discount_tax;
                }
            }
        }


        // Add USA Sales Tax Item
        if ($is_us && $total_tax_amount_float > 0) {
            $items[] = array(
                'productType' => 'sales_tax',
                'name' => __('Sales tax', 'briqpay-for-woocommerce'),
                'reference' => 'sales_tax',
                'totalTaxAmount' => $this->to_int($total_tax_amount_float),
            );
        }

        Logger::log('get_cart_items() completed.');

        /**
         * Filter the cart items sent to Briqpay.
         *
         * @param array $items Cart line items array.
         */
        return apply_filters('briqpay_cart_items', $items);
    }

    /**
     * Map float value to integer (multiplier 100)
     */
    private function to_int($value)
    {
        if (is_string($value)) {
            if (strpos($value, '<span') !== false) {
                $value = wp_strip_all_tags($value);
            }
            // Remove all whitespace including non-breaking spaces
            $value = preg_replace('/\s+|&nbsp;/', '', $value);
            // Handle comma as decimal separator
            $value = str_replace(',', '.', $value);
            // Remove everything except numbers and decimal point
            $value = preg_replace('/[^\d.]/', '', $value);
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * Get Tax Rate in Briqpay format (e.g. 2500 for 25%)
     */
    private function get_tax_rate($product)
    {
        $tax_class = $product->get_tax_class();
        if (!isset(self::$tax_rate_cache[$tax_class])) {
            $tax_rates = \WC_Tax::get_rates($tax_class);
            if (!empty($tax_rates)) {
                $rate = reset($tax_rates);
                self::$tax_rate_cache[$tax_class] = (int) ($rate['rate'] * 100);
            } else {
                self::$tax_rate_cache[$tax_class] = 0;
            }
        }
        return self::$tax_rate_cache[$tax_class];
    }

    /**
     * Get Customer Type
     */
    private function get_customer_type()
    {
        $customer_type = 'consumer';

        // Check if the company name field is required in WooCommerce checkout settings.
        // This covers the standard [woocommerce_checkout] and blocks checkout when the
        // merchant enables "business" (company name required). In that case, force
        // customerType to 'business' even if the field hasn't been filled yet.
        if ($this->is_company_field_required()) {
            $customer_type = 'business';
        } elseif (null !== WC() && null !== WC()->customer) {
            $billing_company = WC()->customer->get_billing_company();
            $customer_type = !empty($billing_company) ? 'business' : 'consumer';
        }

        return apply_filters('briqpay_customer_type', $customer_type);
    }

    /**
     * Check if the WooCommerce billing company field is required.
     *
     * Inspects the WooCommerce checkout field configuration to determine
     * whether the company name field is set as required. Also checks a
     * session flag that can be set by the frontend JS when it detects
     * the company field is required in the DOM.
     *
     * @return bool
     */
    private function is_company_field_required()
    {
        // 1. Check session flag set by frontend JS
        if (null !== WC() && null !== WC()->session && WC()->session->get('briqpay_company_required')) {
            return true;
        }

        // 2. Check WooCommerce checkout field settings
        if (function_exists('WC') && null !== WC()->checkout()) {
            $fields = WC()->checkout()->get_checkout_fields('billing');
            if (isset($fields['billing_company']['required']) && $fields['billing_company']['required']) {
                return true;
            }
        }

        return false;
    }


    /**
     * Check if customer type has changed
     */
    private function has_customer_type_changed()
    {
        if (null === WC() || null === WC()->session) {
            return false;
        }

        $current_type = (string) $this->get_customer_type();
        $previous_type = (string) WC()->session->get('briqpay_customer_type');

        $current_b2b_active = (bool) apply_filters('briqpay_is_b2b_active', (bool) WC()->session->get('briqpay_b2b_active'));
        $previous_b2b_active = WC()->session->get('briqpay_prev_b2b_active');

        Logger::log(sprintf(
            'Type Check - Current: %s, Prev: %s | B2B Active - Current: %s, Prev: %s',
            $current_type,
            $previous_type,
            $current_b2b_active ? 'YES' : 'NO',
            $previous_b2b_active === null ? 'NULL' : ($previous_b2b_active ? 'YES' : 'NO')
        ));

        // 1. First-time initialization of session flags
        if (empty($previous_type)) {
            Logger::log('Initializing Briqpay session type for the first time: ' . $current_type);
            WC()->session->set('briqpay_customer_type', $current_type);
            WC()->session->set('briqpay_prev_b2b_active', $current_b2b_active);
            return false; // Stay stable on first-ever load
        }

        // 2. Check for actual Customer Type Change
        $type_changed = ($previous_type !== $current_type);
        if ($type_changed) {
            Logger::log(sprintf('Customer type changed: %s -> %s. Forcing new session.', $previous_type, $current_type));
            WC()->session->set('briqpay_customer_type', $current_type);
        }

        // 3. Check for B2B Mode Transition
        $b2b_changed = false;
        if (null !== $previous_b2b_active) {
            $previous_b2b_active = (bool) $previous_b2b_active;
            $b2b_changed = ($current_b2b_active !== $previous_b2b_active);
            if ($b2b_changed) {
                Logger::log(sprintf('B2B Active state changed: %s -> %s. Forcing new session.', $previous_b2b_active ? 'yes' : 'no', $current_b2b_active ? 'yes' : 'no'));
                WC()->session->set('briqpay_prev_b2b_active', $current_b2b_active);
            }
        } else {
            // First time seeing B2B flag, initialize it and don't trigger change
            Logger::log('Initializing B2B active state: ' . ($current_b2b_active ? 'yes' : 'no'));
            WC()->session->set('briqpay_prev_b2b_active', $current_b2b_active);
        }

        return $type_changed || $b2b_changed;
    }

    /**
     * Get Locale
     */
    private function get_locale()
    {
        return strtolower(str_replace('_', '-', get_locale()));
    }

    /**
     * Get Webhooks
     */
    private function get_webhooks()
    {
        $url = home_url('/wc-api/briqpay_webhook');
        return array(
            array(
                'eventType' => 'order_status',
                'statuses' => array('order_pending', 'order_rejected', 'order_cancelled', 'order_approved_not_captured'),
                'method' => 'POST',
                'url' => $url,
            ),
            array(
                'eventType' => 'capture_status',
                'statuses' => array('pending', 'approved', 'rejected'),
                'method' => 'POST',
                'url' => $url,
            ),
            array(
                'eventType' => 'refund_status',
                'statuses' => array('pending', 'approved', 'rejected'),
                'method' => 'POST',
                'url' => $url,
            ),
        );
    }

    /**
     * Get Redirect URL
     * 
     * Dynamically determines the return URL. If we are on a B2B shortcode page,
     * we should return to that same page to ensure the handler is triggered 
     * in the correct context.
     */
    private function get_redirect_url()
    {
        $url = wc_get_checkout_url();

        // 1. Check if we are currently on a page (B2B shortcode might be on a custom slug)
        // We use the referer if it's an AJAX call, or the current REQUEST_URI if it's a page load.
        $current_url = '';
        if (wp_doing_ajax()) {
            $current_url = wp_get_raw_referer();
        } else {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
            $current_url = esc_url_raw(home_url($request_uri));
        }

        if (!empty($current_url)) {
            // If the current URL contains 'b2b', it's highly likely our custom checkout page.
            // Standard WC checkout URL usually ends in /checkout/
            if (strpos($current_url, 'b2b') !== false) {
                $url = $current_url;
            }
        }

        // Standardize: Remove existing query args and add our return flag
        $url = strtok($url, '?');
        return add_query_arg('briqpay_return', '1', $url);
    }
    /**
     * Clear Session ID
     */
    public static function clear_session_id()
    {
        if (null !== WC() && null !== WC()->session) {
            WC()->session->set('briqpay_session_id', null);
        }

        if (isset($_COOKIE['briqpay_session_id'])) {
            setcookie('briqpay_session_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }
}
