# OpenSalesTax for Magento 2

> **v0.1.0 alpha.** Installable; passes its unit tests; not yet validated against a real Magento storefront. See `kickoff/` and `specs/` for the build plan.

A free, self-hostable Magento 2 module that swaps Magento's built-in tax calculator for the [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax) on US-destination, USD checkouts. No per-transaction fees, no SaaS lock-in — merchants run both Magento and OpenSalesTax on their own infrastructure.

## What this module does

- Hooks `Magento\Tax\Model\Calculation::getRate` via a plugin (see [`specs/decisions/001-tax-extension-point.md`](specs/decisions/001-tax-extension-point.md)) to substitute Magento's tax-table rate with the rate computed by your OpenSalesTax engine for the customer's destination.
- Hooks `Magento\Quote\Model\Quote\Address\Total\Tax::collect` to surface per-jurisdiction tax breakdown ("Minnesota State Sales Tax", "Hennepin County Tax", ...) in the cart and order summary screens.
- Falls back to Magento's built-in tax tables on non-US destinations, non-USD currencies, or any engine error (default fail-soft behavior).

## What this module does NOT do

- File or remit tax (calculation only — the merchant remits)
- Validate addresses
- Handle non-USD currencies or non-US destinations (passes those through to Magento)
- Validate tax-exempt customer certificates against state DORs
- Ship with the engine bundled — point it at your own [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)

## Requirements

- Magento 2 `^2.4.6` (PHP 8.1+)
- A reachable [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax) instance (v0.22.0 or later)

## Install

```bash
composer require ejosterberg/module-opensalestax
bin/magento module:enable EJOsterberg_OpenSalesTax
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

## Configure

Stores → Configuration → Sales → Tax → **OpenSalesTax**

| Field | Path | Default | Purpose |
|---|---|---|---|
| Engine API URL | `osstax/general/api_url` | (empty) | Base URL of your OST engine, e.g. `https://ost.example.com` |
| API Token (optional) | `osstax/general/api_token` | (empty) | Bearer token if your engine requires authentication. Stored encrypted in `core_config_data`. |
| Fail Hard on Engine Error | `osstax/general/fail_hard` | No | If **Yes**, an unreachable engine blocks checkout. If **No**, the module falls back to Magento's tax tables and logs a warning. |

While `api_url` is empty the module is inert — Magento's built-in tax calc handles everything.

## How it works

1. At checkout, Magento builds the cart totals. The totals pipeline reaches `Magento\Quote\Model\Quote\Address\Total\Tax::collect`.
2. Our plugin's `beforeCollect` checks the gate (configured? USD? shipping to US?). If all three are yes, it builds an OST engine payload from the quote and calls `POST /v1/calculate`.
3. The engine returns per-line tax + per-jurisdiction breakdown. We cache this in a request-scoped registry keyed by quote id.
4. Magento then asks `Calculation::getRate` for the rate to apply per line. Our plugin reads from the registry and returns the OST-derived effective rate, which Magento applies in the usual way.
5. Our `afterCollect` writes the per-jurisdiction breakdown onto the totals object so cart and order screens display individual jurisdictions instead of a single opaque tax line.

If any check fails (non-US, non-USD, engine down without fail-hard), control returns silently to Magento's built-in tax calc.

## Logging

All engine interactions log structured metadata (quote id, line count, HTTP status, RTT in milliseconds) via Magento's `Psr\Log\LoggerInterface`. **Customer addresses and full payloads are never logged.** The API token is decrypted in memory only at request time and never written to logs.

## Development

```bash
composer install
composer check       # runs phpunit + phpstan + phpcs + composer audit
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for branch model, DCO sign-off, and the quality gate.

## License

Dual-licensed under your choice of [Apache-2.0](LICENSE-APACHE.txt) OR [GPL-2.0-or-later](LICENSE-GPL.txt). See [`LICENSE`](LICENSE).
