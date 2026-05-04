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

                    var billing  = data.billingaddress;
                    var shipping = data.shippingaddress;

                    // Helper: set a field value and return true if it actually changed.
                    function syncField(selector, value) {
                        if (value !== undefined && value !== null && $(selector).val() !== value) {
                            $(selector).val(value);
                            return true;
                        }
                        return false;
                    }

                    var changed = false;

                    // Sync all billing address fields that WooCommerce uses for
                    // shipping-zone resolution (country is the most critical one).
                    if (billing) {
                        changed = syncField('#billing_country',  billing.country)   || changed;
                        changed = syncField('#billing_postcode', billing.zip)        || changed;
                        changed = syncField('#billing_city',     billing.city)       || changed;
                        changed = syncField('#billing_state',    billing.state)      || changed;
                    }

                    // Sync all shipping address fields.
                    if (shipping) {
                        changed = syncField('#shipping_country',  shipping.country)  || changed;
                        changed = syncField('#shipping_postcode', shipping.zip)       || changed;
                        changed = syncField('#shipping_city',     shipping.city)      || changed;
                        changed = syncField('#shipping_state',    shipping.state)     || changed;
                    }

                    // Always trigger update_checkout when Briqpay fires this event.
                    // Even if the hidden-field values were already correct, WooCommerce
                    // may not have recalculated shipping yet (e.g. on first load or after
                    // a session reset).  This mirrors the manual fix:
                    //   jQuery('#billing_country,#shipping_country').val('SE');
                    //   jQuery('#billing_postcode,#shipping_postcode').val('12345');
                    //   jQuery(document.body).trigger('update_checkout');
                    console.log('Briqpay B2B: Triggering update_checkout (changed=' + changed + ')');

                    // Suspend the iframe while WooCommerce recalculates.
                    if (window.briqpayCheckout && typeof window.briqpayCheckout.suspend === 'function') {
                        window.briqpayCheckout.suspend();
                    }

                    $(document.body).trigger('update_checkout');
                });
            };

            attachAddressUpdateListener();
        }
    };

    $(document).ready(function () {
        briqpayB2B.init();
    });

})(jQuery);
