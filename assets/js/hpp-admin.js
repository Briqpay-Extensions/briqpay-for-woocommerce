jQuery(function ($) {
    // Create a hosted payment page
    $(document).on('click', '.briqpay-hpp-create', function (e) {
        e.preventDefault();

        if (!confirm(briqpayHpp.confirm_create)) {
            return;
        }

        var flow = $('#briqpay_hpp_flow').val();
        createHostedPage($(this), flow);
    });

    // Regenerate an existing hosted payment page
    $(document).on('click', '.briqpay-hpp-regenerate', function (e) {
        e.preventDefault();

        if (!confirm(briqpayHpp.confirm_regen)) {
            return;
        }

        var $btn = $(this);
        var flow = $btn.data('flow');
        createHostedPage($btn, flow);
    });

    function createHostedPage($btn, flow) {
        var $result = $('.briqpay-hpp-result');
        var $loading = $('.briqpay-hpp-loading');
        var orderId = $('#briqpay_hpp_order_id').val();

        $btn.prop('disabled', true);
        $result.text('');
        $loading.show();

        $.ajax({
            url: briqpayHpp.ajax_url,
            type: 'POST',
            data: {
                action: 'briqpay_create_hosted_page',
                nonce: briqpayHpp.nonce,
                order_id: orderId,
                flow: flow
            },
            success: function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    $result.text((response.data && response.data.message) || briqpayHpp.error_generic);
                    $btn.prop('disabled', false);
                    $loading.hide();
                }
            },
            error: function () {
                $result.text(briqpayHpp.error_generic);
                $btn.prop('disabled', false);
                $loading.hide();
            }
        });
    }

    // Copy the hosted page link
    $(document).on('click', '.briqpay-hpp-copy', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var url = $('#briqpay_hpp_url').val();
        var originalLabel = $btn.text();

        function showCopied() {
            $btn.text(briqpayHpp.copied);
            setTimeout(function () {
                $btn.text(originalLabel);
            }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(showCopied);
            return;
        }

        var $input = $('#briqpay_hpp_url');
        $input.trigger('focus');
        $input[0].select();
        if (typeof $input[0].setSelectionRange === 'function') {
            $input[0].setSelectionRange(0, 99999); // mobile Safari fallback
        }
        try {
            document.execCommand('copy');
            showCopied();
        } catch (err) {
            // Silently ignore - the field is still selected for a manual copy.
        }
    });
});
