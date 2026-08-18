# Briqpay for WooCommerce

![Briqpay Logo](https://cdn.briqpay.com/static/images/briqpayLogo.svg) 

A professional, high-performance payment gateway for Briqpay V3 in WooCommerce.

## Features

- **Briqpay V3 Integration:** Full support for the latest Briqpay API.
- **Classic & Blocks Support:** Works seamlessly with both classic WooCommerce shortcodes and the newer Checkout Block.
- **Embedded iFrame:** Smooth checkout experience within your existing shop.
- **Order Management:** Automated captures and refunds directly from the WooCommerce Admin, with auto-capturing payment methods detected and handled automatically.
- **Hosted Payment Pages:** Create a Briqpay-hosted payment link for an order you built in the WooCommerce admin — ideal for phone, email and quote orders.
- **Order Attribution:** Populates WooCommerce's native "Origin" column (Organic, Direct, Referral, UTM, etc.) for orders placed through Briqpay, for both classic and Blocks checkout.
- **WooCommerce checkout action parity:** Optionally fires WooCommerce's standard checkout actions (`woocommerce_checkout_create_order`, `woocommerce_checkout_update_order_meta`, `woocommerce_checkout_order_processed` and more) so third-party plugins — custom checkout fields, ERP and invoicing connectors, delivery-date pickers — receive Briqpay orders like any other. Off by default on existing stores; see the gateway settings.
- **Terms & Conditions validation:** Purchases are rejected unless the customer accepted WooCommerce's native Terms & Conditions checkbox. Can be switched off in the gateway settings when consent is collected elsewhere (Briqpay's terms module, a consent plugin).
- **Standardized Logging:** Uses standard WooCommerce logging for easy diagnostics.

## Prerequisites

- **WooCommerce:** 5.5 or higher.
- **PHP:** 7.4 or higher.
- **Briqpay Merchant Account:** You need a MID and Shared Secret from Briqpay.

## Installation

1. Download the plugin as a ZIP file.
2. Upload via **Plugins > Add New > Upload Plugin** in your WordPress Admin.
3. Activate the plugin.
4. Go to **WooCommerce > Settings > Payments > Briqpay** to configure your Merchant ID and Shared Secret.

## Hosted Payment Pages

Build an order for a customer in the WooCommerce admin (phone order, email order, a quote you're converting) and send them a Briqpay-hosted link to pay it — no need to route them through the storefront checkout.

### Enabling it

Go to **WooCommerce > Settings > Payments > Briqpay** and, under **Hosted Payment Pages**:

1. Tick **Enable Hosted Payment Pages**.
2. Choose a **Default flow** — this is only a pre-selection; you can always pick a different flow when you actually create a page. See the flow table below.
3. Optionally set a **Hosted page title** (3–256 characters), a **Hosted page logo URL** (an absolute `http(s)` URL to a `.png`, `.jpg`, `.jpeg` or `.svg` image, max 512 characters), and whether to **show the cart** on the page.

### Creating a link

1. Go to **WooCommerce > Orders > Add order**.
2. Set the customer, add at least one line item (and shipping/fees/coupons as needed), then **Save**.
3. In the **Briqpay Hosted Payment Page** box (order edit screen, side column), pick a flow and click **Create hosted payment page**.
4. Copy the link, or open it, and send it to your customer.

The order must be saved with at least one line item before the box will offer to create a page.

### The three flows

| Shown in the dropdown as | Internal flow | What it shows |
|---|---|---|
| **Consumer** | `b2c` | Payment only. |
| **Business - Payment Methods Only** | `b2b_payment_module` | Payment only, for a business customer. |
| **Business - Full Checkout** | `b2b_checkout` | Company lookup, billing, shipping and payment — Briqpay collects and verifies the company/addresses on the page itself. |

### Customer details

The order's billing address, shipping address and company (if any) are sent to Briqpay as a prefill. An incomplete address (missing street, city, postcode or country) is omitted entirely rather than sent half-filled.

For the **Business - Full Checkout** flow specifically, whatever the customer confirms on the hosted page (company, billing and shipping address) is written back onto the WooCommerce order once the payment webhook arrives.

### After the customer pays

The order moves through the same webhook-driven flow as a storefront Briqpay order: `pending` → `processing` once approved, then captures and refunds from the existing **Briqpay Payment Details** box. The WooCommerce "Pay" button stays hidden, since payment happens on the hosted page rather than on the order-pay endpoint.

If the payment method used auto-captures on Briqpay's side, the **Manual Capture** button is replaced with an **"Auto capture in progress"** notice — no separate action needed.

### Regenerating a link

Clicking **Regenerate** creates a brand new Briqpay session and hosted page, replacing the stored link — the previous link stops working. This is blocked once an order has already been paid, refunded or cancelled, and also refused if the existing session has already completed (to avoid orphaning a paid session).

### Filters and actions

- `briqpay_hpp_session_data` — filter the full `/v3/session` payload before it's sent.
- `briqpay_hpp_config` — filter the `/v3/hosted-page` config (`pageTitle`, `logoUrl`, `showCart`).
- `briqpay_hpp_billing_address` / `briqpay_hpp_shipping_address` — filter the address blocks derived from the order.
- `briqpay_hpp_locale` — filter the locale sent for the session.
- `briqpay_hpp_before_create` / `briqpay_hpp_created` — fire before/after a hosted page is created.
- `briqpay_webhook_session_verified` — fires for every verified Briqpay webhook (used internally to sync Business - Full Checkout data back to the order).

### Troubleshooting

If page creation fails with a message about the Briqpay total not matching the WooCommerce order total, recalculate the order (re-save it, or use **Recalculate** in the order totals box) and try again — this guard exists so a link is never created that would otherwise stall after payment. Enable **Logging** in the Briqpay gateway settings and check **WooCommerce > Status > Logs** for detailed diagnostics.

## Development

### Running Tests

This project uses PHPUnit for PHP unit tests and Jest for the frontend JavaScript. To run tests locally:

1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Run the PHP test suite:
   ```bash
   ./vendor/bin/phpunit
   ```
3. Install JS dependencies:
   ```bash
   npm install
   ```
4. Run the JavaScript test suite:
   ```bash
   npm test
   ```

## Support

For technical support or inquiries, please visit [briqpay.com](https://briqpay.com).

## License

This project is licensed under the GPLv2 License - see the [LICENSE](LICENSE) file for details.
