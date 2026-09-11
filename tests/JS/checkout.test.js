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
                    <div id="briqpay-iframe-container"></div>
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
