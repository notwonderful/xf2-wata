# Wata Payment Profile for XenForo 2+

This is a payment provider integration for XenForo 2+, allowing users to process payments via [Wata.pro](https://wata.pro).

## Features
- Secure payment processing via Wata.pro API
- Supports multiple currencies: USD, EUR, RUB
- Webhook validation with IP whitelisting and signature verification

## Requirements
Before installing, ensure your XenForo setup meets the following requirements:

- **XenForo:** 2.2.0+
- **PHP:** 8.0+
- **Required PHP extensions:**
  - JSON (`php-ext/json`)
  - OpenSSL (`php-ext/openssl`)
  - cURL (`php-ext/curl`)

## Installation

1. Download the latest release from the [Releases](https://github.com/notwonderful/xf2-wata/releases) page.
2. In the XenForo admin panel, navigate to **Add-ons** → **Install/upgrade from archive**.
3. Upload the downloaded archive and select it for installation.
4. Configure the payment provider under **Admin Panel** → **Payment Profiles**.

## Configuration

1. Go to **Admin Panel** → **Payment Profiles**.
2. Click **Add Payment Profile** and select **Wata.pro**.
3. Enter your API token.
4. Save changes.

## Webhook Setup

To receive payment notifications, configure the webhook in your Wata.pro account:

- **Webhook URL:** `https://domain.com/payment_callback.php?_xfProvider=Wata`
- **Allowed IPs:**  
  - `62.84.126.140`  
  - `51.250.106.150`  

Make sure your server allows incoming connections from these IPs to avoid webhook failures.

## Additional Resources
- [Wata.pro API Documentation](https://wata.pro/api)

