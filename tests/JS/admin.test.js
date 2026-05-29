const fs = require('fs');
const path = require('path');

describe('Briqpay Admin JS', () => {
    let $;

    beforeEach(() => {
        jest.useFakeTimers();
        // Setup DOM
        document.body.innerHTML = `
            <input type="hidden" id="briqpay_capture_order_id" value="123" />
            <div class="briqpay-capture-form" style="display:none;">
                <input type="number" class="briqpay-capture-qty" value="1" 
                    data-ref="SKU001" data-name="Product 1" data-price-inc="100" 
                    data-tax="25" data-type="physical" />
                <button class="briqpay-do-capture">Capture</button>
                <div class="briqpay-capture-loading" style="display:none;"></div>
            </div>
            <a href="#" class="briqpay-capture-form-toggle">Toggle Form</a>
        `;

        // Mock jQuery
        const jq = require('jquery');
        global.jQuery = jq;
        global.$ = jq;
        $ = jq;

        // Mock global objects
        window.briqpayAdmin = {
            ajax_url: 'https://example.com/wp-admin/admin-ajax.php',
            nonce: 'admin_nonce'
        };

        // Mock window functions
        window.alert = jest.fn();
        window.confirm = jest.fn(() => true);
        
        // Mock location.reload (read-only in JSDOM)
        delete window.location;
        window.location = { reload: jest.fn() };

        // Mock AJAX
        $.ajax = jest.fn((options) => {
            if (options.success) {
                options.success({ success: true });
            }
        });

        // Load the script
        const scriptPath = path.resolve(__dirname, '../../assets/js/admin.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        
        eval(scriptContent);

        // Trigger document ready
        $(document).trigger('ready');
        jest.runAllTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
    });

    test('should toggle capture form', () => {
        // Mock slideToggle since JSDOM doesn't support animations well
        $.fn.slideToggle = jest.fn();

        $('.briqpay-capture-form-toggle').trigger('click');
        expect($.fn.slideToggle).toHaveBeenCalled();
    });

    test('should collect items and perform capture', () => {
        $('.briqpay-do-capture').trigger('click');

        expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Are you sure'));
        expect($.ajax).toHaveBeenCalledWith(expect.objectContaining({
            data: expect.objectContaining({
                action: 'briqpay_capture_items',
                order_id: '123',
                items: [
                    {
                        reference: 'SKU001',
                        name: 'Product 1',
                        quantity: 1,
                        unitPriceIncVat: 100,
                        taxRate: 25,
                        productType: 'physical'
                    }
                ]
            })
        }));
        expect(window.location.reload).toHaveBeenCalled();
    });

    test('should show alert if no items selected', () => {
        $('.briqpay-capture-qty').val(0);
        $('.briqpay-do-capture').trigger('click');

        expect(window.alert).toHaveBeenCalledWith('Please select at least one item to capture.');
        expect($.ajax).not.toHaveBeenCalled();
    });
});
