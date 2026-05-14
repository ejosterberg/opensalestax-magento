# CLAUDE.md — opensalestax-magento

> Project memory for Claude sessions on the Magento 2 module
> connector. Read this AND `specs/constitution.md` +
> `specs/handoff.md` before writing code.

## Mission

Ship a free, self-hostable **Magento 2 module** that swaps
Magento's built-in tax calculator for the OpenSalesTax engine on
US-destination, USD checkouts. Same value prop as the other OST
connectors: no per-transaction fees, no SaaS lock-in, merchant
runs both Magento and OST on their own infrastructure.

## Stack

- **Language:** PHP 8.1+
- **Platform:** Magento 2 (Adobe Commerce Open Source), target
  `^2.4.6` (Adobe support baseline through 2027 — re-verify at
  stage 00)
- **Distribution:** Composer-installable module via Packagist
  (`ejosterberg/module-opensalestax`). Magento Marketplace
  submission is a v1.1 follow-up.
- **License:** Apache-2.0
- **Tests:** PHPUnit (Magento's vendor harness) + PHPStan
  (level 8, `bitExpert/phpstan-magento`) + `phpcs` with the
  `Magento2` ruleset

## Architectural anchors

- **In-process module, no standalone server.** Installed into the
  merchant's Magento via `composer require` + `bin/magento
  module:enable` + `bin/magento setup:upgrade`. No webhook
  subscriptions; no separate process.
- **Tax extension point** (constitution §2). Two candidate
  patterns:
  - `<preference>` in `etc/di.xml` swapping
    `Magento\Tax\Api\TaxCalculationInterface`
  - `<plugin>` on `Magento\Tax\Model\Calculation::getRate`
    (likely cleaner — narrower surface, less risk of conflict
    with other tax-adjacent modules)
  - Plus a `<plugin>` on
    `Magento\Quote\Model\Quote\Address\Total\Tax::collect` so
    per-line tax breakdown surfaces in the customer's checkout
    summary + order detail screens.
  - The concrete choice gets locked in
    `specs/decisions/001-tax-extension-point.md` during
    stage 02 task 4.
- **Trust boundary**: admin-installable, runs inside Magento's
  PHP request lifecycle. Trust the admin user; protect admin
  config endpoints with ACL + form_key.
- **USD-only / US-only**: if the quote's shipping or billing
  country isn't US (or the quote currency isn't USD), return
  control to Magento's built-in `tax_rate` calculation. No OST
  call. (constitution §5)
- **Fail-soft default**: engine errors fall back to Magento's
  built-in tax rate + log a warning. Admin toggle
  `osstax/general/fail_hard` opts into fail-hard (raise + block
  checkout). (constitution §8)
- **Calculation only**: no filing, no remittance, no address
  validation. (constitution §6, §10)

## File layout (planned)

```
opensalestax-magento/
├── CLAUDE.md             # this file
├── README.md             # added in stage 02
├── LICENSE               # Apache-2.0; added in stage 01/02
├── CONTRIBUTING.md       # DCO sign-off mandatory
├── SECURITY.md
├── CHANGELOG.md
├── composer.json         # top-level dev tooling
├── phpunit.xml.dist
├── phpstan.neon
├── phpcs.xml             # references Magento2 ruleset
├── specs/
│   ├── constitution.md
│   ├── current-state.md
│   ├── handoff.md
│   ├── decisions/        # created as ADRs accrue
│   └── research/magento-tax-module.md
├── EJOsterberg/OpenSalesTax/     # the Magento module itself
│   ├── registration.php
│   ├── composer.json              # module-level composer.json for Marketplace
│   ├── etc/
│   │   ├── module.xml
│   │   ├── di.xml                 # tax preference / plugin wiring
│   │   ├── acl.xml                # admin ACL for the config section
│   │   ├── config.xml             # defaults
│   │   └── adminhtml/
│   │       └── system.xml         # admin settings form
│   ├── Model/
│   │   ├── OstaxClient.php        # HTTP client (PHP port of Medusa client)
│   │   └── TaxCalculation.php
│   ├── Plugin/
│   │   ├── CalculationPlugin.php
│   │   └── QuoteTotalsTaxPlugin.php
│   └── Setup/                     # only if schema needed; v0.1 probably no schema
└── Test/
    └── Unit/
        ├── Model/
        └── Plugin/
```

## What NOT to do

- Don't reach for a custom database table in v0.1. The module's
  settings live in `core_config_data` via Magento Config — that's
  the platform-native pattern and it ships free encryption for
  the API token.
- Don't intercept tax calculation at the controller layer. The
  right seams are `Magento\Tax\Api\TaxCalculationInterface` and
  the `Total\Tax::collect` totals collector. Anything higher up
  breaks other tax-adjacent modules.
- Don't ship a copy of the OST engine — point at the merchant's
  instance via the admin config URL.
- Don't add a Marketplace dependency in v0.1. Packagist
  distribution stands alone; Marketplace is v1.1.
- Don't accept commits without DCO sign-off (`-s` flag).
- Don't add AI co-author trailers to commit messages.
- Don't write "WC" in human-readable prose — it's "WooCom" if
  WooCommerce ever needs to be referenced.

## Releasing

- Semver tags `vX.Y.Z` on the single `main` branch. Magento
  generally stays compatible across `2.4.x` minors, so we don't
  branch-per-major like Odoo.
- GitHub release on each tag.
- Publish to Packagist as `ejosterberg/module-opensalestax`. The
  Packagist GitHub webhook auto-publishes new tags **if** the
  repo is registered there (see stage 07).
- Magento Marketplace submission is v1.1; the marketplace
  process is multi-week and gated on review feedback —
  treat it as a follow-up not a blocker.

## Sibling-project map

See `specs/current-state.md` "Sibling-project map" section.
