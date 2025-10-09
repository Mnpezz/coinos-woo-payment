# Coinos Lightning Payment Gateway for WooCommerce

A WordPress plugin that enables Bitcoin Lightning Network payments for WooCommerce stores using the Coinos API.

## Features

- Accept Bitcoin Lightning Network payments via Coinos API
- Real-time payment status checking
- QR code generation for easy mobile payments
- Webhook support for instant payment confirmation
- WooCommerce Blocks support
- Responsive design for mobile and desktop

## Installation

1. Upload the plugin files to `/wp-content/plugins/coinos-woo-payment/` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to WooCommerce > Settings > Payments to configure the gateway

## Configuration

1. Navigate to WooCommerce > Settings > Payments
2. Find "Coinos Lightning Payment" and click "Manage"
3. Enable the gateway
4. Enter your Coinos API key (JWT token)
5. Configure the payment type (Lightning Network)
6. Set the title and description for checkout

## Getting Your Coinos API Key

1. Visit [coinos.io](https://coinos.io)
2. Create an account or log in
3. Find your API Key at [coinos.io/docs](https://coinos.io/docs)
4. Copy the JWT token for use in the plugin settings

## API Key Format

The plugin expects a JWT token in the format:
```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6ImV4YW1wbGUtaWQiLCJpYXQiOjE2MzQ1NjEwMzB9.example-signature
```

## Usage

Once configured, customers will see the Coinos Lightning payment option at checkout. The payment process includes:

1. Customer selects Coinos Lightning payment
2. System generates a Lightning invoice
3. QR code and invoice text are displayed
4. Customer scans QR code or copies invoice to their Lightning wallet
5. Payment is automatically confirmed via webhook or polling

## Webhook Configuration

The plugin automatically sets up webhooks for payment confirmation. No additional configuration is required.


## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Support

For support and bug reports, please visit the plugin's GitHub repository.

## Related Plugins:

* [Lightning Rewards with Coinos](https://github.com/Mnpezz/coinos-wordpress-rewards)
* [Payment with Strike](https://github.com/Mnpezz/strike-woo-payment)

## License

GPL v2 or later
