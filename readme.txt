=== Briqpay for WooCommerce ===
Contributors: briqpay
Donate link: https://briqpay.com
Tags: payments, gateway, briqpay, ecommerce, checkout
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.13
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Connect multiple payment providers through one integration to increase conversion, reduce costs, and simplify your checkout.

== Description ==

Simplify your payments with Briqpay. 

Briqpay connects multiple payment providers like Adyen, Stripe, PayPal, and Klarna in one integration, removing the need for multiple plugins and reducing technical complexity. Merchants can choose the best provider per market to lower fees, increase flexibility, and improve conversion.

**About Briqpay**
Briqpay is the payment optimization platform that allows merchants to connect multiple payment providers through a single integration. Instead of building and maintaining separate integrations to each provider, Briqpay gives merchants a unified payment layer to combine providers, add new payment methods, and optimize the checkout experience per market.

With built-in routing, analytics, and a unified payment flow, Briqpay helps commerce teams improve conversion, reduce payment costs, and scale globally with full control over their payment setup.

Briqpay supports payment setups for all your customer types: B2C, D2C, and B2B.

= Main features =
* **One integration instead of many:** Connect to PayPal, Adyen, Stripe, Klarna and others through one integration.
* **Built for international commerce:** Supports all countries, currencies, payment methods, B2C and B2B.
* **Easy to add or switch payment providers:** Add new payment methods or change payment provider without rebuilding your checkout.
* **Full control over costs and routing:** Control which provider to use per market, currency, or order value.
* **All payment methods work together:** Cards, BNPL, wallets and local payment methods work in the same checkout without conflicts.
* **Always up to date:** Payment methods are updated in one place, so you don’t need to maintain multiple plugins.
* **Built-in analytics and insights:** Analyze conversion, payment method performance, and customer payment behavior across markets in one interface.
* **Consistent payment flow:** Capture, refund, and order handling works the same for every payment method.
* **Blocks Support:** Full support for the newer WooCommerce Checkout Block and classic shortcodes.

== External services ==

This plugin connects to Briqpay to process payments. Briqpay is a payment service provider that streamlines multiple payment methods into a single integration. 

The plugin communicates with the following external endpoints to initialize and verify payment sessions:

* https://api.briqpay.com (Production API)
* https://playground-api.briqpay.com (Test/Staging API)

When you use this plugin, order and customer data is sent to Briqpay to enable the payment flow. This includes:

* **Order Details:** Product names, SKU, quantities, prices, and taxes.
* **Customer Information:** Name, billing/shipping address, email, and phone number.
* **Transaction Data:** Currency, order ID, and total amount.

Data is transmitted when a customer accesses the checkout page, updates their checkout information (e.g., shipping methods), or when a merchant processes captures/refunds via the WooCommerce admin.

The use of this service is governed by Briqpay's legal documentation:

* **Terms of Service:** https://briqpay.com/terms
* **Privacy Policy:** https://briqpay.com/privacy-policy
* **Data Processing Agreement (DPA):** https://briqpay.com/dpa

== Installation ==

1. Install and activate the Briqpay plugin in WooCommerce.
2. Sign up for a Briqpay account at https://briqpay.com.
3. Retrieve your API credentials from the Briqpay dashboard.
4. Add your credentials in WooCommerce > Settings > Payments > Briqpay.
5. Configure your payment providers and methods in Briqpay.
6. Test your checkout using the playground environment.
7. Go live and start accepting payments.

== Changelog ==

= 1.0.13 =
* Improved Capture and Refund reliability: the plugin now fetches the current Briqpay session state before every order management action to ensure accuracy.
* Precision Integrity: Captures and refunds now use canonical prices and tax rates from the authorized session cart, eliminating rounding discrepancies.
* Auto-Recovery: Missing local capture/refund history is now automatically synchronized from the Briqpay session state.
* Expanded Test Coverage: Implemented a full suite of unit tests for core frontend JavaScript components (`checkout.js`, `admin.js`, `blocks-checkout.js`).

