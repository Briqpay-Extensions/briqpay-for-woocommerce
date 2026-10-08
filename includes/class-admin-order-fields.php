<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Briqpay checkout fields on the order edit screen.
 *
 * The fields a customer fills in at the Briqpay checkout - reference, own
 * order number, alternative invoice email, GLN, and whatever else the merchant
 * configured - reach the order as meta (see Session_Order_Data). An order built
 * by hand in the admin has none of them, and the merchant had nowhere to type
 * them: only the organisation number had a field. This block shows them all,
 * editable, and follows the payment method dropdown like the organisation
 * number does, so they are there the moment Briqpay is chosen on a manual
 * order. Saved values land in the same meta keys the checkout writes, so an
 * ERP export reads a manual order exactly like a checkout order, and the
 * hosted payment page sends the invoice fields on to Briqpay.
 *
 * Which fields: the invoice payment method's four standard ones, plus every
 * field seen on earlier orders of this shop (Briqpay's field set is configured
 * per merchant in the backoffice and is not known here), plus whatever is on
 * this order. The briqpay_admin_order_fields filter can add or remove fields.
 */
class Admin_Order_Fields
{
    /**
     * Name of the posted array: briqpay_fields[<source>][<key>].
     */
    const POST_KEY = 'briqpay_fields';

