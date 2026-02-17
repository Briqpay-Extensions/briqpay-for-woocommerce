jQuery(function ($) {
    // Toggle capture form
    $(document).on('click', '.briqpay-capture-form-toggle', function (e) {
        e.preventDefault();
        $('.briqpay-capture-form').slideToggle();
    });

    // Execute capture
    $(document).on('click', '.briqpay-do-capture', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $form = $('.briqpay-capture-form');
        var $loading = $('.briqpay-capture-loading');
        var orderId = $('#briqpay_capture_order_id').val();

        var items = [];
        $('.briqpay-capture-qty').each(function () {
            var qty = parseInt($(this).val());
            if (qty > 0) {
                items.push({
                    reference: $(this).data('ref'),
                    name: $(this).data('name'),
                    quantity: qty,
                    unitPriceIncVat: $(this).data('price-inc'),
                    taxRate: $(this).data('tax'),
                    productType: $(this).data('type')
                });
            }
        });

        if (items.length === 0) {
            alert('Please select at least one item to capture.');
            return;
        }

        if (!confirm('Are you sure you want to capture these items towards Briqpay?')) {
            return;
        }

        $btn.prop('disabled', true);
        $loading.show();

        $.ajax({
            url: briqpayAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'briqpay_capture_items',
                nonce: briqpayAdmin.nonce,
                order_id: orderId,
                items: items
            },
            success: function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Capture failed.');
                    $btn.prop('disabled', false);
                    $loading.hide();
                }
            },
            error: function () {
                alert('An error occurred during capture.');
                $btn.prop('disabled', false);
                $loading.hide();
            }
        });
    });
});
