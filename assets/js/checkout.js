window.briqpayCheckout = {
    session: null,
    redirectUrl: null,
    listenersAttached: false,
    retryCount: 0,
    _updateDebounceTimer: null,
    _lastPayloadHash: '',
    _isUpdating: false,
    _isSuspended: false,
    _pendingDecision: null,

    init: function () {
        const $ = jQuery;
        $(document.body).on('updated_checkout', this.onUpdatedCheckout.bind(this));
        $(document.body).on('checkout_error', this.onCheckoutError.bind(this));
        $(document.body).on('applied_coupon_in_checkout removed_coupon_in_checkout', function () {
            // Clear hash so the next update is never skipped after coupon change
            window.briqpayCheckout._lastPayloadHash = '';
            window.briqpayCheckout.onUpdatedCheckout();
        });
        $(document.body).on('updated_shipping_method', this.onUpdatedCheckout.bind(this));
        $(document.body).on('payment_method_selected', this.onUpdatedCheckout.bind(this));

        // Also listen for payment method changes directly
        $(document.body).on('change', 'input[name="payment_method"]', function () {
            var val = jQuery(this).val();
            if (val === 'briqpay') {
                $('body').addClass('briqpay-selected');
                $('body').removeClass('briqpay-not-selected');
                window.briqpayCheckout.onUpdatedCheckout();
            } else if (val && val !== 'briqpay') {
                // ONLY unhide if we are certain a different method is actually selected
                $('body').removeClass('briqpay-selected');
                $('body').addClass('briqpay-not-selected');
                // Remove critical hide if another method is picked
                $('#briqpay-critical-css').remove();
                $('#briqpay-critical-js').remove();

                // Restore button visibility that might have been hidden by inline CSS/JS
                $('#place_order, .form-row.place-order, .wc-block-checkout__actions, .wc-block-components-checkout-place-order-button, [data-testid="wc-block-components-checkout-place-order-button"]').each(function () {
                    $(this).css({
                        'display': '', 'visibility': '', 'opacity': '', 'pointer-events': '', 'position': '', 'left': '', 'z-index': '', 'width': '', 'height': '', 'max-height': '', 'overflow': ''
                    });
                    $(this).removeAttr('aria-hidden');
                    $(this).removeAttr('disabled');
                });
            }
        });

        // Listen for shipping method changes to trigger immediate Briqpay update
        $(document.body).on('change', 'input[name^="shipping_method"]', function () {
            window.briqpayCheckout.forceUpdate();
        });

        // Also listen for any changes inside the checkout form that might affect totals
        $(document.body).on('change', 'form.checkout input, form.checkout select', function (e) {
            var name = $(this).attr('name');
            if (name && name.indexOf('payment_method') === -1 && name.indexOf('shipping_method') === -1) {
                // Clear hash so address/field changes always trigger a session update
                window.briqpayCheckout._lastPayloadHash = '';
                window.briqpayCheckout.onUpdatedCheckout();
            }
        });

        // ── Address Synchronization Note ──
        // WooCommerce natively watches address/email fields and triggers
        // 'update_checkout' → 'updated_checkout' on changes. We rely on this
        // built-in mechanism and debounce our response in onUpdatedCheckout().
        // No custom address field listeners needed — that would cause double triggers.

        // Initial check: Be VERY conservative. Default to hidden (from PHP) unless another method is 100% checked.
        var $checked = $('input[name="payment_method"]:checked');
        var currentMethod = $checked.val();

        if (currentMethod === 'briqpay') {
            $('body').addClass('briqpay-selected');
            $('body').removeClass('briqpay-not-selected');
        } else if (currentMethod && currentMethod !== 'briqpay') {
            $('body').removeClass('briqpay-selected');
            $('body').addClass('briqpay-not-selected');
            $('#briqpay-critical-css').remove();
            $('#briqpay-critical-js').remove();

            // Restore button visibility
            $('#place_order, .form-row.place-order, .wc-block-checkout__actions, .wc-block-components-checkout-place-order-button, [data-testid="wc-block-components-checkout-place-order-button"]').each(function () {
                $(this).css({
                    'display': '', 'visibility': '', 'opacity': '', 'pointer-events': '', 'position': '', 'left': '', 'z-index': '', 'width': '', 'height': '', 'max-height': '', 'overflow': ''
                });
                $(this).removeAttr('aria-hidden');
                $(this).removeAttr('disabled');
            });
        }

        // Detect if the company name field is present and required on the checkout page.
        // WooCommerce marks required fields with a parent that has 'validate-required' class.
        // If required, inject a hidden flag so the backend forces customerType to 'business'.
        var $companyField = $('#billing_company');
        if ($companyField.length > 0) {
            var $wrapper = $companyField.closest('.form-row');
            if ($wrapper.hasClass('validate-required')) {
                // Inject hidden field into all checkout forms so it's serialized with checkout_data
                $('form.checkout, form#order_review, form.woocommerce-checkout').each(function () {
                    if (!$(this).find('input[name="briqpay_company_required"]').length) {
                        $(this).append('<input type="hidden" name="briqpay_company_required" value="1" />');
                    }
                });
            }
        }

        this.onUpdatedCheckout();
    },

    onUpdatedCheckout: function () {
        var self = this;
        const $ = jQuery;

        // Debounce: collapse multiple rapid 'updated_checkout' events into one call.
        clearTimeout(self._updateDebounceTimer);
        self._updateDebounceTimer = setTimeout(function () {
            self._updateDebounceTimer = null;

            var isBriqpaySelected = $('#payment_method_briqpay').is(':checked');
            var isBriqpayValue = $('input[name="payment_method"]:checked').val() === 'briqpay';
            var isSingleMethod = $('input[name="payment_method"]').length === 1;
            var containerExists = $('#briqpay-iframe-container').length > 0;
            var bodyHasClass = $('body').hasClass('briqpay-selected');

            if (isBriqpaySelected || isBriqpayValue || isSingleMethod || containerExists || bodyHasClass) {
                // In WooCommerce Blocks checkout, blocks-checkout.js handles ALL updates
                // after the initial iframe creation. If we call updateSession here without
                // blocks_data, we send empty shipping info that overwrites the correct total.
                var isBlocksCheckout = !!window.briqpayRegistered;
                var hasSession = !!self.session;
                var hasIframe = $('#briqpay-iframe-container').children().length > 0;

                if (isBlocksCheckout && hasSession && hasIframe) {
                    return;
                }

                self.initOrUpdate();
            }
        }, 500);
    },

    forceUpdate: function () {
        if (window.briqpayRegistered && this.session) {
            return;
        }
        clearTimeout(this._updateDebounceTimer);
        this._updateDebounceTimer = null;
        this.initOrUpdate();
    },

    initOrUpdate: function (data) {
        const $ = jQuery;
        var hasSession = !!this.session;
        var hasIframe = $('#briqpay-iframe-container').children().length > 0;

        if (hasSession && hasIframe) {
            this.updateSession(data);
        } else {
            this.initIframe(data);
        }
    },

    initIframe: function (data) {
        const $ = jQuery;
        if (typeof briqpayParams === 'undefined') {
            console.error('Briqpay: briqpayParams is not defined.');
            return;
        }

        var requestData = {
            action: 'briqpay_get_session',
            nonce: briqpayParams.nonce,
            checkout_data: $('form.checkout, form#order_review, form.woocommerce-checkout').serialize()
        };

        if (data) {
            requestData.blocks_data = JSON.stringify(data);
        }

        $.ajax({
            url: briqpayParams.ajax_url,
            type: 'POST',
            data: requestData,
            success: function (response) {
                if (response.success) {
                    var snippet = response.data.htmlSnippet;
                    if (snippet) {
                        window.briqpayCheckout.session = response.data.sessionId;
                        $('#briqpay-iframe-container').html(snippet);
                        window.briqpayCheckout.attachListeners();
                    }
                } else {
                    console.error('Briqpay: Failed to load session', response);
                }
            },
            error: function (xhr, status, error) {
                console.error('Briqpay: AJAX error in initIframe', error);
            }
        });
    },

    updateSession: function (data) {
        const $ = jQuery;
        if (!this.session) return;

        var $form = $('form.checkout, form#order_review, form.woocommerce-checkout');
        var formData = $form.serialize();
        var blocksJson = data ? JSON.stringify(data) : '';

        var payloadHash = formData + '||' + blocksJson;

        if (payloadHash === this._lastPayloadHash) {
            this.resume();
            this._processPendingDecision();
            return;
        }

        this._lastPayloadHash = payloadHash;

        // Suspend the Briqpay iframe while we update the session
        this.suspend();

        var requestData = {
            action: 'briqpay_get_session',
            nonce: briqpayParams.nonce,
            checkout_data: formData
        };

        if (data) {
            requestData.blocks_data = blocksJson;
        }

        this._isUpdating = true;

        $.ajax({
            url: briqpayParams.ajax_url,
            type: 'POST',
            data: requestData,
            success: function (response) {
                if (response.success) {
                    var sessionIdChanged = response.data.sessionId && response.data.sessionId !== window.briqpayCheckout.session;

                    if (sessionIdChanged) {
                        // Session was regenerated (e.g. total changed dramatically).
                        // We must reload the iframe with the new snippet.

                        window.briqpayCheckout.session = response.data.sessionId;
                        if (response.data.htmlSnippet) {
                            $('#briqpay-iframe-container').html(response.data.htmlSnippet);
                            window.briqpayCheckout.listenersAttached = false;

                            // Re-attach listeners and WAIT before resuming to ensure SDK is ready
                            window.briqpayCheckout.attachListeners();
                            setTimeout(function () {
                                window.briqpayCheckout.resume();
                                window.briqpayCheckout._isUpdating = false;
                                window.briqpayCheckout._processPendingDecision();
                            }, 1000);
                            return; // Exit early, setTimeout handles the rest
                        }
                    }

                    // Standard update (PATCH): also wait 1000ms before resume to ensure backend sync
                    setTimeout(function () {
                        window.briqpayCheckout.resume();
                        window.briqpayCheckout._isUpdating = false;
                        window.briqpayCheckout._processPendingDecision();
                    }, 1000);
                } else {
                    console.error('Briqpay: Session sync failed', response);
                    window.briqpayCheckout.resume();
                    window.briqpayCheckout._isUpdating = false;
                    window.briqpayCheckout._processPendingDecision();
                }
            },
            error: function (xhr, status, error) {
                console.error('Briqpay: AJAX error in updateSession', error);
                window.briqpayCheckout.resume();
                window.briqpayCheckout._isUpdating = false;
                window.briqpayCheckout._processPendingDecision();
            }
        });
    },

    _processPendingDecision: function () {
        if (this._pendingDecision) {
            console.log('Briqpay: Processing deferred decision...');
            var event = this._pendingDecision;
            this._pendingDecision = null;
            this.makeDecision(event);
        }
    },

    makeDecision: function (event) {
        const $ = jQuery;
        var self = this;

        // If an update is scheduled (debounce) or in-flight (AJAX), defer the decision.
        if (this._updateDebounceTimer || this._isUpdating) {
            console.log('Briqpay: Deferring decision until session sync complete.');
            this._pendingDecision = event;
            return;
        }

        $.ajax({
            url: briqpayParams.ajax_url,
            type: 'POST',
            data: {
                action: 'briqpay_make_decision',
                nonce: briqpayParams.nonce,
                sessionId: event.sessionId
            },
            success: function (response) {
                var v3 = window._briqpay ? window._briqpay.v3 : null;
                if (response.success) {
                    self.redirectUrl = response.data.redirect_url;
                    if (v3 && typeof v3.resumeDecision === 'function') v3.resumeDecision();
                } else {
                    if (v3 && typeof v3.resumeDecision === 'function') v3.resumeDecision();
                }
            },
            error: function () {
                var v3 = window._briqpay ? window._briqpay.v3 : null;
                if (v3 && typeof v3.resumeDecision === 'function') v3.resumeDecision();
            }
        });
    },

    attachListeners: function () {
        const $ = jQuery;

        var sdk = window._briqpay;
        var v3 = sdk ? sdk.v3 : null;

        if (!v3) {
            window.briqpayCheckout.retryCount++;
            if (window.briqpayCheckout.retryCount < 50) {
                setTimeout(window.briqpayCheckout.attachListeners, 200);
            }
            return;
        }

        if (window.briqpayCheckout.listenersAttached) {
            return;
        }

        var subscribe = null;
        if (typeof v3.on === 'function') subscribe = v3.on.bind(v3);
        else if (typeof v3.subscribe === 'function') subscribe = v3.subscribe.bind(v3);
        else if (typeof sdk.subscribe === 'function') subscribe = sdk.subscribe.bind(sdk);

        if (!subscribe) {
            window.briqpayCheckout.retryCount++;
            if (window.briqpayCheckout.retryCount < 50) {
                setTimeout(window.briqpayCheckout.attachListeners, 200);
            }
            return;
        }

        window.briqpayCheckout.listenersAttached = true;

        try {
            subscribe('make_decision', function (event) {
                window.briqpayCheckout.makeDecision(event);
            });

            var onCompleted = function (event) {
                var url = window.briqpayCheckout.redirectUrl || (event && event.redirectUrl);
                if (url) window.location.href = url;
            };

            subscribe('order_completed', onCompleted);
            subscribe('session_complete', onCompleted);
            subscribe('checkout_complete', onCompleted);

            // Checkout Overlay - Use global sdk to ensure these lifecycle events are caught
            var globalSubscribe = (sdk && typeof sdk.subscribe === 'function') ? sdk.subscribe.bind(sdk) : subscribe;

            if (globalSubscribe) {

                globalSubscribe('paymentProcessStarted', function () {
                    var overlay = $('#briqpay-overlay');
                    if (overlay.length) {
                        overlay.show();
                        $('#briqpay-iframe-container').css({
                            'position': 'relative',
                            'z-index': '9999'
                        });
                    } else {

                    }
                });

                globalSubscribe('paymentProcessCancelled', function () {
                    $('#briqpay-overlay').hide();
                    $('#briqpay-iframe-container').css({
                        'position': '',
                        'z-index': ''
                    });
                });
            }
        } catch (e) {
            console.error('Briqpay: Listener error:', e);
            window.briqpayCheckout.listenersAttached = false;
        }
    },

    suspend: function () {
        if (this._isSuspended) return;
        if (window._briqpay && window._briqpay.v3 && typeof window._briqpay.v3.suspend === 'function') {
            window._briqpay.v3.suspend();
            this._isSuspended = true;
        }
    },

    resume: function () {
        if (!this._isSuspended) return;
        if (window._briqpay && window._briqpay.v3 && typeof window._briqpay.v3.resume === 'function') {
            window._briqpay.v3.resume();
            this._isSuspended = false;
        }
    },

    onCheckoutError: function () {
        window.briqpayCheckout.resume();
    }
};

jQuery(function ($) {
    'use strict';
    window.briqpayCheckout.init();
});
