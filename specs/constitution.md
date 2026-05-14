# Constitution — opensalestax-magento

> Non-negotiable principles. Read before writing code; flag conflicts
> explicitly before deviating.

## §1. Mission

Ship a free, self-hostable **Magento 2 module** that swaps
Magento's built-in tax calculator for an OpenSalesTax engine
instance on US-destination, USD checkouts. Same merchant value
proposition as the WooCommerce / Medusa / Saleor / Odoo
connectors: no per-transaction fees, no SaaS lock-in, merchant
runs both Magento and OST on their own infrastructure.

## §2. Architecture (locked 2026-05-13)

**Magento 2 module, PHP, composer-installable.**

- Installed via `composer require ejosterberg/module-opensalestax`
  then `bin/magento module:enable EJOsterberg_OpenSalesTax` +
  `bin/magento setup:upgrade`. NO standalone server, NO webhook
  subscriptions — the module runs in-process inside Magento's
  PHP request lifecycle.
- **Tax extension points** (both required):
  - Primary: a `<preference>` in `etc/di.xml` swapping
    `Magento\Tax\Api\TaxCalculationInterface` for a custom
    implementation, OR (likely cleaner) a `<plugin>` on
    `Magento\Tax\Model\Calculation::getRate` to inject
    OST-derived rates per quote address.
  - Plus: a `<plugin>` on
    `Magento\Quote\Model\Quote\Address\Total\Tax::collect` to
    populate per-line tax breakdown that surfaces in the
    customer's checkout summary and order detail screens.
  - The concrete pattern (preference vs plugin on
    `getRate`) is open. The implementation session MUST
    document the choice in
    `specs/decisions/001-tax-extension-point.md` before
    landing the calculation code.
- **Trust boundary**: the module is admin-installable and runs
  inside Magento. Trust the admin user; protect admin config
  endpoints with ACL + form_key. Customer-facing requests
  inherit Magento's session / CSRF protections — we don't
  add new public endpoints.

## §3. License

Apache-2.0. Matches the engine, the Python SDK, the Medusa
connector, and the Saleor connector. (The Odoo connector is
LGPL/AGPL because OCA requires AGPL; Magento has no equivalent
constraint, so Apache-2.0 stays for maximum reuse.)

SPDX header on every PHP/XML file:
- PHP: `// SPDX-License-Identifier: Apache-2.0`
- XML: `<!-- SPDX-License-Identifier: Apache-2.0 -->`

DCO sign-off mandatory on every commit (`git commit -s`). No AI
co-author trailers.

## §4. Engine-call contract

The OST engine HTTP API (v1) is the source of truth. The Magento
module calls:

- `POST /v1/calculate` — per-line tax calculation, destination ZIP
- `GET /v1/health` — for Test Connection / startup probe

The module NEVER imports OST internals or relies on undocumented
engine behavior. The HTTP API is the contract; we pin the engine
`v1` API in our README's compatibility matrix.

The HTTP client lives at `Model/OstaxClient.php`. It wraps
`\Magento\Framework\HTTP\Client\Curl` and exposes `calculate()`
and `healthCheck()` methods. (Logic mirrors the Medusa
connector's `src/providers/opensalestax/client.ts` — port, don't
rewrite.)

## §5. USD-only / US-only

The OST engine is US-only and USD-only by design (engine
constitution §5). When the quote currency isn't USD, OR the
quote's shipping/billing country isn't US, the module returns
control to Magento's built-in `tax_rate` calculation. No OST
call, no warning log (this is normal, expected behavior). This
is the documented fallback path; merchants who want US-only
checkouts use Magento's standard country filter.

## §6. Calculation only

Never file returns, never remit collected tax, never validate
addresses. The module computes tax; the merchant remits.
Every README, admin settings page, and commit message disclaimer
carries this statement.

## §7. Admin-trust boundary

The module's admin config screen at **Stores → Configuration →
Sales → Tax → OpenSalesTax** is gated by Magento's ACL
(`Magento_Backend::admin`). The settings form uses Magento's
standard form_key CSRF protection. No new public-facing
endpoints are added; the module operates only inside Magento's
existing request lifecycle.

API tokens (if the merchant's OST instance requires one) are
stored encrypted via `Magento\Framework\Encryption\Encryptor`
in `core_config_data`. Never logged, never echoed to the admin
form when re-rendered.

## §8. Fail-soft policy

When the OST engine is unreachable or returns 5xx, the module
falls back to Magento's built-in `tax_rate` calculation and
logs a warning. Magento then computes tax from the merchant's
configured tax tables. Merchants can opt into **fail-hard**
behavior (raise + block checkout) via the admin config toggle
`osstax/general/fail_hard`. Default is fail-soft.

## §9. Test environment

Two layers:

- **Unit tests** (`Test/Unit/`): pure-PHP tests using
  `\PHPUnit\Framework\TestCase` for the OST client and
  `\Magento\Framework\TestFramework\Unit\Helper\ObjectManager`
  for the plugin classes. Mocks the HTTP client and any
  Magento collaborators. Fast — runs in <5s.
- **Demo deployment** (stage 05): a real Magento 2.4.6+ devbox
  via `markshust/docker-magento`, the module installed from a
  Composer path repository pointing at the local clone, and an
  OST engine container. `bin/magento setup:upgrade` followed by
  a synthetic $100 MN checkout exercises the full path.

We do not maintain a Magento Functional Testing Framework
(MFTF) suite in v0.1 — it's heavyweight and the demo
deployment covers the end-to-end happy path.

## §10. Out of scope

Per the engine + project constitutions:

- Tax filing / remittance
- Address validation / autocomplete
- Non-USD currency
- Non-US jurisdictions
- Tax-exempt customer certificate validation against state DOR
- Marketplace facilitator handling (NJ / CA seller-of-record
  edge cases)
- Modifying upstream Magento source (every interaction is via
  DI preference or plugin)
- Tax-class → OST-category mapping (every line uses category
  `general` in v0.1; mapping queued for v0.2)
- Magento Marketplace submission (v1.1)
