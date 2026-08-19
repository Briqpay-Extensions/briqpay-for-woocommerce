=== Briqpay for WooCommerce ===
Contributors: briqpay
Donate link: https://briqpay.com
Tags: payments, gateway, briqpay, ecommerce, checkout
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.6
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
* **Built for international commerce:** Supports all countries, payment methods, B2C and B2B. Currencies with two decimal places (SEK, EUR, USD, GBP, DKK, NOK and most others) are supported; zero-decimal currencies such as JPY and ISK and three-decimal ones such as KWD are not yet, and the gateway hides itself rather than sending incorrect amounts.
* **Easy to add or switch payment providers:** Add new payment methods or change payment provider without rebuilding your checkout.
* **Full control over costs and routing:** Control which provider to use per market, currency, or order value.
* **All payment methods work together:** Cards, BNPL, wallets and local payment methods work in the same checkout without conflicts.
* **Always up to date:** Payment methods are updated in one place, so you don’t need to maintain multiple plugins.
* **Built-in analytics and insights:** Analyze conversion, payment method performance, and customer payment behavior across markets in one interface.
* **Consistent payment flow:** Capture, refund, and order handling works the same for every payment method.
* **Blocks Support:** Full support for the newer WooCommerce Checkout Block and classic shortcodes.
* **Hosted Payment Pages:** Create a Briqpay-hosted payment link straight from a WooCommerce order in the admin — ideal for phone, email and quote orders.
* **Migration-friendly B2B order data:** Stores migrating from the previous Briqpay for WooCommerce plugin can keep using its B2B order meta keys (organisation number, shipping email, and related fields), so existing ERP exports and integrations keep working unchanged.

== Migrating from the previous Briqpay plugin ==

If you're moving from the previous "Briqpay for WooCommerce" plugin (the one hosted at
github.com/krokedil/briqpay-for-woocommerce), B2B orders can keep using that plugin's order meta
keys so existing ERP exports and integrations that read them keep working.

Enable **"Legacy B2B order meta mapping"** under WooCommerce > Settings > Payments > Briqpay >
Migration / legacy compatibility. With it enabled, B2B orders additionally store:

* `_billing_org_nr` — the company's organisation/CIN number.
* `_shipping_email` — the shipping contact email.
* `_briqpay_payment_method` — the resolved payment method name.
* `_briqpay_autocapture` — a truthy/empty flag mirroring autocapture status.
* `_briqpay_rules_result` — kept for structural parity; the underlying feature was deprecated in
  Briqpay's v3 API, so this is always an empty JSON array.

This is in addition to, not instead of, the meta keys this plugin already writes (for example
`_briqpay_company_cin` and `_briqpay_company_name`) — other features in this plugin depend on
those. The setting is off by default and only affects B2B orders; consumer (B2C) orders are never
touched by it.

The order edit screen's company CIN display, and hosted payment pages built from B2B orders, will
also read from the legacy `_billing_org_nr` key if the newer key is absent — so orders you've
already imported from the old plugin display correctly whether or not the setting is enabled.

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

= 1.1.6 =
* Added: Orders that Briqpay flags for manual review (the `manual_review` payment tag) are now placed on hold instead of being moved to processing. The order stays on hold until someone releases it manually, and no later Briqpay event will advance it.
* Added: An order you have put on hold yourself now stays on hold. Previously an approval or capture event from Briqpay could move it to processing, overriding your own logic - relevant for leasing, credit checks and other flows where an order must wait for a human. Applies to any on-hold order, whatever placed it there, including capture failures and amount mismatches. Use the `briqpay_respect_on_hold_status` filter if you want the previous behaviour.

