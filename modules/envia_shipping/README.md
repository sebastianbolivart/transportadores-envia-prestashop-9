# Envia Shipping – PrestaShop 9 Module

A production-ready shipping module for **PrestaShop 9** that integrates the [Envia.com](https://envia.com) API to deliver real-time shipping quotes during checkout.

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Architecture Overview](#architecture-overview)
6. [API Usage](#api-usage)
7. [Multistore Support](#multistore-support)
8. [Debugging](#debugging)
9. [Troubleshooting](#troubleshooting)
10. [Changelog](#changelog)

---

## Features

- **Real-time quotes** – Fetches live shipping rates from the Envia API on every cart update
- **Multi-carrier support** – Displays all carriers returned by Envia (sorted by price)
- **Caching** – File-based quote cache reduces API calls and checkout latency
- **Fallback rate** – Configurable flat-rate price if the API is unavailable
- **Price margin** – Apply a percentage markup on top of the API price
- **Multistore** – Per-shop API key and origin address configuration
- **Debug logging** – Detailed request/response logs via PrestaShopLogger
- **Secure** – API key stored encrypted; CSRF protection on all admin forms
- **PSR-12 / PHP 8.1+** – Clean, modern codebase

---

## Requirements

| Component | Version |
|-----------|---------|
| PrestaShop | 9.0+ |
| PHP | 8.1+ |
| Composer | 2.x |

---

## Installation

### Via PrestaShop Back-Office

1. Download or clone this repository.
2. Zip the `envia_shipping` folder.
3. Go to **Modules → Module Manager → Upload a module**.
4. Upload the ZIP file.
5. Click **Install**.

### Via CLI

```bash
# From PrestaShop root directory
cd modules/
git clone https://github.com/sebastianbolivart/transportadores-envia-prestashop-9 envia_shipping
cd envia_shipping
composer install --no-dev --optimize-autoloader
```

Then install via back-office or run:

```bash
php bin/console prestashop:module install envia_shipping
```

---

## Configuration

Navigate to **Admin → Shipping → Envia Shipping → Configuration**.

### API Settings

| Field | Description |
|-------|-------------|
| **Envia API Key** | Bearer token from your Envia.com account panel |
| **Environment** | `Sandbox` for testing, `Production` for live orders |

### Origin Address

The warehouse or fulfilment centre from which packages are shipped.

| Field | Description |
|-------|-------------|
| **Origin Postal Code** | e.g. `050021` |
| **Origin Country** | ISO 2-letter code, e.g. `MX`, `CO` |
| **Origin City** | City name (optional, improves quote accuracy) |
| **Origin State** | State/province (optional) |

### Default Package Dimensions

Used when individual product dimensions are not configured.

| Field | Unit | Default |
|-------|------|---------|
| Length | cm | 20 |
| Width | cm | 15 |
| Height | cm | 10 |

### Pricing

| Field | Description |
|-------|-------------|
| **Declared Value Multiplier** | Multiply cart total by this factor for declared value (default `1.0`) |
| **Price Margin (%)** | Add a percentage markup to all API-returned prices |
| **Fallback Flat Rate** | Price charged if the API is unavailable (set `0` to hide the carrier) |

### Technical Settings

| Field | Default | Notes |
|-------|---------|-------|
| API Timeout (s) | 10 | Max time to wait for API response |
| Cache TTL (min) | 10 | How long quotes are cached |
| Debug Logging | Off | Enable for verbose API logs |

---

## Architecture Overview

```
envia_shipping/
├── envia_shipping.php          Main module class (CarrierModule)
├── composer.json               PSR-4 autoload config
├── config/
│   ├── services.yml            Service container definitions
│   └── routes.yml              Admin route definitions
└── src/
    ├── Domain/
    │   └── Model/
    │       ├── Address.php     Value object for addresses
    │       └── Package.php     Value object for package dimensions
    ├── Application/
    │   └── Service/
    │       ├── ShippingQuoteService.php  Orchestrates quote retrieval
    │       └── CarrierMapper.php        Maps API response → PS carrier format
    └── Infrastructure/
        ├── Api/
        │   └── EnviaApiClient.php       HTTP client with auth & retry
        ├── Cache/
        │   └── QuoteCache.php           File-based quote cache
        ├── Logger/
        │   └── EnviaLogger.php          Wraps PrestaShopLogger
        └── Exception/
            ├── EnviaException.php       Base exception
            ├── EnviaApiException.php    API-specific errors
            └── EnviaConfigException.php Configuration errors
```

### Request Flow

```
Customer selects carrier step
  └─► PrestaShop calls getOrderShippingCost()
        └─► ShippingQuoteService::getQuotes()
              ├─► QuoteCache::get() — cache hit? Return cached price
              └─► EnviaApiClient::getShippingRates()
                    └─► POST https://api.envia.com/ship/rate/
                          └─► CarrierMapper::mapRatesToCarriers()
                                └─► Return cheapest price to PrestaShop
```

### Failover Chain

1. Fresh cache hit → return cached price
2. API success → cache & return price
3. API failure → try stale cache (expired)
4. Stale cache miss → return configured fallback flat rate
5. No fallback → hide carrier from checkout

---

## API Usage

The module sends the following payload to `POST /ship/rate/`:

```json
{
  "origin": {
    "postalCode": "050021",
    "country": "CO"
  },
  "destination": {
    "postalCode": "110111",
    "country": "CO"
  },
  "packages": [
    {
      "weight": 1.5,
      "length": 20,
      "width": 15,
      "height": 10,
      "declaredValue": 200000
    }
  ]
}
```

Expected API response structure:

```json
{
  "data": [
    {
      "carrier": "fedex",
      "service": "FedEx Standard",
      "totalPrice": 45000,
      "currency": "COP",
      "deliveryTime": "3"
    }
  ]
}
```

Get your API key at [https://envia.com](https://envia.com).

---

## Multistore Support

Each shop can have its own:
- API key
- Environment (sandbox/production)
- Origin address
- Package defaults

Ensure you set the correct **shop context** before saving configuration in a multistore setup. The module respects `Configuration::updateValue()` with the active shop context.

---

## Debugging

### Enable Debug Mode

1. Go to **Admin → Shipping → Envia Shipping → Configuration**
2. Toggle **Enable Debug Logging** to **Yes**
3. Save

### View Logs

1. Go to **Admin → Advanced Parameters → Logs**
2. Filter by **Object**: `EnviaShipping`

Debug logs include:
- Full API request payload (API key is never logged)
- Full API response
- Cache hits/misses
- Fallback triggers
- Exception stack traces

> ⚠️ **Disable debug mode in production** to avoid performance overhead and potential data exposure.

---

## Troubleshooting

### "No shipping options available" during checkout

1. Check the API key is configured and valid
2. Verify the origin postal code and country code are correct
3. Check the destination address has a valid postal code
4. Enable debug logging and review the logs
5. Confirm the Envia Shipping carrier is active in **Shipping → Carriers**

### API returns empty rates

- Ensure the origin and destination postal codes are valid for the selected environment
- Switch to Sandbox and test with known-good test postal codes from Envia docs
- Check API rate limits on your Envia account

### Carrier not showing in checkout

- Go to **Shipping → Carriers** and confirm "Envia Shipping" is active
- Ensure the carrier is assigned to the correct zones
- Check the minimum/maximum weight ranges include the cart weight

### Cache not being cleared

- Go to **Admin → Envia Shipping → Dashboard** and click **Flush Quote Cache**
- Verify the cache directory (`var/cache/envia_shipping/`) is writable by the web server

---

## Changelog

### v1.0.0 (2026-03-16)

- Initial release
- Envia.com API integration
- Dynamic carrier with real-time quotes
- File-based quote caching
- Admin dashboard and configuration panel
- Fallback flat rate
- Debug logging
- Multistore support
- PSR-12 / PHP 8.1+ / PrestaShop 9 compatibility