    public function init()
    {
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'render'), 20);
        add_action('woocommerce_process_shop_order_meta', array($this, 'save'), 46, 1);
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    /**
     * The invoice payment method's own fields - the ones every merchant has.
     *
     * Keys are Briqpay's: they are the meta key suffix and, for the hosted
     * payment page, the invoiceDetails property names.
     *
     * @return array<string,array<string,string>> source => key => label
     */
    public static function standard_fields()
    {
        return array(
            'paymentAdditionalFields' => array(
                'reference' => __('Reference', 'briqpay-for-woocommerce'),
                'orderNumber' => __('Customer order number', 'briqpay-for-woocommerce'),
                'email' => __('Invoice email', 'briqpay-for-woocommerce'),
                'gln' => __('GLN', 'briqpay-for-woocommerce'),
            ),
        );
    }

    /**
     * Every field to show for an order, with its current value.
     *
     * Order of precedence for the label: this order's own record, then what an
     * earlier order called it, then the standard label.
     *
     * @param \WC_Order $order The order.
     * @return array<int,array{source:string,key:string,label:string,value:string}>
     */
    public static function fields_for($order)
    {
        $fields = array();
        foreach (self::standard_fields() as $source => $keys) {
            foreach ($keys as $key => $label) {
                $fields[$source . '|' . $key] = array('source' => $source, 'key' => $key, 'label' => $label, 'value' => '');
            }
        }
        foreach (Session_Order_Data::seen_fields() as $id => $label) {
            list($source, $key) = array_pad(explode('|', $id, 2), 2, '');
            if ('' === $key || !isset(Session_Order_Data::SOURCES[$source])) {
                continue;
            }
            $fields[$id] = array('source' => $source, 'key' => $key, 'label' => $label, 'value' => '');
        }
        foreach (self::stored_fields($order) as $field) {
            $fields[$field['source'] . '|' . $field['key']] = $field;
        }
        foreach ($fields as $id => $field) {
            $fields[$id]['value'] = (string) $order->get_meta(Session_Order_Data::SOURCES[$field['source']] . $field['key']);
        }

        /**
         * Filter the Briqpay checkout fields offered on the order edit screen.
         *
         * @param array     $fields Each {source, key, label, value}; source is one
         *                          of paymentAdditionalFields, orderNote, customForm1.
         * @param \WC_Order $order  The order.
         */
        return array_values(apply_filters('briqpay_admin_order_fields', array_values($fields), $order));
    }

    /**
     * The fields recorded on the order by the checkout (or an earlier edit).
     *
     * @param \WC_Order $order The order.
     * @return array<int,array{source:string,key:string,label:string,value:string}>
     */
    private static function stored_fields($order)
    {
        $decoded = json_decode((string) $order->get_meta(Session_Order_Data::META_FIELDS), true);
        $fields = array();
        foreach (is_array($decoded) ? $decoded : array() as $field) {
            if (!is_array($field) || empty($field['source']) || empty($field['key']) || !isset(Session_Order_Data::SOURCES[$field['source']])) {
                continue;
            }
            $fields[] = array(
                'source' => (string) $field['source'],
                'key' => (string) $field['key'],
                'label' => isset($field['label']) ? (string) $field['label'] : (string) $field['key'],
                'value' => isset($field['value']) ? (string) $field['value'] : '',
            );
        }
        return $fields;
    }

    /**
     * Render the block: a view-mode list of the filled-in fields, and inputs
     * for all of them in edit mode. Rendered for every order, shown only for
     * Briqpay ones - admin-order-fields.js follows the payment method dropdown.
     *
     * @param \WC_Order $order The order.
     */
    public function render($order)
    {
        $fields = self::fields_for($order);
        if (empty($fields)) {
            return;
        }
        $hidden = 'briqpay' !== $order->get_payment_method();
        $filled = array_filter($fields, function ($field) {
            return '' !== $field['value'];
        });
        ?>
        <div class="order_data_column briqpay-admin-field briqpay-checkout-fields" style="clear:both; float:none; width:100%;<?php echo $hidden ? ' display:none;' : ''; ?>">
            <?php if (!empty($filled)) : ?>
                <div class="address">
                    <p>
                        <strong><?php esc_html_e('Briqpay checkout fields', 'briqpay-for-woocommerce'); ?>:</strong>
                        <?php foreach ($filled as $field) : ?>
                            <br><?php echo esc_html($field['label']); ?>: <?php echo esc_html($field['value']); ?>
                        <?php endforeach; ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="edit_address">
                <?php foreach ($fields as $field) : ?>
                    <?php
                    woocommerce_wp_text_input(
                        array(
                            'id' => 'briqpay_field_' . $field['source'] . '_' . $field['key'],
                            'name' => self::POST_KEY . '[' . $field['source'] . '][' . $field['key'] . ']',
                            'label' => $field['label'],
                            'value' => $field['value'],
                            'type' => 'email' === $field['key'] ? 'email' : 'text',
                        )
                    );
                    ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Save the posted fields: same meta keys the checkout writes, and the same
     * JSON record, so nothing downstream can tell a typed value from a
     * collected one. Nothing is written when nothing changed.
     *
     * @param int $post_id The order ID.
     */
    public function save($post_id)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
        if (!isset($_POST[self::POST_KEY]) || !is_array($_POST[self::POST_KEY])) {
            return;
        }
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        if (!current_user_can('edit_shop_orders') && !current_user_can('edit_shop_order', $post_id)) {
            return;
        }
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        $posted = wp_unslash($_POST[self::POST_KEY]);
        // phpcs:enable

        $labels = array();
        foreach (self::fields_for($order) as $field) {
            $labels[$field['source'] . '|' . $field['key']] = $field['label'];
        }

        $record = array();
        foreach (self::stored_fields($order) as $field) {
            $record[$field['source'] . '|' . $field['key']] = $field;
        }

        $changed = array();
        foreach ($posted as $source => $keys) {
            if (!isset(Session_Order_Data::SOURCES[$source]) || !is_array($keys)) {
                continue;
            }
            foreach ($keys as $key => $value) {
                $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
                if ('' === $key || !is_scalar($value)) {
                    continue;
                }
                $value = 'email' === $key ? sanitize_email((string) $value) : sanitize_text_field((string) $value);
                $meta_key = Session_Order_Data::SOURCES[$source] . $key;
                $id = $source . '|' . $key;
                if ($value === (string) $order->get_meta($meta_key)) {
                    continue;
                }
                $label = isset($labels[$id]) ? $labels[$id] : $key;
                if ('' === $value) {
                    $order->delete_meta_data($meta_key);
                    unset($record[$id]);
                } else {
                    $order->update_meta_data($meta_key, $value);
                    $record[$id] = array('source' => $source, 'key' => $key, 'label' => $label, 'value' => $value);
                }
                $changed[] = $label . ': ' . ('' === $value ? __('(cleared)', 'briqpay-for-woocommerce') : $value);
            }
        }

        if (empty($changed)) {
            return;
        }

        if (empty($record)) {
            $order->delete_meta_data(Session_Order_Data::META_FIELDS);
        } else {
            $order->update_meta_data(Session_Order_Data::META_FIELDS, wp_json_encode(array_values($record)));
        }
        $order->add_order_note(
            __('Briqpay: checkout fields edited in the admin:', 'briqpay-for-woocommerce') . "\n" . implode("\n", $changed)
        );
        $order->save();
        Logger::log(sprintf('Admin edited %d Briqpay checkout field(s) on order %s.', count($changed), $order->get_id()));
    }

    /**
     * The toggle script, on order screens only. Same handle as the legacy
     * organisation-number field registers; enqueuing it twice is a no-op.
     *
     * @param string $hook Current admin page hook.
     */
    public function admin_scripts($hook)
    {
        $is_order_screen = 'woocommerce_page_wc-orders' === $hook;

        if (!$is_order_screen && in_array($hook, array('post.php', 'post-new.php'), true)) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $is_order_screen = $screen && 'shop_order' === $screen->post_type;
        }

        if (!$is_order_screen) {
            return;
        }

        wp_enqueue_script('briqpay-admin-order-fields', BRIQPAY_WC_URL . 'assets/js/admin-order-fields.js', array('jquery'), BRIQPAY_WC_VERSION, true);
    }
}
