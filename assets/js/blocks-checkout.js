const briqpayBlockConfig = (window.wc && window.wc.wcSettings) ? window.wc.wcSettings.getSetting('briqpay_data', {}) : {};
const { registerPaymentMethod } = window.wc ? window.wc.wcBlocksRegistry : { registerPaymentMethod: () => { } };
const { createElement, useEffect } = window.wp.element;
const { decodeEntities } = window.wp.htmlEntities;
const { __ } = window.wp.i18n;

/**
 * Briqpay Content Component for WooCommerce Blocks Checkout
 */
const BriqpayContent = (props) => {
    const { billing, shipping, shippingData, cart, cartData, cartTotals, checkoutStatus, activePaymentMethod } = props;

    // Toggle body class for button visibility
    const restorePlaceOrderButton = () => {
        document.body.classList.remove('briqpay-selected');
        document.body.classList.add('briqpay-not-selected');

        const buttons = document.querySelectorAll('#place_order, .form-row.place-order, .wc-block-checkout__actions, .wc-block-components-checkout-place-order-button, [data-testid="wc-block-components-checkout-place-order-button"]');
        buttons.forEach(btn => {
            btn.style.display = '';
            btn.style.visibility = '';
            btn.style.opacity = '';
            btn.style.pointerEvents = '';
            btn.style.height = '';
            btn.style.maxHeight = '';
            btn.style.overflow = '';
            btn.removeAttribute('aria-hidden');
            btn.removeAttribute('disabled');
        });
    };

    useEffect(() => {
        if (activePaymentMethod === 'briqpay') {
            document.body.classList.add('briqpay-selected');
            document.body.classList.remove('briqpay-not-selected');
        } else if (activePaymentMethod && activePaymentMethod !== 'briqpay') {
            restorePlaceOrderButton();
        }

        // WooCommerce Blocks only mounts this component while Briqpay is the active
        // payment method — the moment the shopper picks a different method, this
        // component unmounts immediately, before it can ever see the new
        // activePaymentMethod value. The cleanup function is the only reliable place
        // to catch that transition and undo the button hiding.
        return () => {
            restorePlaceOrderButton();
        };
    }, [activePaymentMethod]);

    // Extract shipping methods from all possible prop locations.
    // WooCommerce Blocks passes shippingData in different shapes across versions.
    const extractShippingMethods = () => {
        // Try the most common locations
        if (shippingData && shippingData.selectedShippingMethods) return shippingData.selectedShippingMethods;
        if (props.shippingData && props.shippingData.selectedShippingMethods) return props.shippingData.selectedShippingMethods;

        // WC Blocks sometimes nests under shippingData.shippingRates
        if (shippingData && shippingData.shippingRates && Array.isArray(shippingData.shippingRates)) {
            var methods = {};
            shippingData.shippingRates.forEach(function (pkg, idx) {
                if (pkg.shipping_rates) {
                    pkg.shipping_rates.forEach(function (rate) {
                        if (rate.selected) {
                            methods[idx] = rate.rate_id;
                        }
                    });
                }
            });
            if (Object.keys(methods).length > 0) return methods;
        }

        // Try via the cart extensions
        if (cartData && cartData.shippingRates && Array.isArray(cartData.shippingRates)) {
            var methods = {};
            cartData.shippingRates.forEach(function (pkg, idx) {
                if (pkg.shipping_rates) {
                    pkg.shipping_rates.forEach(function (rate) {
                        if (rate.selected) {
                            methods[idx] = rate.rate_id;
                        }
                    });
                }
            });
            if (Object.keys(methods).length > 0) return methods;
        }

        // Try Store API cartTotals
        if (cartTotals && cartTotals.shipping_rates) {
            return cartTotals.shipping_rates;
        }

        return null;
    };

    // Map props to address objects
    const billingAddress = billing ? (billing.billingAddress || billing.cartAddress || billing) : null;
    const shippingAddress = (shippingData || shipping) ? (
        (shippingData ? (shippingData.shippingAddress || shippingData.cartAddress) : null) ||
        (shipping ? (shipping.shippingAddress || shipping) : null)
    ) : null;
    const selectedShippingMethods = extractShippingMethods();

    // Refs for debounce timer and last-synced state snapshot
    const lastSyncRef = window.wp.element.useRef('');
    const debounceTimerRef = window.wp.element.useRef(null);

    useEffect(() => {

        // Build a state fingerprint — use the FULL cart totals object so that
        // coupons, fees, shipping, and quantity changes all trigger a sync.
        const currentState = JSON.stringify({
            billing: billingAddress,
            shipping: shippingAddress,
            rates: selectedShippingMethods,
            totals: cartTotals || cartData || cart,
            method: activePaymentMethod
        });

        if (currentState === lastSyncRef.current) return;

        const triggerSync = () => {
            if (window.briqpayCheckout && typeof window.briqpayCheckout.initOrUpdate === 'function') {
                if (typeof window.briqpayParams === 'undefined') {
                    window.briqpayParams = {
                        ajax_url: briqpayBlockConfig.ajax_url,
                        nonce: briqpayBlockConfig.nonce
                    };
                }

                lastSyncRef.current = currentState;

                // Detect if company name field is required in the blocks checkout DOM.
                // WooCommerce Blocks renders the company field with a required attribute
                // when the merchant enables it as required.
                var companyRequired = false;
                var companyInput = document.querySelector('#billing-company, input[id*="billing-company"], .wc-block-components-text-input #billing-company');
                if (companyInput) {
                    companyRequired = companyInput.required || companyInput.getAttribute('aria-required') === 'true';
                    if (!companyRequired) {
                        var label = companyInput.closest('.wc-block-components-text-input');
                        if (label && label.querySelector('.wc-block-components-text-input__label .required')) {
                            companyRequired = true;
                        }
                    }
                }

                window.briqpayCheckout.initOrUpdate({
                    billing_address: billingAddress,
                    shipping_address: shippingAddress,
                    shipping_rates: selectedShippingMethods,
                    cart_totals: cartTotals || cartData || cart,
                    company_required: companyRequired
                });
            } else {
                setTimeout(triggerSync, 200);
            }
        };

        if (activePaymentMethod === 'briqpay') {
            if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current);
            debounceTimerRef.current = setTimeout(triggerSync, 500);
        }

        return () => { if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current); };
    }, [activePaymentMethod, billing, shipping, shippingData, cart, cartData, cartTotals, checkoutStatus]);

    return createElement('div', {
        id: 'briqpay-iframe-container',
        style: { width: '100%', minHeight: '300px', marginTop: '10px' }
    }, __('Initializing payment options...', 'briqpay-for-woocommerce'));
};

const label = decodeEntities(briqpayBlockConfig.title) || __('Briqpay', 'briqpay-for-woocommerce');

const BriqpayPaymentMethod = {
    name: 'briqpay',
    label: createElement('span', null, label),
    ariaLabel: label,
    content: createElement(BriqpayContent),
    edit: createElement(BriqpayContent),
    canMakePayment: () => briqpayBlockConfig.can_make_payment !== false,
    supports: { features: briqpayBlockConfig.supports || ['products'] },
};

registerPaymentMethod(BriqpayPaymentMethod);
window.briqpayRegistered = true;
