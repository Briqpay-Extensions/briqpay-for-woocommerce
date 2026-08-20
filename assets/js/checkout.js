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
    // Guards overlapping initialisation: updated_checkout, updated_shipping_method,
    // payment_method_selected and the B2B shortcode can all trigger it, and the
    // B2B script calls initOrUpdate() directly on top of the normal flow.
    _isInitializing: false,
    // Last snippet Briqpay returned. WooCommerce replaces the payment fragment on
    // every order review refresh, which throws the iframe away - this lets us
    // redraw it without another session request.
    _lastSnippet: null,

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

        // Also listen to payment method selections in WooCommerce Blocks
        $(document.body).on('change click', '.wc-block-checkout__payment-methods, .wc-block-components-radio-control', function () {
            window.briqpayCheckout.onUpdatedCheckout();
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

        // Call straight through. This used to wait 500ms and then hit
        // onUpdatedCheckout's own 500ms debounce, so nothing reached Briqpay for a
        // full second after the page was ready. The debounce below still collapses
        // bursts of events, which is what the delay was actually for.
        this.onUpdatedCheckout();
    },

    onUpdatedCheckout: function () {
        var self = this;
        const $ = jQuery;

        // Debounce: collapse multiple rapid 'updated_checkout' events into one call.
        clearTimeout(self._updateDebounceTimer);
        self._updateDebounceTimer = setTimeout(function () {
            self._updateDebounceTimer = null;

            var isBlocksCheckout = $('.wc-block-checkout').length > 0 || $('.wc-block-components-checkout-step').length > 0 || !!window.briqpayRegistered;
            var isBriqpaySelected = false;
            var isSingleMethod = false;

            if (isBlocksCheckout) {
                // In Blocks, Briqpay is active/selected if its content iframe container is rendered in the DOM
                var iframeContainer = $('#briqpay-iframe-container');
                isBriqpaySelected = iframeContainer.length > 0;
                
                // Blocks payment option radio buttons container check
                var blockOptions = $('.wc-block-components-radio-control__option');
                if (blockOptions.length === 1 && blockOptions.filter('[for*="briqpay"]').length > 0) {
                    isSingleMethod = true;
                }
            } else {
                var paymentMethods = $('input[name="payment_method"]');
                isBriqpaySelected = $('#payment_method_briqpay').is(':checked') || (paymentMethods.length > 0 && paymentMethods.filter(':checked').val() === 'briqpay');
                isSingleMethod = paymentMethods.length === 1 && paymentMethods.val() === 'briqpay';
            }

            if (isBriqpaySelected || isSingleMethod) {
                self.initOrUpdate();
            }
        }, 250);
    },

    forceUpdate: function () {
        clearTimeout(this._updateDebounceTimer);
        this._updateDebounceTimer = null;
        this.initOrUpdate();
    },

    initOrUpdate: function (data) {
        const $ = jQuery;
        var $container = $('#briqpay-iframe-container');
        var hasSession = !!this.session;
        var hasIframe = $container.children().length > 0;

        // Overlapping initialisation. Several events can fire close together
        // (updated_checkout, updated_shipping_method, payment_method_selected), and
        // without this each one could start its own session request.
        if (this._isInitializing) {
            return;
        }

        if (hasSession && hasIframe) {
            this.updateSession(data);
            return;
        }

        // The container is empty but we already have a live session. This is the
        // ordinary case after WooCommerce refreshes the payment fragment:
        // payment_fields() emits a fresh empty container, so the iframe we drew a
        // moment ago is gone. Re-drawing from the cached snippet costs nothing,
        // where calling initIframe() would create/PATCH a session over the network
        // and hand back a snippet identical to the one we already hold - the second
        // iframe load visible on the checkout page.
        if (hasSession && !hasIframe && this._lastSnippet) {
            $container.html(this._lastSnippet);
            this.listenersAttached = false;
            this.attachListeners();

            // Redrawing restores the view, but the fragment may well have refreshed
            // BECAUSE the cart changed - a new shipping method, a coupon - so the
            // session still has to be reconciled. updateSession() compares the form
            // fingerprint and no-ops when nothing actually moved, so this costs a
            // request only when one is genuinely needed.
            this.updateSession(data);
            return;
        }

        this.initIframe(data);
    },

    /**
     * The payload fingerprint updateSession() compares against, so a create and an
     * update agree on what "unchanged" means.
     */
    _payloadHash: function (data) {
        const $ = jQuery;
        var $form = $('form.checkout, form#order_review, form.woocommerce-checkout');
        return $form.serialize() + '||' + (data ? JSON.stringify(data) : '');
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

        // Seed the fingerprint from the form we are about to send, so the next
        // updateSession() recognises an unchanged payload and skips the request
        // entirely. Without this the sync right after a create always fired.
        this._lastPayloadHash = this._payloadHash(data);
        this._isInitializing = true;

        $.ajax({
            url: briqpayParams.ajax_url,
            type: 'POST',
            data: requestData,
            success: function (response) {
                if (response.success) {
                    var snippet = response.data.htmlSnippet;
                    if (snippet) {
                        window.briqpayCheckout.session = response.data.sessionId;
                        // Cached so a fragment refresh can redraw without a request.
                        window.briqpayCheckout._lastSnippet = snippet;
                        $('#briqpay-iframe-container').html(snippet);
                        window.briqpayCheckout.attachListeners();
                    }
                } else {
                    // Nothing was drawn, so the fingerprint must not claim the form
                    // is already synced - a retry has to be allowed through.
                    window.briqpayCheckout._lastPayloadHash = null;
                    console.error('Briqpay: Failed to load session', response);
                }
                window.briqpayCheckout._isInitializing = false;
            },
            error: function (xhr, status, error) {
                window.briqpayCheckout._lastPayloadHash = null;
                window.briqpayCheckout._isInitializing = false;
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

        var payloadHash = this._payloadHash(data);

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

                            // A brand-new iframe was just inserted, so the SDK does
                            // genuinely need a moment to come up before resume().
                            // 250ms rather than a full second - attachListeners()
                            // already retries until the SDK answers.
                            window.briqpayCheckout._lastSnippet = response.data.htmlSnippet;
                            window.briqpayCheckout.attachListeners();
                            setTimeout(function () {
                                window.briqpayCheckout.resume();
                                window.briqpayCheckout._isUpdating = false;
                                window.briqpayCheckout._processPendingDecision();
                            }, 250);
                            return; // Exit early, setTimeout handles the rest
                        }
                    }

                    // Standard PATCH: the same iframe is still on screen and the
                    // API has already answered, so there is nothing to wait for.
                    // The old unconditional 1s kept the iframe suspended long after
                    // the work was done.
                    window.briqpayCheckout.resume();
                    window.briqpayCheckout._isUpdating = false;
                    window.briqpayCheckout._processPendingDecision();
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
