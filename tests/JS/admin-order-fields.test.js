const fs = require('fs');
const path = require('path');

/**
 * The Briqpay-only order fields follow the payment method dropdown on the order
 * edit screen, so a merchant building a manual order sees them the moment
 * Briqpay is chosen - not only after saving the order with it.
 */
describe('Briqpay admin order fields', () => {
    let $;

    function load(selected) {
        document.body.innerHTML = `
            <select id="_payment_method">
                <option value="">— Select —</option>
                <option value="cod">Cash on delivery</option>
                <option value="briqpay">Briqpay</option>
            </select>
            <div class="order_data_column briqpay-admin-field" style="display:none;">
                <input id="_billing_org_nr" />
            </div>
        `;
        document.getElementById('_payment_method').value = selected;

        // Before jQuery loads: it captures window.setTimeout at load time, and
        // jQuery 3 runs ready callbacks through it once the document is complete.
        jest.useFakeTimers();

        const jq = require('jquery');
        global.jQuery = jq;
        global.$ = jq;
        $ = jq;

        eval(fs.readFileSync(path.resolve(__dirname, '../../assets/js/admin-order-fields.js'), 'utf8'));
        $(document).trigger('ready');
        jest.runAllTimers();
    }

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('a new manual order starts with the fields hidden', () => {
        load('');
        expect($('.briqpay-admin-field')[0].style.display).toBe('none');
    });

    test('choosing Briqpay shows the fields without saving the order', () => {
        load('');
        $('#_payment_method').val('briqpay').trigger('change');
        expect($('.briqpay-admin-field')[0].style.display).not.toBe('none');
    });

    test('switching away from Briqpay hides them again', () => {
        load('briqpay');
        expect($('.briqpay-admin-field')[0].style.display).not.toBe('none');
        $('#_payment_method').val('cod').trigger('change');
        expect($('.briqpay-admin-field')[0].style.display).toBe('none');
    });

    test('an order already paid with Briqpay shows them on load', () => {
        load('briqpay');
        expect($('.briqpay-admin-field')[0].style.display).not.toBe('none');
    });
});
