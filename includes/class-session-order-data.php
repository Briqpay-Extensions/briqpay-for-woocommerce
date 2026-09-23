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
 * order note module or the custom form, so their keys are not known here. The
 * V3 session returns each one as data.orderNote.<key> / data.customForm1.<key>,
 * shaped { value, header } where header is the label the customer saw.
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

                $label = is_array($entry) && isset($entry['header']) && is_scalar($entry['header'])
                    ? (string) $entry['header']
                    : '';

                $fields[] = array(
                    'source' => $source,
                    'key' => sanitize_key((string) $key),
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
     * Each field gets its own meta key (_briqpay_order_note_<key>,
     * _briqpay_custom_form_<key>) for integrations, plus one order note listing
     * them so the merchant sees them on the order screen. Runs on every sync, so
     * the note is only added when the values have changed. Does not save the
     * order - callers do.
     *
     * @param \WC_Order $order   The order.
     * @param array     $session Briqpay session.
     * @return void
     */
    public static function apply_custom_fields($order, array $session)
    {
        $fields = self::custom_fields($session);
        if (empty($fields)) {
            return;
        }

        foreach ($fields as $field) {
            $order->update_meta_data(self::SOURCES[$field['source']] . $field['key'], $field['value']);
        }

        $encoded = wp_json_encode($fields);
        if ($encoded === $order->get_meta(self::META_FIELDS)) {
            return;
        }
        $order->update_meta_data(self::META_FIELDS, $encoded);

        $lines = array();
        foreach ($fields as $field) {
            $lines[] = $field['label'] . ': ' . $field['value'];
        }

        $order->add_order_note(
            __('Briqpay: Customer details from the checkout:', 'briqpay-for-woocommerce') . "\n" . implode("\n", $lines)
        );

        Logger::log(sprintf('Applied %d Briqpay checkout field(s) to order %s.', count($fields), $order->get_id()));
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
