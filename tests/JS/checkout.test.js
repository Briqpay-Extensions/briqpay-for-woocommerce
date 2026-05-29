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
