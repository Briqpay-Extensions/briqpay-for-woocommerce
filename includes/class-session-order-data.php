<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session data that Briqpay collects in the checkout and that has to reach the
 * WooCommerce order: the shipping recipient's company, and the merchant's own
 * input fields (reference, own order number, alternative email, ...).
 *
 * Those fields are configured per merchant in the Briqpay backoffice, on the
 * order note module, the custom form or the payment method itself, so their keys
 * are not known here. The V3 session returns each one as
 * data.orderNote.<key> / data.customForm1.<key> /
 * data.paymentAdditionalFields.<key>, shaped { value, header } where header is
 * the label the customer saw.
 *
 * paymentAdditionalFields is the invoice payment method's own block - the
 * reference, the customer's own order number, the alternative invoice email, the
 * GLN. It behaves differently from the other two in one way that matters: Briqpay
 * only fills it in AFTER the decision, between one and ninety-five seconds later.
 * The order is built at the decision, so reading it only there finds nothing,
 * which is why apply_custom_fields() also runs on the customer's return and on
 * every webhook.
 */
class Session_Order_Data
{
    /**
     * Order meta holding every collected field, as a JSON list.
     */
    const META_FIELDS = '_briqpay_custom_fields';

    /**
     * Session blocks read, mapped to the prefix of their per-field meta keys.
     */
    const SOURCES = array(
        'paymentAdditionalFields' => '_briqpay_payment_field_',
        'orderNote' => '_briqpay_order_note_',
        'customForm1' => '_briqpay_custom_form_',
    );

    /**
     * The company to put on the shipping address.
     *
     * The shipping module has its own companyName - the recipient, which on a B2B
     * order is not necessarily the buyer. Only when Briqpay reports none does it
     * fall back to the buying company, as before.
     *
     * @param array $session Briqpay session.
     * @return string
     */
    public static function shipping_company(array $session)
    {
        $shipping_company = $session['data']['shipping']['companyName'] ?? '';
        if (is_string($shipping_company) && '' !== trim($shipping_company)) {
            return sanitize_text_field($shipping_company);
        }

        $company_name = $session['data']['company']['name'] ?? '';

        return is_string($company_name) ? sanitize_text_field($company_name) : '';
    }

    /**
     * The merchant-configured fields collected in the Briqpay checkout.
     *
     * @param array $session Briqpay session.
     * @return array<int,array{source:string,key:string,label:string,value:string}>
     */
    public static function custom_fields(array $session)
    {
        $fields = array();

        foreach (array_keys(self::SOURCES) as $source) {
            $block = $session['data'][$source] ?? array();
            if (!is_array($block)) {
                continue;
            }

            foreach ($block as $key => $entry) {
                $value = self::field_value($entry);
                if ('' === $value) {
                    continue;
                }

                // Briqpay's own casing is kept - sanitize_key() would lowercase
                // customerOrderNumber to customerordernumber, and an integration
                // reading the meta key has to be able to predict it from the
                // Briqpay field name. Only characters unsafe in a meta key are
                // dropped, which can leave nothing to key on.
                $meta_key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
                if ('' === $meta_key) {
                    continue;
                }

                $label = is_array($entry) && isset($entry['header']) && is_scalar($entry['header'])
                    ? (string) $entry['header']
                    : '';

                $fields[] = array(
                    'source' => $source,
                    'key' => $meta_key,
                    'label' => sanitize_text_field('' !== $label ? $label : (string) $key),
                    'value' => $value,
                );
            }
        }

        return $fields;
    }

    /**
     * Write the collected fields onto the order.
     *
     * Each field gets its own meta key (_briqpay_payment_field_<key>,
     * _briqpay_order_note_<key>, _briqpay_custom_form_<key>) for integrations,
     * plus one order note listing them so the merchant sees them on the order
     * screen. The order note also becomes WooCommerce's own customer note when the
     * order has none, because that is where a merchant looks for it.
     *
     * Runs on every sync - at the decision, on the customer's return and on every
     * webhook - so nothing is written at all unless the values actually changed.
     * That is what keeps a redelivered webhook from adding a second identical
     * order note. Does not save the order; the return value tells the caller
     * whether there is anything to save.
     *
     * @param \WC_Order $order   The order.
     * @param array     $session Briqpay session.
     * @return bool True if the order was changed and needs saving.
     */
    public static function apply_custom_fields($order, array $session)
    {
        $fields = self::custom_fields($session);
        if (empty($fields)) {
            return false;
        }

        // Compared before anything is written, so an unchanged session is a true
        // no-op: no meta writes, no note, and nothing for the caller to save.
        $encoded = wp_json_encode($fields);
        if ($encoded === $order->get_meta(self::META_FIELDS)) {
            return false;
        }
        $order->update_meta_data(self::META_FIELDS, $encoded);

        $lines = array();
        foreach ($fields as $field) {
            $order->update_meta_data(self::SOURCES[$field['source']] . $field['key'], $field['value']);

            // The note the customer typed belongs in WooCommerce's own field, not
            // only in our meta. Never overwrites one that is already there: the
            // checkout form's note wins over a later Briqpay sync.
            if ('orderNote' === $field['source'] && 'note' === $field['key']
                && '' === (string) $order->get_customer_note()) {
                $order->set_customer_note($field['value']);
            }

            $lines[] = $field['label'] . ': ' . $field['value'];
        }

        $order->add_order_note(
            __('Briqpay: Customer details from the checkout:', 'briqpay-for-woocommerce') . "\n" . implode("\n", $lines)
        );

        Logger::log(sprintf('Applied %d Briqpay checkout field(s) to order %s.', count($fields), $order->get_id()));

        return true;
    }

    /**
     * apply_custom_fields(), with anything it throws swallowed and logged.
     *
     * For the two call sites where this is supplementary work attached to
     * something far more important: the customer's return, which renders their
     * order confirmation, and the webhook, which records the payment status. Both
     * run this before that work. A failure to store a reference field must never
     * cost the customer their confirmation page or the merchant a status update -
     * the values stay in the Briqpay session either way, and the next sync
     * retries.
     *
     * @param \WC_Order $order   The order.
     * @param array     $session Briqpay session.
     * @return bool True if the order was changed and needs saving.
     */
    public static function try_apply_custom_fields($order, array $session)
    {
        try {
            return self::apply_custom_fields($order, $session);
        } catch (\Throwable $e) {
            Logger::error(sprintf(
                'Could not apply Briqpay checkout fields to order %s: %s',
                is_callable(array($order, 'get_id')) ? $order->get_id() : 'unknown',
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Flatten one field entry to a string.
     *
     * @param mixed $entry { value, header }, or an object-valued field with header merged in.
     * @return string Empty when there is nothing to store.
     */
    private static function field_value($entry)
    {
        if (is_array($entry) && array_key_exists('value', $entry)) {
            $entry = $entry['value'];
        } elseif (is_array($entry)) {
            unset($entry['header']);
        }

        if (is_bool($entry)) {
            return $entry ? __('Yes', 'briqpay-for-woocommerce') : __('No', 'briqpay-for-woocommerce');
        }

        if (is_scalar($entry)) {
            return sanitize_textarea_field((string) $entry);
        }

        if (is_array($entry)) {
            $parts = array();
            foreach ($entry as $part) {
                $part = self::field_value($part);
                if ('' !== $part) {
                    $parts[] = $part;
                }
            }
            return implode(', ', $parts);
        }

        return '';
    }
}
