# Current State — opensalestax-magento

**Last updated:** 2026-05-13 (project scaffolded)
**Status:** **Pre-alpha — specs scaffolded; no code yet.** Eric
confirmed architecture (Magento 2 module, PHP, composer-installable,
target `^2.4.6`). Next step: scaffold top-level `composer.json` +
module skeleton at `EJOsterberg/OpenSalesTax/`, then implement
v0.1.0 alpha (registration + HTTP client + tax extension + admin
config + tests).

## Where the upstream engine is

OpenSalesTax engine — same instance the other connectors point
at. Pin in production: **v0.22+** (pre-v0.22 had the SD-state-bleed
bug, closed in v0.22.0). Tested-against version pinned per release.
v1 HTTP API: `POST /v1/calculate`, `GET /v1/health`,
`GET /v1/states`, `GET /v1/rates`.

Shared dev instance: `http://10.32.161.126:8080`. v0.54.1+
confirmed at the orchestrator hub.

## Where the platform is

Magento 2 — **`^2.4.6`** is the supported floor. Adobe Commerce
Open Source baseline; 2.4.6 + 2.4.7 are under active support
through 2027 (re-verify at stage 00 against Adobe's lifecycle
matrix at <https://experienceleague.adobe.com/docs/commerce-operations/release/planning/lifecycle-policy.html>).

Tax extension seams the module hooks:

- **`Magento\Tax\Api\TaxCalculationInterface`** — public API for
  tax calculation. Candidate target for a `<preference>` swap
  (replaces Magento's default `Magento\Tax\Model\TaxCalculation`).
- **`Magento\Tax\Model\Calculation::getRate`** — lower-level rate
  lookup. Candidate target for a `<plugin>` (narrower surface,
  less risk of clashing with other modules that also extend the
  public API).
- **`Magento\Quote\Model\Quote\Address\Total\Tax::collect`** —
  totals collector that aggregates per-line tax for the cart /
  order summary. Plugin here is required to surface per-line
  breakdown in the customer's checkout summary + order detail
  screens.

The ADR `specs/decisions/001-tax-extension-point.md` (written in
stage 02 task 4) locks the preference-vs-plugin choice.

## What's shipped

(Nothing yet — this is the project's first session.)

## What's planned (in order)

### v0.1.0 alpha (this session or next)

- Top-level `composer.json` (dev deps:
  `magento/framework`, `phpunit/phpunit`, `phpstan/phpstan`,
  `bitExpert/phpstan-magento`, `magento/magento-coding-standard`)
- `phpunit.xml.dist`, `phpstan.neon`, `phpcs.xml` (references
  `Magento2` ruleset)
- `Model/OstaxClient.php` — HTTP client, port of the Medusa
  connector's TS client to PHP using
  `\Magento\Framework\HTTP\Client\Curl`. Methods: `calculate()`,
  `healthCheck()`. SPDX header on every file.
- `registration.php`, `etc/module.xml` — module registration
- ADR `specs/decisions/001-tax-extension-point.md` — pick
  preference-on-`TaxCalculationInterface` or
  plugin-on-`Calculation::getRate`. Document trade-offs.
- `Model/TaxCalculation.php` (or `Plugin/CalculationPlugin.php`
  per the ADR) — the actual tax calc swap
- `Plugin/QuoteTotalsTaxPlugin.php` — per-line breakdown for
  cart / order summary
- `etc/acl.xml`, `etc/adminhtml/system.xml`, `etc/config.xml` —
  admin settings (Sales → Tax → OpenSalesTax)
- `Test/Unit/Model/OstaxClientTest.php`, plugin tests
- `CHANGELOG.md` v0.1.0 entry, tag `v0.1.0`, GitHub release.
  Packagist auto-publishes on the next webhook fire if the
  repo is registered there.

### v0.2 polish queue (after v0.1 alpha ships)

- Magento Marketplace submission (multi-week review process at
  <https://commercemarketplace.adobe.com/>)
- Magento tax classes → OST six-category mapping (same shape as
  the WooCom v0.3.3 / Odoo v0.1.13 pattern; admin UI maps tax
  classes to OST categories)
- Per-state nexus filter (matches Odoo v0.3.0)
- Operator telemetry — last successful calc, failure streak,
  threshold-crossing alert via Magento's system message
  framework
- Exemption-certificate handling (Magento has a built-in
  customer-group → tax-class mapping; expose an opt-out flag
  per group)
- MFTF end-to-end test suite (Magento Functional Testing
  Framework)

## Spec-folder map

| File | Purpose |
|---|---|
| `specs/constitution.md` | Non-negotiable principles (license, architecture, USD-only) |
| `specs/current-state.md` | This file — snapshot for fresh sessions |
| `specs/handoff.md` | What the next session should pick up |
| `specs/research/magento-tax-module.md` | Magento Tax extension points — preference vs plugin, totals collector, admin config patterns |
| `specs/decisions/NNN-<slug>.md` | ADRs as they accrue (first one: tax extension point) |
| `specs/security/audit-YYYY-MM-DD.md` | Per-audit security snapshots (created in stage 04) |

## Sibling-project map

| Path | Stack | State |
|---|---|---|
| `opensalestax-Odoo/` | Planning hub | active (drives all connector projects) |
| `opensalestax-python/` | Python SDK | shipped to PyPI |
| `opensalestax-odoo-src/` | Odoo connector | v0.4.1 shipped on PyPI; OCA PR queued |
| `opensalestax-medusa/` | Medusa v2 plugin | shipped; NPM `@ejosterberg/medusa-plugin-opensalestax` |
| `opensalestax-woocommerce/` | WordPress plugin | shipped |
| `opensalestax-stripe-php/` | Stripe-PHP connector | shipped, private repo pending Packagist flip |
| `opensalestax-php/` | PHP SDK | shipped, private repo pending Packagist flip |
| `opensalestax-saleor/` | Saleor Tax App | pre-alpha, specs only (scaffolded 2026-05-10) |
| `opensalestax-vendure/` | Vendure plugin | pre-alpha, specs only (scaffolded 2026-05-13) |
| `opensalestax-magento/` | **THIS** — Magento 2 module | pre-alpha, specs only |