= 1.0.12 =
* Added native B2B company metadata: company name and CIN (corporate identification number) are now automatically saved from the Briqpay session to order meta (`_briqpay_company_name`, `_briqpay_company_cin`) — no external filter snippet required.
* Company name and CIN are now displayed in the WooCommerce admin order view, below the billing address, for all B2B orders.
* Company name (`billing_company`) is now correctly set on the WooCommerce order and customer at the decision point and on return, ensuring it appears on the thank-you page and in order confirmation emails.
* Company name is also set on `shipping_company` so it appears correctly on shipping labels and in shipping address details.

= 1.0.11 =
* Fixed B2B checkout shipping not updating correctly when address is populated from the Briqpay iframe.
* Extended `addressupdate` event handler to sync country, city and state fields — not just postcode — so WooCommerce shipping zones resolve correctly.
* `update_checkout` is now always triggered on every `addressupdate` event (not only when field values differ) to handle cases where the hidden fields already contain correct values but WooCommerce has not yet recalculated shipping.

= 1.0.10 =
* Removed incorrect order origin override — WooCommerce attribution tracking is now preserved.
* Added automatic B2B detection: when the company name field is required in standard WooCommerce checkout, customer type is forced to "business".
* UX improvements for B2B checkout.

= 1.0.9 =
* Security hardening: Added proper sanitization for all `$_SERVER['REQUEST_URI']` usages.
* Security hardening: Added recursive sanitization for JSON-decoded `blocks_data` input.
* Security hardening: Added `map_deep()` sanitization on webhook payloads after `json_decode()`.
* Improved inline documentation for webhook authentication model.

= 1.0.8 =
* Fixed B2B session synchronization race condition.
* Improved payment decision reliability with deferred processing during session updates.
* Added mandatory 1000ms delay before session resume to ensure backend/frontend alignment.
* Fixed JavaScript error in MutationObserver configuration.
* Enhanced backend data integrity with forced shipping recalculation at decision point.
* Implemented automatic session cleanup after successful order completion.

= 1.0.7 =
* Added dependency "Requires Plugins: woocommerce" to plugin header.
* Added "External services" section to readme.txt for Briqpay transparency.
* Refactored script/style enqueuing to use standard WordPress functions.
* Added email validation check at decision point for B2C checkouts.
* Added automatic Briqpay session reset on user login to prevent buyer context issues.
* Improved order creation logic to preserve product variations and 3rd-party metadata (e.g. Extra Product Options).
* Fixed B2B context leaking after purchase, causing cart/mini-cart buttons to disappear.
* Fixed duplication of shipping, fees, and coupons during order creation when reusing draft orders.
* Added "Emergency Sync" to resolve amount mismatches caused by race conditions during the purchase process.
* Fixed shipping address pre-filling for logged-in users in B2B checkout.

= 1.0.6 =
* Robustly disabled B2B context persistence after order completion to prevent Cart page interference and mini-cart issues.
* Added absolute guards to `is_b2b_active` to prevent re-activation on the success page.
* Added automatic address clearing after a successful B2B purchase to ensure guest data is not persisted for subsequent sessions.

= 1.0.5 =
* Fixed an issue where B2B checkout redirected to an "empty cart" page instead of order confirmation.
* Prevented unnecessary Briqpay session initialization on the success page.

= 1.0.4 =
* We have added more robust handling of redirect url for b2b checkout
* added update support

= 1.0.3 =
* Added address synchronization for B2B checkout (zip/postcode update).
* Improved `briqpay_b2b_checkout` shortcode to automatically handle B2B session context without requiring external filters.

= 1.0.2 =
* Security hardening: Fixed nonce verification and input sanitization warnings.
* Fixed mobile styling for B2B checkout.
* Corrected internationalization text domains.

= 1.0.1 =
* Performance optimizations.
* Improved checkout script loading.

= 1.0.0 =
* Initial release.
* Standardized logging with WC_Logger.
* Full support for WooCommerce Checkout Blocks.
