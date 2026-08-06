const fs = require('fs');
const path = require('path');

describe('Briqpay Hosted Payment Page Settings JS', () => {
    let $;

    function loadScript() {
        const scriptPath = path.resolve(__dirname, '../../assets/js/hpp-settings.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        eval(scriptContent);
        $(document).trigger('ready');
        jest.runAllTimers();
    }

    function subFieldRows() {
        return $(
            '#woocommerce_briqpay_hpp_default_flow, ' +
            '#woocommerce_briqpay_hpp_page_title, ' +
            '#woocommerce_briqpay_hpp_logo_url, ' +
            '#woocommerce_briqpay_hpp_show_cart'
        ).closest('tr');
    }

    beforeEach(() => {
        jest.useFakeTimers();
        document.body.innerHTML = `
            <table class="form-table">
                <tr>
                    <th><label for="woocommerce_briqpay_hpp_enabled">Enable</label></th>
                    <td><input type="checkbox" id="woocommerce_briqpay_hpp_enabled" /></td>
                </tr>
                <tr>
                    <th><label for="woocommerce_briqpay_hpp_default_flow">Default flow</label></th>
                    <td><select id="woocommerce_briqpay_hpp_default_flow"><option value="b2c">B2C</option></select></td>
                </tr>
                <tr>
                    <th><label for="woocommerce_briqpay_hpp_page_title">Hosted page title</label></th>
                    <td><input type="text" id="woocommerce_briqpay_hpp_page_title" /></td>
                </tr>
                <tr>
                    <th><label for="woocommerce_briqpay_hpp_logo_url">Hosted page logo URL</label></th>
                    <td><input type="text" id="woocommerce_briqpay_hpp_logo_url" /></td>
                </tr>
                <tr>
                    <th><label for="woocommerce_briqpay_hpp_show_cart">Show cart</label></th>
                    <td><input type="checkbox" id="woocommerce_briqpay_hpp_show_cart" /></td>
                </tr>
            </table>
        `;

        const jq = require('jquery');
        global.jQuery = jq;
        global.$ = jq;
        $ = jq;
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('hides the sub-field rows on load when disabled', () => {
        loadScript();

        subFieldRows().each(function () {
            expect($(this).css('display')).toBe('none');
        });
    });

    test('shows the sub-field rows on load when already enabled', () => {
        $('#woocommerce_briqpay_hpp_enabled').prop('checked', true);
        loadScript();

        subFieldRows().each(function () {
            expect($(this).css('display')).not.toBe('none');
        });
    });

    test('reveals the sub-field rows when the checkbox is checked', () => {
        loadScript();
        $('#woocommerce_briqpay_hpp_enabled').prop('checked', true).trigger('change');

        subFieldRows().each(function () {
            expect($(this).css('display')).not.toBe('none');
        });
    });

    test('folds the sub-field rows again when the checkbox is unchecked', () => {
        $('#woocommerce_briqpay_hpp_enabled').prop('checked', true);
        loadScript();
        $('#woocommerce_briqpay_hpp_enabled').prop('checked', false).trigger('change');

        subFieldRows().each(function () {
            expect($(this).css('display')).toBe('none');
        });
    });

    test('does nothing when the enable checkbox is not present on the page', () => {
        document.body.innerHTML = '<p>Some other settings page</p>';
        expect(() => loadScript()).not.toThrow();
    });
});