= 1.1.5 =
* Added: "WooCommerce checkout actions" setting (WooCommerce > Settings > Payments > Briqpay). **Existing stores are unaffected until you turn this on.** When enabled, Briqpay orders fire WooCommerce's standard checkout actions - `woocommerce_checkout_create_order`, `woocommerce_checkout_update_order_meta`, `woocommerce_checkout_order_created`, `woocommerce_checkout_order_processed`, the Blocks Store API equivalents, and the `woocommerce_checkout_create_order_line_item_object` filter - so third-party plugins (custom checkout fields, ERP and invoicing connectors, delivery-date pickers, shipping brokers) receive them the same way they receive orders paid with other methods. The submitted checkout form is passed to those actions, captured during checkout and replayed, because the payment decision request carries only a session ID.
* Note: before enabling the setting above, review any custom code you added to compensate for these actions being missing (typically on `briqpay_after_create_order`). It will now run alongside the plugins it was standing in for, which can mean duplicate ERP exports, invoices or fees. New installs have the setting on by default because they have no such workarounds.
* Added: Filters `briqpay_fire_checkout_hook` (disable one specific checkout action), `briqpay_superimpose_post_data` and `briqpay_order_created_via`, plus a `filters/checkout_hook_control.txt` example.
* Added: When the setting above is on, storefront orders are recorded with `created_via` of `checkout` rather than `Briqpay`, so plugins that only act on native checkout orders recognise them. Stores with the setting off keep the previous value.
* Fix: Order line items now record the product's tax class, so recalculating an order in the admin no longer taxes reduced-rate products at the standard rate.
* Fix: Shipping line items now carry the shipping rate's own metadata and tax status. Table-rate, pickup-point and shipping-broker plugins store the customer's selected service there, and it was previously dropped.
* Fix: Coupon line items now store WooCommerce's `coupon_info` snapshot, so orders still display their discounts correctly after a coupon is edited or deleted.
* Fix: The order comments the customer types at checkout are now saved as the order's customer note. They were previously discarded on every Briqpay order. Works on both classic and Blocks checkout.
* Fix: Orders now record the cart hash, which WooCommerce and several plugins use to tell whether an order still matches the cart it came from.
* Fix: The `woocommerce_checkout_order_processed` handler that attaches the Briqpay session to orders created by WooCommerce's native checkout was never registered, so it never ran. It is now registered, and hardened so it can only promote a draft order and never move an order backwards.
* Fix: The payment gateway's `process_payment()` no longer always fails. If Briqpay confirms the session is paid - verified against the Briqpay API, never from local data - the order completes through WooCommerce's own pipeline. Anything unconfirmed still directs the customer back to the Briqpay checkout, so the native button cannot bypass payment.
* Added: Stock is now reserved for the order at the payment decision, and released again if the decision is rejected, so a purchase in progress cannot be oversold to the next customer.
* Added: Cost of Goods Sold totals are recalculated on WooCommerce 9.5 and later.
* Added: `woocommerce_checkout_order_exception` fires when order creation fails, and `woocommerce_checkout_create_order_tax_item` is offered for each tax line (both behind the "WooCommerce checkout actions" setting).
* Fix: Duplicate orders, captures, refunds and hosted payment pages could be created by concurrent requests. The locks that guarded these used a read-then-write sequence that two simultaneous requests could both pass. They now use an atomic claim, and captures, refunds and hosted page creation - which had no lock at all - are now serialised per order.
* Fix: A webhook that Action Scheduler failed to enqueue was silently dropped, and because it had already been marked as seen, Briqpay's retry was discarded as a duplicate. The enqueue result is now checked and the webhook processed immediately as a fallback.
* Fix: The janitor marked stagnant orders as processing whenever the Briqpay session was "completed". A completed session only means the customer finished the checkout - the transaction underneath can still be pending or rejected - so unpaid orders were reported as paid. It now requires an approved transaction and records payment through WooCommerce's own `payment_complete()`.
* Fix: Refunds entered as an amount rather than per item assumed 25% VAT and sent a fictional physical product named "refund". The tax rate is now derived from the order's actual tax, and the refund is sent as an adjustment line carrying the refund reason. Added the `briqpay_amount_only_refund_items` filter for stores that need to allocate across specific captured references.
* Fix: Automatic capture retries never ran. A failed capture scheduled its retry task with an empty order reference, so the retry aborted immediately and the order stayed on hold without further attempts.
* Fix: Amount-only refunds discarded the refund reason entered by the merchant and always used a generic label.
* Fix: An admin double-clicking "Execute Capture" could run two captures in parallel against the same capture history. Manual captures now take the same per-order lock as automatic ones.
* Fix: A failed session lookup at the payment decision left the decision lock held for its full duration, so an immediate retry after a transient API error was refused.
* Fix: A Briqpay session reported as "completed" is no longer treated as paid when its underlying transaction is pending or rejected. The webhook handler and the gateway's payment check now verify transaction approval, matching the janitor. A session that carries no transaction detail is still accepted, so no existing payment flow stops completing.
* Fix: The `briqpay_payment_complete` action never actually ran on a normal purchase. It was only fired on a code path that a storefront order never reaches, because orders already have the "pending" status by the time the customer returns. It now fires once per order whenever payment is verified at the return. **If you added custom code elsewhere to work around this, it will now run alongside your original `briqpay_payment_complete` listener** - remove one, or use the new `briqpay_fire_payment_complete` filter to suppress the action. Note this is a return-time signal; for "the payment is secured", use WooCommerce's own `woocommerce_payment_complete` or the order status transitions, which this plugin already triggers from the webhook and which fire even if the customer never returns.
* Fix: A checkout page loaded with an empty cart no longer sends a session request that Briqpay is guaranteed to reject, and no longer reports the cart as out of sync as a result.
* Fix: Corrected the currency support claim above. Money conversion assumes two decimal places throughout, so the gateway now hides itself on stores configured for a different precision instead of sending amounts that are wrong by a factor of ten. Override with the `briqpay_allow_unsupported_currency_precision` filter.

