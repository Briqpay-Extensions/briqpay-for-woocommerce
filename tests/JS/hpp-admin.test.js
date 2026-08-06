const fs = require('fs');
const path = require('path');

describe('Briqpay Hosted Payment Page Admin JS', () => {
    let $;

    beforeEach(() => {
        jest.useFakeTimers();

        document.body.innerHTML = `
            <input type="hidden" id="briqpay_hpp_order_id" value="123" />
            <select id="briqpay_hpp_flow">
                <option value="b2c" selected>B2C</option>
                <option value="b2b_payment_module">B2B Payment Module</option>
                <option value="b2b_checkout">B2B Checkout</option>
            </select>
            <button type="button" class="button button-primary briqpay-hpp-create">Create hosted payment page</button>
            <button type="button" class="button briqpay-hpp-regenerate" data-flow="b2b_checkout">Regenerate</button>
            <input type="text" id="briqpay_hpp_url" class="widefat" readonly value="https://hp.briqpay.com/payment/x/y" />
            <button type="button" class="button briqpay-hpp-copy">Copy link</button>
            <div class="briqpay-hpp-result"></div>
            <div class="briqpay-hpp-loading" style="display:none;"></div>
        `;

        const jq = require('jquery');
        global.jQuery = jq;
        global.$ = jq;
        $ = jq;

        window.briqpayHpp = {
            ajax_url: 'https://example.com/wp-admin/admin-ajax.php',
            nonce: 'hpp_nonce',
            confirm_create: 'Create a Briqpay hosted payment page for this order?',
            confirm_regen: 'This creates a NEW payment session and invalidates the previous link. Continue?',
            error_generic: 'Could not create the hosted payment page.',
            copied: 'Copied!'
        };

        window.confirm = jest.fn(() => true);
        window.alert = jest.fn();

        delete window.location;
        window.location = { reload: jest.fn() };

        // Default: AJAX always succeeds.
        $.ajax = jest.fn((options) => {
            if (options.success) {
                options.success({ success: true, data: { url: 'https://hp.briqpay.com/payment/new' } });
            }
        });

        const scriptPath = path.resolve(__dirname, '../../assets/js/hpp-admin.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        eval(scriptContent);

        $(document).trigger('ready');
        jest.runAllTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('posts the selected flow, order id and nonce to the create action', () => {
        $('#briqpay_hpp_flow').val('b2b_payment_module');
        $('.briqpay-hpp-create').trigger('click');

        expect(window.confirm).toHaveBeenCalledWith(window.briqpayHpp.confirm_create);
        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            url: window.briqpayHpp.ajax_url,
            type: 'POST',
            data: expect.objectContaining({
                action: 'briqpay_create_hosted_page',
                nonce: 'hpp_nonce',
                order_id: '123',
                flow: 'b2b_payment_module'
            })
        }));
    });

    test('aborts without an AJAX call when the confirm dialog is declined', () => {
        window.confirm = jest.fn(() => false);

        $('.briqpay-hpp-create').trigger('click');

        expect($.ajax).not.toHaveBeenCalled();
    });

    test('reloads the page on success', () => {
        $('.briqpay-hpp-create').trigger('click');

        expect(window.location.reload).toHaveBeenCalled();
    });

    test('renders the server error message and re-enables the button on failure', () => {
        $.ajax = jest.fn((options) => {
            if (options.success) {
                options.success({ success: false, data: { message: 'Total mismatch.' } });
            }
        });

        const $btn = $('.briqpay-hpp-create');
        $btn.trigger('click');

        expect($('.briqpay-hpp-result').text()).toBe('Total mismatch.');
        expect($btn.prop('disabled')).toBe(false);
        expect($('.briqpay-hpp-loading').is(':visible')).toBe(false);
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    test('falls back to the generic error message when the server sends none', () => {
        $.ajax = jest.fn((options) => {
            if (options.success) {
                options.success({ success: false });
            }
        });

        $('.briqpay-hpp-create').trigger('click');

        expect($('.briqpay-hpp-result').text()).toBe(window.briqpayHpp.error_generic);
    });

    test('sends confirm_regen and the existing flow when regenerating', () => {
        $('.briqpay-hpp-regenerate').trigger('click');

        expect(window.confirm).toHaveBeenCalledWith(window.briqpayHpp.confirm_regen);
        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_create_hosted_page',
                order_id: '123',
                flow: 'b2b_checkout'
            })
        }));
    });

    test('copies the URL using the execCommand fallback when navigator.clipboard is absent', () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        document.execCommand = jest.fn(() => true);

        $('.briqpay-hpp-copy').trigger('click');

        expect(document.execCommand).toHaveBeenCalledWith('copy');
        expect($('.briqpay-hpp-copy').text()).toBe('Copied!');
    });

    test('uses the clipboard API when available instead of execCommand', () => {
        const writeText = jest.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        document.execCommand = jest.fn();

        $('.briqpay-hpp-copy').trigger('click');

        expect(writeText).toHaveBeenCalledWith('https://hp.briqpay.com/payment/x/y');
        expect(document.execCommand).not.toHaveBeenCalled();
    });
});
