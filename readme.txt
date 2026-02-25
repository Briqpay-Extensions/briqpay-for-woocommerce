=== Briqpay for WooCommerce ===
Contributors: briqpay
Donate link: https://briqpay.com
Tags: payments, gateway, briqpay, ecommerce, checkout
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A professional, high-performance payment gateway for Briqpay V3 in WooCommerce.

== Description ==

Briqpay for WooCommerce allows you to integrate the Briqpay V3 payment platform seamlessly into your shop.

**Important Note:** This plugin connects to an external service (Briqpay API) to process payments. 
*   **Service:** Briqpay (https://briqpay.com)

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
