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

            // --- B2B Address Update Handling ---
            // Listen for address changes in the iframe and sync to WooCommerce
            var addressUpdateRetryCount = 0;
            var attachAddressUpdateListener = function () {
                var sdk = window._briqpay;
                var v3 = sdk ? sdk.v3 : null;

                if (!v3) {
                    addressUpdateRetryCount++;
                    if (addressUpdateRetryCount < 50) {
                        setTimeout(attachAddressUpdateListener, 200);
                    }
                    return;
                }

                var subscribe = null;
                if (typeof v3.on === 'function') subscribe = v3.on.bind(v3);
                else if (typeof v3.subscribe === 'function') subscribe = v3.subscribe.bind(v3);
                else if (typeof sdk.subscribe === 'function') subscribe = sdk.subscribe.bind(sdk);

                if (!subscribe) {
                    addressUpdateRetryCount++;
                    if (addressUpdateRetryCount < 50) {
                        setTimeout(attachAddressUpdateListener, 200);
                    }
                    return;
                }

                console.log('Briqpay B2B: Subscribing to addressupdate...');
                subscribe('addressupdate', function (data) {
                    console.log('Briqpay B2B: Address Update Event Received', data);

                    var billing = data.billingaddress;
                    var shipping = data.shippingaddress;
                    var changed = false;

                    if (billing) {
                        if (billing.zip && $('#billing_postcode').val() !== billing.zip) {
                            $('#billing_postcode').val(billing.zip);
                            changed = true;
                        }
                    }

                    if (shipping) {
                        if (shipping.zip && $('#shipping_postcode').val() !== shipping.zip) {
                            $('#shipping_postcode').val(shipping.zip);
                            changed = true;
                        }
                    }

                    if (changed) {
                        console.log('Briqpay B2B: Address changed, triggering update_checkout');
                        // Trigger WooCommerce checkout update to recalculate shipping
                        $(document.body).trigger('update_checkout');
                    }
                });
            };

            attachAddressUpdateListener();
        }
    };

    $(document).ready(function () {
        briqpayB2B.init();
    });

})(jQuery);
