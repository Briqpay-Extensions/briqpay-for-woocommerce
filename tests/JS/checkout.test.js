const fs = require('fs');
const path = require('path');

describe('Briqpay Checkout JS', () => {
    let $;

    beforeEach(() => {
        jest.useFakeTimers();

        // Setup DOM
        document.body.innerHTML = `
            <body class="woocommerce-checkout">
                <form class="checkout">
                    <input type="radio" name="payment_method" id="payment_method_briqpay" value="briqpay" />
                    <input type="radio" name="payment_method" id="payment_method_cod" value="cod" />
                    <div id="briqpay-iframe-slot">
                        <div id="briqpay-iframe-container"></div>
                    </div>
                    <button id="place_order">Place Order</button>
                    <input type="text" name="billing_first_name" value="John" />
                    <input type="text" name="vat_number" value="SE559249533601" />
                </form>
            </body>
        `;

        // Mock jQuery
        $ = require('jquery');
        global.jQuery = $;
        global.$ = $;

        // Mock briqpayParams
        window.briqpayParams = {
            ajax_url: 'https://example.com/wp-admin/admin-ajax.php',
            nonce: 'test_nonce'
        };

        // Mock window._briqpay SDK
        window._briqpay = {
            v3: {
                subscribe: jest.fn(),
                suspend: jest.fn(),
                resume: jest.fn(),
                resumeDecision: jest.fn()
            }
        };

        // Mock AJAX
        $.ajax = jest.fn((options) => {
            if (options.success) {
                options.success({
                    success: true,
                    data: {
                        sessionId: 'test_session_id',
                        htmlSnippet: '<div>Iframe Content</div>'
                    }
                });
            }
            return {
                done: jest.fn().mockReturnThis(),
                fail: jest.fn().mockReturnThis(),
                always: jest.fn().mockReturnThis()
            };
        });

        // Load the script
        const scriptPath = path.resolve(__dirname, '../../assets/js/checkout.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        eval(scriptContent);

        // Initial init is called at the end of the script
        jest.runAllTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('should toggle classes when payment method changes', () => {
        const briqpayRadio = $('#payment_method_briqpay');
        const codRadio = $('#payment_method_cod');

        // Select Briqpay
        briqpayRadio.prop('checked', true).trigger('change');
        expect($('body').hasClass('briqpay-selected')).toBe(true);

        // Select COD
        codRadio.prop('checked', true).trigger('change');
        expect($('body').hasClass('briqpay-selected')).toBe(false);
        expect($('body').hasClass('briqpay-not-selected')).toBe(true);
    });

    test('should init iframe if not already present', () => {
        $('#payment_method_briqpay').prop('checked', true).trigger('change');
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_get_session'
            })
        }));
        expect(window.briqpayCheckout.session).toBe('test_session_id');
        expect($('#briqpay-iframe-container').html()).toContain('Iframe Content');
    });

    test('should update session when fields change', () => {
        // Briqpay must be the selected payment method for a sync to happen at all.
        // Sessions are deliberately NOT synced while another gateway is selected -
        // see the 'should not sync session when another gateway is selected' test below.
        $('#payment_method_briqpay').prop('checked', true);

        // First init
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');

        // Trigger a field change
        $('input[name="billing_first_name"]').val('Jane').trigger('change');
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_get_session'
            })
        }));
        expect(window._briqpay.v3.suspend).toHaveBeenCalled();
    });

    test('syncs after WooCommerce recalculates, even when the form is unchanged', () => {
        // The bug this guards: the sync was skipped whenever the checkout form
        // serialized identically to last time, which answers the wrong question.
        // A VAT number validated asynchronously flips the cart to ex-VAT through
        // WooCommerce alone - no form field changes - so Briqpay was left holding
        // the amount from before the recalculation.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');

        // Seed the fingerprint exactly as a completed sync of this form would.
        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();
        $.ajax.mockClear();

        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_get_session'
            })
        }));
    });

    test('still skips a redundant sync when neither the form nor the cart moved', () => {
        // The other half: without this the fix would turn every no-op into a
        // request and a suspend/resume of the iframe.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');

        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();
        window.briqpayCheckout._cartRecalculated = false;
        $.ajax.mockClear();
        window._briqpay.v3.suspend.mockClear();

        window.briqpayCheckout.updateSession();

        expect($.ajax).not.toHaveBeenCalled();
        // Nothing was sent, so the iframe must not have been locked either.
        expect(window._briqpay.v3.suspend).not.toHaveBeenCalled();
    });

    test('resume() does not fire between a queued update and the sync that follows it', () => {
        // Bug this guards: _finishUpdate() used to call resume() unconditionally
        // before checking for a queued update, so a queued sync immediately
        // suspended the iframe again - a visible resume -> suspend -> resume
        // flicker, since Briqpay's SDK refreshes the iframe's rendered data on
        // every resume(). Resume must fire at most once, only after the LAST
        // update in the chain has actually finished.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();

        const calls = [];
        window._briqpay.v3.suspend = jest.fn(() => calls.push('suspend'));
        window._briqpay.v3.resume = jest.fn(() => calls.push('resume'));

        let release;
        $.ajax = jest.fn((options) => {
            release = () => options.success({ success: true, data: { sessionId: 'existing_session' } });
            return { done: jest.fn(), fail: jest.fn(), always: jest.fn() };
        });

        // First sync starts (suspends). A second, genuinely different change
        // (a distinct payload, so its later replay is not just recognised as
        // the same thing already sent and skipped) lands and queues while the
        // first is still in flight.
        window.briqpayCheckout._cartRecalculated = true;
        window.briqpayCheckout.updateSession();
        window.briqpayCheckout.updateSession({ changed: 'yes' });

        // First sync's response arrives. The queued one must start (suspend
        // again) WITHOUT an intervening resume().
        // The first response arrives and, in the same tick, starts the queued
        // sync. suspend() is itself idempotent (a no-op while already
        // suspended), so with resume() no longer called first, the iframe was
        // never released in between - there is nothing here for a second
        // suspend() to even need to do. The one and only 'resume' must not
        // have happened yet: the queued sync is still in flight.
        release();
        jest.runAllTimers();

        expect(calls).toEqual(['suspend']);

        // Now the queued sync itself finishes - only now may it resume.
        release();
        jest.runAllTimers();

        expect(calls).toEqual(['suspend', 'resume']);
    });

    test('the second of two recalculations still reaches Briqpay (VAT plugin pattern)', () => {
        // A VAT plugin refreshes the checkout twice for one VAT number: once as
        // soon as it is entered, and again when the VIES lookup answers and the
        // exemption is actually applied. The second refresh carries the ex-VAT
        // amount, and the form is identical by then, so it is the one that used
        // to be dropped.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();
        $.ajax.mockClear();

        // First refresh: field entered, exemption not applied yet.
        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();
        expect($.ajax).toHaveBeenCalledTimes(1);

        // Second refresh, seconds later: VIES answered, cart is now ex-VAT.
        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();
        expect($.ajax).toHaveBeenCalledTimes(2);
    });

    test('a recalculation landing while the session is being created is reconciled', () => {
        // initOrUpdate() refuses to run while a session is being created, so the
        // second of the two refreshes above lands on nothing if it arrives inside
        // that request. Once the session exists it has to be picked up.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = null;
        $('#briqpay-iframe-container').empty();

        // Hold the create open so the recalculation lands mid-flight.
        let finishCreate;
        $.ajax = jest.fn((options) => {
            finishCreate = () => options.success({
                success: true,
                data: { sessionId: 'new_session', htmlSnippet: '<div>Iframe</div>' }
            });
            return { done: jest.fn(), fail: jest.fn(), always: jest.fn() };
        });

        window.briqpayCheckout.initIframe();
        expect($.ajax).toHaveBeenCalledTimes(1);

        // VIES answers while the session is still being created.
        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();
        expect($.ajax).toHaveBeenCalledTimes(1); // dropped by the _isInitializing guard

        finishCreate();
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledTimes(2);
    });

    test('clearing the VAT number syncs the amount back to including VAT', () => {
        // The other direction. Removing the number changes the serialized form,
        // so this path was already reaching Briqpay - the test is here so it
        // stays that way, since the exemption coming back off matters exactly as
        // much as it going on.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();
        window.briqpayCheckout._cartRecalculated = false;
        $.ajax.mockClear();

        $('input[name="vat_number"]').val('').trigger('change');
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_get_session',
                checkout_data: expect.stringContaining('vat_number=')
            })
        }));
    });

    test('a recalculation arriving mid-sync is not swallowed by the one in flight', () => {
        // The flag is cleared as the request goes out, so a recalculation that
        // lands while it is in flight survives into the queued update instead of
        // being counted as already handled.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        window.briqpayCheckout._lastPayloadHash = window.briqpayCheckout._payloadHash();

        // Hold the first request open so the second arrives while it is running.
        let release;
        $.ajax = jest.fn((options) => {
            release = () => options.success({
                success: true,
                data: { sessionId: 'existing_session' }
            });
            return { done: jest.fn(), fail: jest.fn(), always: jest.fn() };
        });

        window.briqpayCheckout._cartRecalculated = true;
        window.briqpayCheckout.updateSession();
        expect($.ajax).toHaveBeenCalledTimes(1);

        // Second recalculation, same untouched form, while the first is in flight.
        window.briqpayCheckout._cartRecalculated = true;
        window.briqpayCheckout.updateSession();

        release();
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledTimes(2);
    });

    test('should not sync session when another gateway is selected', () => {
        // Regression guard for the "other gateways become unusable" fix: when a
        // different gateway is selected, Briqpay must not create/patch a session
        // and must not hide the native Place Order button.
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        $.ajax.mockClear();

        $('#payment_method_cod').prop('checked', true).trigger('change');
        $('input[name="billing_first_name"]').val('Jane').trigger('change');
        jest.runAllTimers();

        expect($.ajax).not.toHaveBeenCalled();
        expect($('body').hasClass('briqpay-selected')).toBe(false);
        expect($('body').hasClass('briqpay-not-selected')).toBe(true);
    });

    test('a decision during WooCommerce recalculation waits for the new amount', () => {
        // The gap between update_checkout and updated_checkout: WooCommerce is
        // recalculating, so the amount may already be moving, but no sync of ours
        // is scheduled or running yet. A decision sent here is decided against the
        // amount from before the recalculation.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        $.ajax.mockClear();

        $(document.body).trigger('update_checkout');

        window.briqpayCheckout.makeDecision({ sessionId: 'existing_session' });

        // Held, not sent.
        expect($.ajax).not.toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({ action: 'briqpay_make_decision' })
        }));
        expect(window.briqpayCheckout._pendingDecision).not.toBeNull();

        // WooCommerce settles; our sync runs and releases the decision after it.
        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({ action: 'briqpay_make_decision' })
        }));
    });

    test('a decision is never stranded if updated_checkout never arrives', () => {
        // A failed or superseded request can leave updated_checkout unfired.
        // Releasing late is recoverable; never releasing strands the customer.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        $.ajax.mockClear();

        $(document.body).trigger('update_checkout');
        window.briqpayCheckout.makeDecision({ sessionId: 'existing_session' });
        expect(window.briqpayCheckout._pendingDecision).not.toBeNull();

        // updated_checkout never comes.
        jest.advanceTimersByTime(10000);
        jest.runAllTimers();

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({ action: 'briqpay_make_decision' })
        }));
    });

    test('the live iframe is parked before WooCommerce replaces the payment box, and restored after', () => {
        // The bug this exists for: WooCommerce rebuilds the WHOLE payment box on
        // every single order-review refresh, unconditionally, for every gateway -
        // core behaviour, not something a payment plugin can opt out of. Simulate
        // that literally: replace the slot's markup the way WooCommerce's own
        // fragment replacement does, and confirm the live iframe (identified by a
        // marker property only a real, never-destroyed DOM node would still carry)
        // survives it.
        const liveIframe = document.createElement('iframe');
        liveIframe.markerOnlyTheRealNodeHas = 'still alive';
        $('#briqpay-iframe-container').empty().append(liveIframe);

        $(document.body).trigger('update_checkout');

        // Parked: pulled out of the slot before WooCommerce's replacement runs.
        expect(document.getElementById('briqpay-iframe-container')).not.toBeNull();
        expect($('#briqpay-iframe-slot').find('#briqpay-iframe-container').length).toBe(0);

        // WooCommerce's own replacement: the slot's ENTIRE markup is discarded and
        // rebuilt from scratch, exactly as payment_fields() being called again
        // does. If the container were still inside it, this line would destroy it.
        $('#briqpay-iframe-slot').html('<div id="briqpay-iframe-slot-inner"></div>');

        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();

        // Restored into the (new) slot, and it is the SAME node - not a rebuilt one.
        const restoredIframe = document.querySelector('#briqpay-iframe-container iframe');
        expect(restoredIframe).not.toBeNull();
        expect(restoredIframe.markerOnlyTheRealNodeHas).toBe('still alive');
        expect($('#briqpay-iframe-container').parent().is('#briqpay-iframe-slot')).toBe(true);
    });

    test('parking is a no-op with nothing live in the container yet', () => {
        // The very first render, or any point before a session exists: nothing to
        // protect, and detaching an empty container would just be pointless work.
        $('#briqpay-iframe-container').empty();

        $(document.body).trigger('update_checkout');

        expect(window.briqpayCheckout._containerParked).toBe(false);
    });

    test('a second update_checkout while already parked does not push the restore deadline out', () => {
        // The same bug class the payment-decision deadline was fixed for: without
        // the "already parked" guard, a repeated update_checkout re-arms the 10s
        // deadline via the same clearTimeout()+setTimeout() pair parkContainer()
        // uses to start it, so a checkout refreshing on a timer could push it out
        // forever and leave the payment box permanently blank.
        $('#briqpay-iframe-container').html('<iframe></iframe>');

        $(document.body).trigger('update_checkout'); // t=0, deadline armed for t=10000
        expect(window.briqpayCheckout._containerParked).toBe(true);

        jest.advanceTimersByTime(3000); // t=3000
        $(document.body).trigger('update_checkout'); // must NOT re-arm to t=13000

        // Past the ORIGINAL deadline (t=10000) but not a reset one (t=13000) - this
        // only elapses if the second trigger left the first deadline alone.
        jest.advanceTimersByTime(7001); // t=10001

        expect(window.briqpayCheckout._containerParked).toBe(false);
        expect($('#briqpay-iframe-container').parent().is('#briqpay-iframe-slot')).toBe(true);
    });

    test('a parked container is restored even if updated_checkout never arrives', () => {
        // The same reasoning as the payment-decision deadline: a failed or
        // superseded request can leave updated_checkout unfired. Leaving the
        // payment box permanently blank is worse than restoring late.
        $('#briqpay-iframe-container').html('<iframe></iframe>');

        $(document.body).trigger('update_checkout');
        expect(window.briqpayCheckout._containerParked).toBe(true);

        jest.advanceTimersByTime(10000);

        expect(window.briqpayCheckout._containerParked).toBe(false);
        expect($('#briqpay-iframe-container').parent().is('#briqpay-iframe-slot')).toBe(true);
    });

    test('restoring is a no-op when nothing was parked', () => {
        // updated_checkout can fire for reasons that never involved a park (a
        // sync triggered some other way) - must not move anything unexpectedly.
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        const parentBefore = $('#briqpay-iframe-container').parent()[0];

        $(document.body).trigger('updated_checkout');
        jest.runAllTimers();

        expect($('#briqpay-iframe-container').parent()[0]).toBe(parentBefore);
    });

    test('ensureContainer creates the container inside the slot when neither exists yet', () => {
        $('#briqpay-iframe-container').remove();

        const $created = window.briqpayCheckout.ensureContainer();

        expect($created.attr('id')).toBe('briqpay-iframe-container');
        expect($created.parent().is('#briqpay-iframe-slot')).toBe(true);
    });

    test('ensureContainer returns nothing when there is no slot either (e.g. Blocks)', () => {
        $('#briqpay-iframe-container').remove();
        $('#briqpay-iframe-slot').remove();

        expect(window.briqpayCheckout.ensureContainer().length).toBe(0);
    });

    test('a deferred decision survives a checkout that keeps refreshing', () => {
        // A plugin refreshing the checkout on a timer fires update_checkout over
        // and over. A release deadline reset by each one would never arrive and
        // the pay button would be dead for good - the worst failure this plugin
        // has available to it.
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        $.ajax.mockClear();

        $(document.body).trigger('update_checkout');
        window.briqpayCheckout.makeDecision({ sessionId: 'existing_session' });
        expect(window.briqpayCheckout._pendingDecision).not.toBeNull();

        // Something keeps refreshing, and updated_checkout never lands.
        for (var i = 0; i < 10; i++) {
            jest.advanceTimersByTime(3000);
            $(document.body).trigger('update_checkout');
        }

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({ action: 'briqpay_make_decision' })
        }));
    });

    test('the wait for WooCommerce can be switched off from PHP', () => {
        // The escape hatch: this sits on the pay button of every checkout, so a
        // live store must be able to fall back to the old behaviour without a
        // rollback.
        window.briqpayParams.defer_decision_during_update = 0;
        $('#payment_method_briqpay').prop('checked', true);
        window.briqpayCheckout.session = 'existing_session';
        $('#briqpay-iframe-container').html('<iframe></iframe>');
        $.ajax.mockClear();

        $(document.body).trigger('update_checkout');
        window.briqpayCheckout.makeDecision({ sessionId: 'existing_session' });

        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({ action: 'briqpay_make_decision' })
        }));
    });

    test('should attach listeners to SDK events', () => {
        window.briqpayCheckout.listenersAttached = false;
        window.briqpayCheckout.attachListeners();
        
        const subscribeMock = window._briqpay.v3.subscribe;
        expect(subscribeMock).toHaveBeenCalledWith('make_decision', expect.any(Function));
        expect(subscribeMock).toHaveBeenCalledWith('order_completed', expect.any(Function));
    });

    test('should defer decision if update is in progress', () => {
        window.briqpayCheckout._isUpdating = true;
        const event = { sessionId: '123' };
        
        window.briqpayCheckout.makeDecision(event);
        
        expect(window.briqpayCheckout._pendingDecision).toBe(event);
        expect($.ajax).not.toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_make_decision'
            })
        }));
    });
});