= 1.1.4 =
* Fix: Checkout could be blocked by a stale "We were unable to synchronize your cart with the payment provider" error even though the cart and the Briqpay session matched. When a session update failed, the plugin recorded the failure and then created a replacement session built from the current cart - but the recorded failure was never cleared, so the next payment attempt was refused. A successful session creation now clears it, and a failed one sets it, so the flag always reflects the last known state.
* Fix: The same stale flag could survive into a later checkout, because neither the post-purchase cleanup nor the login session reset cleared it. Both now do.

= 1.1.3 =
* Added: "Validate Terms & Conditions" setting (WooCommerce > Settings > Payments > Briqpay). When enabled, a purchase is rejected at the payment decision unless the customer ticked WooCommerce's native Terms & Conditions checkbox. Disable it if you collect consent elsewhere - for example with Briqpay's own terms module or a third-party consent plugin - so the customer is not asked to accept twice. Enabled by default, so existing installs keep the current behaviour.

= 1.1.2 =
* Added: "Legacy B2B order meta mapping" setting (WooCommerce > Settings > Payments > Briqpay > Migration / legacy compatibility) for stores migrating from the previous Briqpay for WooCommerce plugin. When enabled, B2B orders additionally store the organisation number in `_billing_org_nr`, the shipping email in `_shipping_email`, and mirror the payment method/autocapture meta the previous plugin used, alongside the meta this plugin already writes. Disabled by default; existing installs are unaffected.
* Added: The order edit screen shows the legacy "Billing Organization Number" field and shipping email when the setting above is enabled, matching the previous plugin's admin screen.
* Fix: The company CIN shown on the order edit screen and used for hosted payment pages now falls back to `_billing_org_nr` when the newer `_briqpay_company_cin` meta is absent, so orders imported from the previous plugin display correctly regardless of the setting.

= 1.1.1 =
* Added: Hosted Payment Pages. Build an order in the WooCommerce admin and create a Briqpay-hosted payment link for it directly from the order screen.
* Added: Hosted Payment Pages settings section (WooCommerce > Settings > Payments > Briqpay) to enable the feature and pre-select a default flow (Consumer, Business - Payment Methods Only, or Business - Full Checkout), plus hosted page title, logo URL and show-cart preferences. The section folds until enabled to keep the settings screen tidy.
* Added: Customer billing/shipping address and company details already on the order are prefilled into the hosted page session.
* Added: For the Business - Full Checkout flow, the company and addresses confirmed on the hosted page are written back onto the WooCommerce order once payment completes.
* Added: Regenerating a hosted payment page creates a new Briqpay session and invalidates the previous link; blocked once an order is already paid (the dead link is no longer shown for paid/refunded/cancelled orders).
* Added: The Hosted Payment Page box only appears for orders created manually in the WooCommerce admin, not for regular customer/checkout orders.
* Fix: The "Briqpay Payment Details" meta box's PSP Name now populates from the webhook, not only from the storefront return redirect.
* Fix: Internal Briqpay item/fee reference metadata no longer shows up in the order line items table.
* Added: When the payment method used auto-captures on Briqpay's side, the order's "Manual Capture" button is replaced with an "Auto capture in progress" notice.
* Added: All new Briqpay sessions now enable real-time processing (config.realTimeProcessing), so webhooks and status updates are delivered immediately instead of in batches.
* Fix: Orders placed through Briqpay now populate WooCommerce's native Order Attribution data (the "Origin" column in WooCommerce > Orders), instead of always showing "Unknown". Covers both classic (shortcode) and Blocks checkout.

