/**
 * Show the Briqpay-only order fields (the billing organisation number) as soon as
 * Briqpay is chosen as the payment method on the order edit screen, rather than
 * only once the order has been saved with it.
 *
 * The fields are rendered for every order with their initial visibility taken
 * from the saved payment method; this keeps them in step with the dropdown
 * while the merchant is still building a manual order.
 */
jQuery(function ($) {
    var $fields = $('.briqpay-admin-field');

    if (!$fields.length) {
        return;
    }

    function sync() {
        var $select = $('#_payment_method');

        if (!$select.length) {
            return;
        }

        $fields.toggle('briqpay' === $select.val());
    }

    $(document).on('change', '#_payment_method', sync);
    sync();
});
