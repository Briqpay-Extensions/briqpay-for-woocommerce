=== Briqpay for WooCommerce ===
Contributors: briqpay
Donate link: https://briqpay.com
Tags: payments, gateway, briqpay, ecommerce, checkout
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A professional, high-performance payment gateway for Briqpay V3 in WooCommerce.

== Description ==

Briqpay for WooCommerce allows you to integrate the Briqpay V3 payment platform seamlessly into your shop.

**Important Note:** This plugin connects to an external service (Briqpay API) to process payments. 
*   **Service:** Briqpay (https://briqpay.com)

== External services ==

This plugin connects to Briqpay to process payments. Briqpay is a payment service provider that streamlines multiple payment methods into a single integration. 

The plugin communicates with the following external endpoints to initialize and verify payment sessions:
* https://api.briqpay.com (Production API)
* https://playground-api.briqpay.com (Test/Staging API)

When you use this plugin, order and customer data is sent to Briqpay. This includes:
* **Order Details:** Product names, SKU, quantities, prices, and taxes.
* **Customer Information:** Name, billing/shipping address, email, and phone number.
* **Transaction Data:** Currency, order ID, and total amount.

This data is sent when a customer accesses the checkout page, updates their checkout information (e.g., shipping methods), or when a merchant processes captures/refunds via the WooCommerce admin.

The use of this service is governed by Briqpay's legal documentation:
* **Privacy Policy:** https://briqpay.com/privacy-policy
* **Data Processing Agreement (DPA):https://briqpay.com/dpa

= Features =
*   **Briqpay V3 Integration:** Full support for the latest Briqpay API.
*   **Classic & Blocks Support:** Works with both classic WooCommerce shortcodes and the newer Checkout Block.
*   **Embedded iFrame:** Smooth checkout experience within your shop.
*   **Order Management:** Automated captures and refunds directly from the WooCommerce Admin.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your Briqpay settings in WooCommerce > Settings > Payments > Briqpay.

== Changelog ==

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
