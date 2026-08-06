jQuery(function ($) {
    var $enabled = $('#woocommerce_briqpay_hpp_enabled');
    if (!$enabled.length) {
        return;
    }

    var $rows = $(
        '#woocommerce_briqpay_hpp_default_flow, ' +
        '#woocommerce_briqpay_hpp_page_title, ' +
        '#woocommerce_briqpay_hpp_logo_url, ' +
        '#woocommerce_briqpay_hpp_show_cart'
    ).closest('tr');

    function toggle() {
        $rows.toggle($enabled.is(':checked'));
    }

    toggle();
    $enabled.on('change', toggle);
});
