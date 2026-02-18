/**
 * Briqpay B2B Checkout Bootstrap
 */
(function ($) {
    'use strict';

    var briqpayB2B = {
        init: function () {
            console.log('Briqpay B2B: Initializing...');

            // Add a helper class to body for B2B specific CSS targets
            $('body').addClass('briqpay-b2b-page briqpay-selected');

            // Listen for shipping changes to trigger "normal flow" WooCommerce update
            $(document.body).on('change', 'input[name^="shipping_method"]', function () {
                var val = $(this).val();
                $(document.body).trigger('update_checkout');
            });

            // The core checkout.js handles the actually loading of the iframe
            // and event listening. We just need to make sure it triggers.
            if (window.briqpayCheckout) {
                // If we are in B2B mode, we need to make sure checkout.js doesn't
                // bail out early because of window.briqpayRegistered (Blocks check).
                // We wrap the original onUpdatedCheckout to ensure it always calls initOrUpdate.
                var originalOnUpdated = window.briqpayCheckout.onUpdatedCheckout;
                window.briqpayCheckout.onUpdatedCheckout = function () {
                    // Temporarily unset the sentinel if it exists to force update
                    var wasRegistered = window.briqpayRegistered;
                    window.briqpayRegistered = false;

                    originalOnUpdated.apply(window.briqpayCheckout, arguments);

                    window.briqpayRegistered = wasRegistered;
                };
                window.briqpayCheckout.initOrUpdate();
            } else {
                console.warn('Briqpay B2B: briqpayCheckout global not found.');
            }
        }
    };

    $(document).ready(function () {
        briqpayB2B.init();
    });

})(jQuery);
