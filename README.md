# Briqpay for WooCommerce

![Briqpay Logo](https://cdn.briqpay.com/static/images/briqpayLogo.svg) 

A professional, high-performance payment gateway for Briqpay V3 in WooCommerce.

## Features

- **Briqpay V3 Integration:** Full support for the latest Briqpay API.
- **Classic & Blocks Support:** Works seamlessly with both classic WooCommerce shortcodes and the newer Checkout Block.
- **Embedded iFrame:** Smooth checkout experience within your existing shop.
- **Order Management:** Automated captures and refunds directly from the WooCommerce Admin.
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

## Development

### Running Tests

This project uses PHPUnit for unit testing. To run tests locally:

1. Install dependencies:
   ```bash
   composer install
   ```
2. Execute tests:
   ```bash
   ./vendor/bin/phpunit
   ```

## Support

For technical support or inquiries, please visit [briqpay.com](https://briqpay.com).

## License

This project is licensed under the GPLv2 License - see the [LICENSE](LICENSE) file for details.
