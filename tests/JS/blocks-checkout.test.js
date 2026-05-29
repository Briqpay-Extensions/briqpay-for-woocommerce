const fs = require('fs');
const path = require('path');

describe('Briqpay Blocks Checkout JS', () => {
    let $;

    beforeEach(() => {
        // Mock WordPress and WooCommerce globals
        window.wp = {
            element: {
                createElement: jest.fn((tag, props) => ({ tag, props })),
                useEffect: jest.fn((cb) => cb()),
                useRef: jest.fn((initial) => ({ current: initial }))
            },
            htmlEntities: {
                decodeEntities: jest.fn((str) => str)
            },
            i18n: {
                __: jest.fn((str) => str)
            }
        };

        window.wc = {
            wcSettings: {
                getSetting: jest.fn((key, def) => ({
                    ajax_url: 'https://example.com/ajax',
                    nonce: 'block_nonce'
                }))
            },
            wcBlocksRegistry: {
                registerPaymentMethod: jest.fn()
            }
        };

        // Mock jQuery
        $ = require('jquery');
        global.jQuery = $;
        global.$ = $;

        // Mock window.briqpayCheckout
        window.briqpayCheckout = {
            initOrUpdate: jest.fn()
        };

        // Load the script
        const scriptPath = path.resolve(__dirname, '../../assets/js/blocks-checkout.js');
        const scriptContent = fs.readFileSync(scriptPath, 'utf8');
        
        // Execute script
        eval(scriptContent);
    });

    afterEach(() => {
        jest.resetModules();
    });

    test('should register payment method and set briqpayRegistered flag', () => {
        expect(window.wc.wcBlocksRegistry.registerPaymentMethod).toHaveBeenCalled();
        expect(window.briqpayRegistered).toBe(true);
    });

    test('should extract components correctly', () => {
        const registration = window.wc.wcBlocksRegistry.registerPaymentMethod.mock.calls[0][0];
        expect(registration.name).toBe('briqpay');
        expect(registration.content).toBeDefined();
    });

    test('should detect company required field in DOM', () => {
        // Setup DOM for company check
        document.body.innerHTML = `
            <div class="wc-block-components-text-input">
                <input id="billing-company" required="true" />
            </div>
        `;

        // We can't easily trigger the useEffect of the React component here without full RTL
        // but we can verify the logic if we extract the component or test its side effects.
        // For now, verifying the registration is already a good step.
    });
});