= 1.1.0 =
* Fix: Scoped payment gateway hiding rules strictly to `body.briqpay-selected` to prevent hiding other payment options.
* Fix: Always verify payment status and execute cart & session cleanup prior to return redirect.
* Fix: Prevented registered user address erasure on the thank-you page.
* Fix: Added robust order validation (stock, coupons, terms, per-package shipping) to prevent invalid checkouts.
* Fix: Ensured draft orders are correctly reconciled and rebuilt when items differ.
* Fix: Added session-to-order mapping and lock to prevent concurrent duplicate orders.
* Fix: Enforced address updates before tax calculations.
* Fix: Set sync-failed session flag on updates to reject out-of-sync checkouts.
* Fix: Enabled currency multiplier for zero/three-decimal currencies.
* Fix: Disambiguated references for identical items at different prices.
* Fix: Saved and preserved fee references in order-item metadata.
* Fix: Calculated refunds correctly using the entered value.
* Fix: Restructured multi-capture refunds to enforce single-capture refunds atomically.
* Fix: Persisted item metadata to prevent checkout errors on deleted products.
* Fix: Added capture failure on-hold statuses and automatic retry task scheduling.
* Fix: Hardened webhook processing with retry exception handling, monotonic status flow transitions, and unique key deduplication.
* Fix: Prevented Janitor from cancelling orders on temporary API errors.
* Fix: Hardened Blocks active checks and declared full cart_checkout_blocks compatibility.

= 1.0.15 =
* Security: Added IDOR protection check to ajax_make_decision to verify the requested session ID matches the user's active session.
* Security: Added amount and currency matching verification to the webhook capture status handler.
* Security: Added amount verification guard to the manual backend order capture execution.
* Optimization: Removed redundant GET request by initiating PATCH request directly in get_or_create_session.
* Privacy: Suppressed full customer/order payload logs during validation checks when verbose logging is disabled.

= 1.0.14 =
* Security: Fixed guest draft order reuse vulnerability (IDOR) by securing fallback lookups, validating customer ownership, and automatically rebuilding cart items for reused untrusted drafts.
* Security: Hardened webhook processing by performing Briqpay API verification before capture and refund routing, verifying capture/refund IDs, and retrieving authoritative values directly from the API.
* Fix: Corrected order status flow so pending Briqpay orders transition correctly through pending → processing → completed via webhooks, instead of entering an incorrect "paused" state.
* Fix: Hidden the "Pay" button on the My Account orders page and order confirmation page for Briqpay orders awaiting webhook confirmation, preventing customers from re-initiating payment after completing checkout via the Briqpay iframe.
* Fix: Blocked direct access to the order-pay endpoint for Briqpay orders that have already been paid, redirecting customers to My Account with a notice.
* Improvement: Added verbose logging toggle in settings — when disabled, high-frequency trace logs (availability checks, script loading, cart processing) are suppressed while keeping critical diagnostics (totals, B2B flow, webhooks) in the default log level.
* Performance: Centralized plugin logging, gating debug messages behind WooCommerce gateway settings, and disabling heavy payload logs.
* Assets Optimization: Restructured admin scripts/styles to only load on Briqpay order edit views.
* Reduced Recalculations: Deduplicated Blocks checkout customer saves and introduced cart/address hashing to prevent redundant recalculations.
* B2B & Webhooks: Optimized B2B fragments and introduced a transient-based 5-minute guard to deduplicate webhook handling.
* Cron Improvements: Relocated background job schedules to plugin activation/deactivation hooks and limited cleanup actions to batches of 50.
* API Request Reductions: Skipped redundant PATCH requests for unchanged data and second GET calls when HTML snippets are available.
* Caching & Lookups: Added request-level caching for product image URLs and tax rates, and optimized order lookups via IDs-first queries.
* HPOS Compatibility: Enabled HPOS-aware order list table filtering to hide temporary orders.

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
