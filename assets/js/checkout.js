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
    // Newest payload that arrived while a session update was in flight. Only the
    // latest is worth keeping - intermediate cart states are already superseded.
    _queuedUpdate: null,
    // Set when WooCommerce tells us it has recalculated the cart. The payload
    // fingerprint below only covers the checkout form, and the amount can move
    // without a single form field changing - see updateSession().
    _cartRecalculated: false,
    // True between WooCommerce's update_checkout and updated_checkout - the
    // window where the amount is already moving and nothing here knows it yet.
    _wcUpdating: false,
    _wcUpdatingTimer: null,
    // Absolute deadline for releasing a deferred decision. Armed once, never
    // re-armed - see _armDecisionDeadline().
    _pendingDecisionTimer: null,
    // Set once the permanently-mounted container has its position observers
    // attached - see watchContainerPosition().
    _containerWatched: false,
    // Pending deferred suspend - see suspendSoon().
    _suspendTimer: null,

    init: function () {
        const $ = jQuery;
        $(document.body).on('update_checkout', this.onWooUpdateStarted.bind(this));
        // WooCommerce has just rebuilt the payment box, so the slot is a brand
        // new element in a possibly different place. Re-align the (never moved,
        // never reloaded) container with it. Registered ahead of
        // onCartRecalculated, and synchronous, so the browser never paints the
        // moment between the box being replaced and the container lining up.
        $(document.body).on('updated_checkout', this.syncContainerPosition.bind(this));
        $(document.body).on('updated_checkout', this.onCartRecalculated.bind(this));
        $(document.body).on('checkout_error', this.onCheckoutError.bind(this));
        $(document.body).on('applied_coupon_in_checkout removed_coupon_in_checkout', function () {
            // Clear hash so the next update is never skipped after coupon change
            window.briqpayCheckout._lastPayloadHash = '';
            window.briqpayCheckout.onCartRecalculated();
        });
        $(document.body).on('updated_shipping_method', this.onCartRecalculated.bind(this));
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

    /**
     * WooCommerce is about to recalculate the cart.
     *
     * Deferring a decision only while one of OUR syncs is scheduled or running
     * leaves a hole: between this event and updated_checkout, WooCommerce is
     * recalculating and the amount may already be changing, but nothing here is
     * scheduled or in flight yet, so a customer clicking pay in that window had
     * their purchase decided against the amount from before it. Anything that
     * refreshes the checkout opens this window - a VAT number being validated in
     * the background is simply the one that made it easy to hit.
     */
    onWooUpdateStarted: function () {
        var self = this;

        this._wcUpdating = true;

        // updated_checkout is not guaranteed to arrive - a request can fail or be
        // superseded - and a decision must never wait on it forever. Releasing
        // late is recoverable; never releasing is a customer stuck on a spinner.
        clearTimeout(this._wcUpdatingTimer);
        this._wcUpdatingTimer = setTimeout(function () {
            self._wcUpdating = false;
            self._wcUpdatingTimer = null;
            self._processPendingDecision();
        }, 10000);
    },

    /**
     * WooCommerce has just recalculated the cart server-side.
     *
     * Note this separately from the form fingerprint: WooCommerce recalculates
     * for reasons that never touch the checkout form, and the resulting amount
     * is what the customer is about to be charged.
     */
    onCartRecalculated: function () {
        this._cartRecalculated = true;
        // The window is closed. Any decision held open by it is released by the
        // sync this triggers, through _finishUpdate(), so it is decided against
        // the amount WooCommerce just settled on rather than the one before it.
        this._wcUpdating = false;
        this.onUpdatedCheckout();
    },

    onUpdatedCheckout: function () {
        var self = this;
        const $ = jQuery;

        // Debounce: collapse multiple rapid 'updated_checkout' events into one call.
        clearTimeout(self._updateDebounceTimer);

        // The first initialisation has nothing to collapse - there is no session yet
        // and no request in flight - so it goes on the next tick instead of waiting
        // out the debounce. Still a setTimeout rather than a direct call, so the
        // current event handler finishes and the DOM is settled before we read it.
        // Safe against duplicate requests because _isInitializing gates initIframe()
        // and clearTimeout above still collapses a burst of events into one.
        var delay = (!self.session && !self._isInitializing) ? 0 : 250;

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
        }, delay);
    },

    forceUpdate: function () {
        clearTimeout(this._updateDebounceTimer);
        this._updateDebounceTimer = null;
        this.initOrUpdate();
    },

    /**
     * Find or create the persistent element the live iframe actually lives in,
     * inserting it into the current disposable slot if it does not exist yet
     * (the very first render, or any state where it was lost some other way).
     *
     * "Find or create" rather than "always create": creating a fresh one
     * unconditionally would be exactly the destroy-and-recreate this whole
     * mechanism exists to avoid.
     *
     * @return jQuery The container, already in the DOM. Empty result only
     *                 when there is no slot to put one in either (e.g. Blocks,
     *                 which never renders #briqpay-iframe-slot at all).
     */
    ensureContainer: function () {
        const $ = jQuery;
        var $container = $('#briqpay-iframe-container');

        if ($container.length) {
            return $container;
        }

        var $slot = $('#briqpay-iframe-slot');
        if (!$slot.length) {
            return $container; // empty jQuery set (Blocks has its own container)
        }

        // Mounted on <body>, ONCE, and never moved again.
        //
        // The iframe cannot live inside the payment box. WooCommerce replaces
        // that box wholesale on every checkout refresh, and earlier releases
        // tried to survive it by detaching the container just before and
        // reattaching just after. Measured on a live checkout, that cost TWO
        // iframe loads per refresh: detaching a node removes it from the
        // document, and removing an iframe discards its browsing context, so
        // it reloads from scratch when reattached. The element object survives
        // the round trip, which is why an identity check said it was fine; the
        // iframe inside it did not. That reloading is what the merchant saw as
        // the payment window bouncing.
        //
        // So it is never moved at all. It sits on <body> for the life of the
        // page, positioned over the slot by syncContainerPosition(), which the
        // payment box is free to destroy and rebuild as often as it likes.
        $container = $('<div id="briqpay-iframe-container"></div>');
        $(document.body).append($container);

        this.watchContainerPosition();
        this.syncContainerPosition();

        return $container;
    },

    /**
     * Keep the permanently-mounted container sitting exactly over the slot.
     *
     * The slot is the disposable div payment_fields() renders inside the
     * payment box: it marks where the iframe belongs, reserves its height so
     * the surrounding layout is correct, and is replaced on every refresh.
     * Nothing of value lives in it.
     */
    syncContainerPosition: function () {
        var container = document.getElementById('briqpay-iframe-container');
        var slot = document.getElementById('briqpay-iframe-slot');

        if (!container || !slot) {
            return;
        }

        var sr = slot.getBoundingClientRect();

        // A hidden payment box (another gateway selected, or the box collapsed)
        // measures zero. Positioning against that would strand the iframe at
        // the top of the document, so leave it where it is and hide it instead.
        if (!sr.width && !sr.height) {
            container.style.visibility = 'hidden';
            return;
        }

        container.style.visibility = '';

        // Relative to <body>'s own box, so this holds whether or not a theme
        // gives <body> a position of its own.
        var br = document.body.getBoundingClientRect();

        container.style.position = 'absolute';
        container.style.top = (sr.top - br.top) + 'px';
        container.style.left = (sr.left - br.left) + 'px';
        container.style.width = sr.width + 'px';

        // The container is out of flow, so the slot has to hold its height or
        // everything below the payment box sits too high.
        //
        // Measured AFTER the positioning above, and that order is load-bearing.
        // Briqpay's own wrapper inside the container carries a bottom margin.
        // While the container is in normal flow that margin collapses out of it
        // and takes up space in the payment box; once the container is
        // absolutely positioned it establishes its own formatting context and
        // the margin is contained, making it taller. Measured on the merchant's
        // checkout that is a 32px difference - reserve the before value and the
        // payment box ends up 32px short, which is a layout shift on every
        // single refresh. Measure it in its final, positioned state.
        var height = container.offsetHeight;
        if (height) {
            slot.style.minHeight = height + 'px';
        }
    },

    /**
     * Re-sync whenever anything could have moved the slot: the iframe resizing
     * itself (Briqpay's SDK does this as the customer moves through payment),
     * the page reflowing, or the window changing size. updated_checkout is
     * handled separately, synchronously, so no paint happens between
     * WooCommerce replacing the box and the container lining up with it again.
     */
    watchContainerPosition: function () {
        var self = this;

        if (this._containerWatched) {
            return;
        }
        this._containerWatched = true;

        var sync = function () { self.syncContainerPosition(); };

        jQuery(window).on('resize.briqpay orientationchange.briqpay', sync);

        if (typeof ResizeObserver === 'function') {
            var ro = new ResizeObserver(sync);
            var container = document.getElementById('briqpay-iframe-container');
            if (container) {
                ro.observe(container);
            }
            ro.observe(document.body);
        }
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
            // Empty on a fresh page load. The server needs to know that, or it may
            // skip the update and answer without a snippet to render.
            client_session_id: this.session || '',
            checkout_data: $('form.checkout, form#order_review, form.woocommerce-checkout').serialize()
        };

        if (data) {
            requestData.blocks_data = JSON.stringify(data);
        }

        // Seed the fingerprint from the form we are about to send, so the next
        // updateSession() recognises an unchanged payload and skips the request
        // entirely. Without this the sync right after a create always fired.
        this._lastPayloadHash = this._payloadHash(data);
        this._cartRecalculated = false;
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
                        // ensureContainer() rather than assuming it already exists:
                        // the very first render has only the disposable slot so far.
                        window.briqpayCheckout.ensureContainer().html(snippet);
                        window.briqpayCheckout.attachListeners();
                    } else {
                        // Success with no snippet leaves an empty checkout, and this
                        // used to pass in complete silence. Allow a retry and say so.
                        window.briqpayCheckout._lastPayloadHash = null;
                        console.error('Briqpay: session response contained no htmlSnippet - nothing to render.', response);
                    }
                } else {
                    // Nothing was drawn, so the fingerprint must not claim the form
                    // is already synced - a retry has to be allowed through.
                    window.briqpayCheckout._lastPayloadHash = null;
                    console.error('Briqpay: Failed to load session', response);
                }
                window.briqpayCheckout._isInitializing = false;

                // A recalculation that landed while the session was being created
                // was dropped by initOrUpdate()'s _isInitializing guard, and
                // nothing else is coming to pick it up. This is reachable
                // whenever something recalculates the cart twice in quick
                // succession - a VAT plugin refreshing the checkout once when the
                // number is entered and again when its VIES lookup answers - where
                // the second refresh can easily land inside this request. Without
                // this, Briqpay would keep the amount from before that lookup.
                if (window.briqpayCheckout.session && window.briqpayCheckout._cartRecalculated) {
                    window.briqpayCheckout.onUpdatedCheckout();
                }
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

        // A request is already in flight. _isInitializing only guards the FIRST
        // call, so without this a shipping change landing on top of
        // updated_checkout could start a second concurrent sync - two PATCHes
        // racing, with the loser's payload potentially applied last. Keep the
        // newest payload and run it when the current request finishes.
        if (this._isUpdating) {
            this._queuedUpdate = { data: data };
            return;
        }

        var $form = $('form.checkout, form#order_review, form.woocommerce-checkout');
        var formData = $form.serialize();
        var blocksJson = data ? JSON.stringify(data) : '';

        var payloadHash = this._payloadHash(data);

        // The fingerprint covers the checkout form and nothing else, so on its own
        // it answers the wrong question: it tells us whether the CUSTOMER changed
        // anything, when what matters is whether the AMOUNT did. WooCommerce
        // recalculating the cart is an independent way for the amount to move -
        // a VAT number validated asynchronously and flipping the cart to ex-VAT,
        // a dynamic pricing or fee plugin, a currency switcher - and in all of
        // those the form is byte-identical afterwards. Skipping on that basis
        // left Briqpay holding the pre-recalculation amount, showing the customer
        // a total the shop no longer agreed with; the purchase then either went
        // through against the stale figure or was rejected by the amount check at
        // decision time. So once WooCommerce says it recalculated, always sync.
        if (payloadHash === this._lastPayloadHash && !this._cartRecalculated) {
            this.resume();
            this._processPendingDecision();
            return;
        }

        this._lastPayloadHash = payloadHash;
        // Cleared as the request goes out, not when it returns: a recalculation
        // arriving while this one is in flight has to survive into the queued
        // update rather than be swallowed by this one.
        this._cartRecalculated = false;

        // Suspend the Briqpay iframe while we update the session, but only if
        // the update actually takes long enough to be worth it - see suspendSoon().
        this.suspendSoon();

        var requestData = {
            action: 'briqpay_get_session',
            nonce: briqpayParams.nonce,
            client_session_id: this.session || '',
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
                            window.briqpayCheckout.ensureContainer().html(response.data.htmlSnippet);
                            window.briqpayCheckout.listenersAttached = false;

                            // A brand-new iframe was just inserted, so the SDK does
                            // genuinely need a moment to come up before resume().
                            // 250ms rather than a full second - attachListeners()
                            // already retries until the SDK answers.
                            window.briqpayCheckout._lastSnippet = response.data.htmlSnippet;
                            window.briqpayCheckout.attachListeners();
                            setTimeout(function () {
                                window.briqpayCheckout._finishUpdate();
                            }, 250);
                            return; // Exit early, setTimeout handles the rest
                        }
                    }

                    // Standard PATCH: the same iframe is still on screen and the
                    // API has already answered, so there is nothing to wait for.
                    // The old unconditional 1s kept the iframe suspended long after
                    // the work was done.
                    window.briqpayCheckout._finishUpdate();
                } else {
                    console.error('Briqpay: Session sync failed', response);
                    window.briqpayCheckout._finishUpdate();
                }
            },
            error: function (xhr, status, error) {
                console.error('Briqpay: AJAX error in updateSession', error);
                window.briqpayCheckout._finishUpdate();
            }
        });
    },

    /**
     * One exit path for every session update - success, snippet swap, failure and
     * transport error alike.
     *
     * Runs a queued payload before releasing a deferred decision, so a purchase is
     * never decided against a session that is about to be updated again.
     */
    _finishUpdate: function () {
        this._isUpdating = false;

        // Run a queued update WITHOUT resuming first. Briqpay's SDK documents
        // resume() as refreshing the iframe's rendered data - resuming only to
        // suspend again a moment later for the queued sync produced a visible
        // resume -> suspend -> resume flicker, settling the iframe on data
        // that was already known stale the instant it appeared. Resume once,
        // only after the last queued update has actually run - matching the
        // existing behaviour just below, which already withholds a pending
        // decision the same way until this same "no more queue" point.
        if (this._queuedUpdate) {
            var queued = this._queuedUpdate;
            this._queuedUpdate = null;
            this.updateSession(queued.data);
            return;
        }

        this.resume();
        this._processPendingDecision();
    },

    _processPendingDecision: function () {
        if (this._pendingDecision) {
            console.log('Briqpay: Processing deferred decision...');
            var event = this._pendingDecision;
            this._pendingDecision = null;
            this.makeDecision(event);
        }
    },

    /**
     * Guarantee a deferred decision is eventually released.
     *
     * Armed once and deliberately never re-armed. Anything that refreshes the
     * checkout on a timer - a delivery-date picker, a stock countdown, a
     * third-party field that polls - fires update_checkout again and again, and
     * a deadline pushed out by each one would never arrive at all. The customer
     * would sit on a spinner with no way to complete the purchase, which is far
     * worse than deciding against an amount the server checks against Briqpay's
     * own figure anyway before anything is charged.
     */
    _armDecisionDeadline: function () {
        var self = this;

        if (this._pendingDecisionTimer) {
            return;
        }

        this._pendingDecisionTimer = setTimeout(function () {
            self._pendingDecisionTimer = null;

            if (!self._pendingDecision) {
                return;
            }

            console.log('Briqpay: Releasing deferred decision on deadline.');
            var event = self._pendingDecision;
            self._pendingDecision = null;
            self._sendDecision(event);
        }, 10000);
    },

    makeDecision: function (event) {
        // Waiting on WooCommerce can be switched off from PHP with the
        // briqpay_defer_decision_during_update filter, without a rollback, if it
        // ever misbehaves on a live store. Defaults to on; a missing param (an
        // older cached script, say) also counts as on.
        var waitForWoo = !(typeof briqpayParams !== 'undefined' && 0 === briqpayParams.defer_decision_during_update);

        // Defer while the amount is in motion: one of our syncs scheduled
        // (debounce) or in flight, or WooCommerce itself mid-recalculation.
        if (this._updateDebounceTimer || this._isUpdating || (this._wcUpdating && waitForWoo)) {
            console.log('Briqpay: Deferring decision until session sync complete.');
            this._pendingDecision = event;
            this._armDecisionDeadline();
            return;
        }

        this._sendDecision(event);
    },

    _sendDecision: function (event) {
        const $ = jQuery;
        var self = this;

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

    /**
     * Suspend, but only if this update is still running a moment from now.
     *
     * Most syncs are no-ops: the server compares a payload hash and answers
     * "nothing changed" without calling Briqpay at all, typically in well
     * under a tenth of a second. Suspending for those means the customer sees
     * the payment window lock and unlock constantly while they fill the form
     * in, for updates that never changed anything. Waiting a moment first
     * means a fast no-op finishes before the suspend ever happens, while a
     * real update - which always takes longer, since it calls Briqpay - still
     * suspends exactly as before, well before the amount could change under
     * the customer.
     */
    suspendSoon: function () {
        var self = this;

        if (this._isSuspended || this._suspendTimer) {
            return;
        }

        this._suspendTimer = setTimeout(function () {
            self._suspendTimer = null;
            self.suspend();
        }, 150);
    },

    suspend: function () {
        if (this._isSuspended) return;
        if (window._briqpay && window._briqpay.v3 && typeof window._briqpay.v3.suspend === 'function') {
            window._briqpay.v3.suspend();
            this._isSuspended = true;
        }
    },

    resume: function () {
        // Cancel a deferred suspend that has not fired yet. Without this a fast
        // update could finish first and the suspend would land afterwards, with
        // nothing left to resume it.
        clearTimeout(this._suspendTimer);
        this._suspendTimer = null;

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
