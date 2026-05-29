const fs = require('fs');
const path = require('path');

describe('Briqpay B2B Checkout JS', () => {
    let $;
    let addressUpdateCallback;

    beforeEach(() => {
        jest.useFakeTimers();

        // Setup DOM
        document.body.innerHTML = `
            <div id="briqpay-iframe-container"></div>
            <input type="hidden" id="billing_country" value="" />
            <input type="hidden" id="billing_postcode" value="" />
            <input type="hidden" id="billing_city" value="" />
            <input type="hidden" id="billing_state" value="" />
            <input type="hidden" id="shipping_country" value="" />
            <input type="hidden" id="shipping_postcode" value="" />
            <input type="hidden" id="shipping_city" value="" />
            <input type="hidden" id="shipping_state" value="" />
        `;

        // Mock jQuery
        $ = require('jquery');
        global.jQuery = $;
        global.$ = $;

        // Mock window._briqpay SDK
        addressUpdateCallback = undefined;
        const subscribeMock = jest.fn((event, cb) => {
            if (event === 'addressupdate') {
                addressUpdateCallback = cb;
            }
        });

        window._briqpay = {
            v3: {
                subscribe: subscribeMock,
                on: subscribeMock
            }
        };

        // Mock window.briqpayCheckout
        window.briqpayCheckout = {
            initOrUpdate: jest.fn(),
            suspend: jest.fn(),
            onUpdatedCheckout: jest.fn()
        };

        // Load the script
        const scriptPath = path.resolve(__dirname, '../../assets/js/briqpay-b2b-checkout.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        
        // Execute script
        eval(scriptContent);

        // Trigger document ready
        $(document).trigger('ready');
        
        // Advance timers to ensure any setTimeouts run
        jest.runAllTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('should subscribe to addressupdate event', () => {
        expect(window._briqpay.v3.subscribe).toHaveBeenCalledWith('addressupdate', expect.any(Function));
        expect(addressUpdateCallback).toBeDefined();
    });

    test('should sync billing and shipping fields on addressupdate', () => {
        const testData = {
            billingaddress: {
                country: 'SE',
                zip: '12345',
                city: 'Stockholm',
                state: 'STHLM'
            },
            shippingaddress: {
                country: 'NO',
                zip: '54321',
                city: 'Oslo',
                state: 'OSLO'
            }
        };

        // Trigger addressupdate
        addressUpdateCallback(testData);

        // Verify billing fields
        expect($('#billing_country').val()).toBe('SE');
        expect($('#billing_postcode').val()).toBe('12345');
        expect($('#billing_city').val()).toBe('Stockholm');
        expect($('#billing_state').val()).toBe('STHLM');

        // Verify shipping fields
        expect($('#shipping_country').val()).toBe('NO');
        expect($('#shipping_postcode').val()).toBe('54321');
        expect($('#shipping_city').val()).toBe('Oslo');
        expect($('#shipping_state').val()).toBe('OSLO');
    });

    test('should trigger update_checkout even if no fields changed', () => {
        const updateCheckoutSpy = jest.fn();
        $(document.body).on('update_checkout', updateCheckoutSpy);

        const testData = {
            billingaddress: {
                country: 'SE',
                zip: '12345'
            }
        };

        // Set initial values to match test data
        $('#billing_country').val('SE');
        $('#billing_postcode').val('12345');

        // Trigger addressupdate
        addressUpdateCallback(testData);

        // Verify update_checkout was triggered
        expect(updateCheckoutSpy).toHaveBeenCalled();
    });

    test('should suspend checkout iframe during update', () => {
        const testData = {
            billingaddress: { country: 'SE' }
        };

        // Trigger addressupdate
        addressUpdateCallback(testData);

        // Verify suspend was called
        expect(window.briqpayCheckout.suspend).toHaveBeenCalled();
    });
});
