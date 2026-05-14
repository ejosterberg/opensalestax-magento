# Handoff — opensalestax-magento

> **Read first if you're a fresh agent.** Constitution + current
> state + this file are the canonical bring-up sequence.

## You are here — 2026-05-13 (project scaffold)

The Magento 2 module is **pre-alpha** — specs are written; no
PHP yet. Architecture pre-locked on 2026-05-13:

- **Magento 2 module**, PHP 8.1+, composer-installable via
  `ejosterberg/module-opensalestax`. Target `^2.4.6`.
- **Tax extension** via Magento DI: either a `<preference>` on
  `Magento\Tax\Api\TaxCalculationInterface` OR a `<plugin>` on
  `Magento\Tax\Model\Calculation::getRate`. The concrete choice
  is task 4 below and gets documented in
  `specs/decisions/001-tax-extension-point.md`.
- **Plus** a `<plugin>` on
  `Magento\Quote\Model\Quote\Address\Total\Tax::collect` for
  per-line breakdown.
- Distribution via **Packagist**. Magento Marketplace submission
  is v1.1.

## What's next — implement v0.1.0 alpha

The order below is the order to do it in. Each task fits a single
focused work block (15–60 min).

### 1. Project bootstrap

- [ ] Top-level `composer.json`:
  - `name`: `ejosterberg/module-opensalestax`
  - `description`: "Magento 2 module — destination-based US sales tax via the self-hosted OpenSalesTax engine"
  - `type`: `magento2-module`
  - `license`: `Apache-2.0`
  - `require`: `php ^8.1`, `magento/framework ^103.0`
  - `require-dev`: `phpunit/phpunit ^10`,
    `phpstan/phpstan ^1.10`,
    `bitExpert/phpstan-magento ^0.31`,
    `magento/magento-coding-standard ^32`
  - `autoload.psr-4`: `"EJOsterberg\\OpenSalesTax\\": "EJOsterberg/OpenSalesTax/"`
  - `autoload.files`: `["EJOsterberg/OpenSalesTax/registration.php"]`
- [ ] `phpunit.xml.dist` pointing at `Test/Unit/`
- [ ] `phpstan.neon` extending `bitExpert/phpstan-magento`
  ruleset, level 8
- [ ] `phpcs.xml` referencing `Magento2` standard
- [ ] `.gitignore` (vendor/, .phpunit.cache/, build/)
- [ ] `LICENSE` (Apache-2.0 text)
- [ ] `CONTRIBUTING.md` — DCO sign-off mandatory, no AI
  co-author trailers, branch model (single-branch, semver tags)
- [ ] `SECURITY.md` — vulnerability reporting

### 2. OST HTTP client

- [ ] Port `opensalestax-medusa/src/providers/opensalestax/client.ts`
  → `EJOsterberg/OpenSalesTax/Model/OstaxClient.php`. Replace
  global `fetch` with `\Magento\Framework\HTTP\Client\Curl`
  injected via constructor.
- [ ] Methods:
  - `calculate(array $payload): array` — POST `/v1/calculate`,
    return decoded JSON
  - `healthCheck(): array` — GET `/v1/health`, return
    `{ok, version, db_connected, rtt_ms}`
- [ ] Pulls `api_url` (+ optional `api_token`) from
  `\Magento\Framework\App\Config\ScopeConfigInterface`. Token
  decryption via
  `\Magento\Framework\Encryption\EncryptorInterface`.
- [ ] SPDX header. PHPDoc on every public method.
- [ ] Unit test against a mocked `Curl` client.

### 3. Module registration

- [ ] `EJOsterberg/OpenSalesTax/registration.php`:
  ```php
  <?php
  // SPDX-License-Identifier: Apache-2.0
  declare(strict_types=1);
  use Magento\Framework\Component\ComponentRegistrar;
  ComponentRegistrar::register(
      ComponentRegistrar::MODULE,
      'EJOsterberg_OpenSalesTax',
      __DIR__
  );
  ```
- [ ] `EJOsterberg/OpenSalesTax/etc/module.xml` declaring the
  module + dependency on `Magento_Tax` and `Magento_Quote`
- [ ] `EJOsterberg/OpenSalesTax/composer.json` — module-level
  composer manifest for Marketplace eligibility (same name as
  top-level for now; can split later)

### 4. ADR — pick the tax extension pattern

- [ ] Write `specs/decisions/001-tax-extension-point.md`.
  Compare the two candidates:
  - **A.** `<preference>` swapping
    `Magento\Tax\Api\TaxCalculationInterface` for
    `EJOsterberg\OpenSalesTax\Model\TaxCalculation`. Full
    control over the API surface; risk of clashing with other
    modules that also `<preference>` the same interface.
  - **B.** `<plugin>` on
    `Magento\Tax\Model\Calculation::getRate`. Narrower seam;
    plays nicely with other tax-adjacent modules; only sees
    rate-lookup calls, doesn't need to reimplement the full
    interface.
- [ ] Decide. Document the rationale. Reference Magento DevDocs
  on `<preference>` vs `<plugin>` ordering.

### 5. Tax calculation class

- [ ] Implement per the ADR. If preference: `Model/TaxCalculation.php`
  implements `Magento\Tax\Api\TaxCalculationInterface`. If
  plugin: `Plugin/CalculationPlugin.php` with `afterGetRate`.
- [ ] Wire in `etc/di.xml`
- [ ] Gate logic (constitution §5): if
  `$quote->getQuoteCurrencyCode() !== 'USD'`, or shipping/billing
  address country !== US, return `null` / call original /
  fall back to parent depending on the chosen pattern.
- [ ] Call `OstaxClient::calculate()`; on engine failure, apply
  the fail-soft policy (return null/original result + log
  warning) UNLESS `osstax/general/fail_hard=1`.
- [ ] PHPDoc + SPDX header.
- [ ] Unit test with mocked `OstaxClient` + mocked
  `ScopeConfigInterface`.

### 6. Quote totals tax plugin

- [ ] `Plugin/QuoteTotalsTaxPlugin.php` with `afterCollect` on
  `Magento\Quote\Model\Quote\Address\Total\Tax`. Reads the
  per-line OST response (cached on the quote address extension
  attributes from step 5) and writes it into the totals
  collector's per-line tax buckets so checkout summary shows
  the breakdown.
- [ ] Wire in `etc/di.xml`.
- [ ] Unit test against a mocked `Total\Tax` + mocked quote.

### 7. Admin settings

- [ ] `EJOsterberg/OpenSalesTax/etc/acl.xml` — adds
  `EJOsterberg_OpenSalesTax::config` resource under
  `Magento_Backend::admin / Magento_Backend::stores /
  Magento_Backend::store / Magento_Config::config /
  Magento_Tax::config_tax` so only authorized admins see it.
- [ ] `EJOsterberg/OpenSalesTax/etc/adminhtml/system.xml` —
  adds a new "OpenSalesTax" group under Sales → Tax with
  fields:
  - `osstax/general/api_url` (text; required; validated as URL)
  - `osstax/general/api_token` (obscure; encrypted via
    `Magento\Config\Model\Config\Backend\Encrypted` backend
    model)
  - `osstax/general/fail_hard` (yes/no; default no)
- [ ] `EJOsterberg/OpenSalesTax/etc/config.xml` — defaults
  (empty URL, fail_hard=0)

### 8. Tests

- [ ] `Test/Unit/Model/OstaxClientTest.php` — happy path
  (mock Curl returns 200 + valid body), 5xx (returns engine
  error array), timeout (returns engine error), health check.
- [ ] `Test/Unit/Model/TaxCalculationTest.php` (or the plugin
  variant): USD/US gate, fall-back for non-USD, fall-back for
  non-US, fail-soft default, fail-hard opt-in, line-item
  round-trip math.
- [ ] `Test/Unit/Plugin/QuoteTotalsTaxPluginTest.php` —
  per-line breakdown gets written to the totals collector.
- [ ] Target ≥10 tests at v0.1.0 ship time.

### 9. Release

- [ ] `CHANGELOG.md` v0.1.0 entry
- [ ] Tag `v0.1.0`, push to GitHub
- [ ] Register the repo at <https://packagist.org/packages/submit>
  if not already done. Subsequent tags auto-publish via the
  Packagist GitHub webhook.
- [ ] Verify a `composer require ejosterberg/module-opensalestax
  --dry-run` resolves against Packagist.

## What's deferred to v0.2

- Magento Marketplace submission (multi-week review)
- Tax-class → OST-category mapping (admin UI surface)
- Per-state nexus filter
- Operator telemetry (failure streak, system message alerts)
- Customer-group exemption-certificate hooks
- MFTF end-to-end test suite

## Standing rules

- Apache-2.0; DCO sign-off mandatory; no AI co-author trailers
- Constitution §5: USD-only, US-only; non-USD / non-US falls
  back to Magento's built-in `tax_rate` calc
- Constitution §8: fail-soft default; fail-hard opt-in via
  admin config
- Constitution §7: admin-config endpoints require ACL +
  form_key — never add public-facing endpoints

## Pre-flight for the next session

1. Read `specs/constitution.md`
2. Read `specs/current-state.md`
3. Read `specs/research/magento-tax-module.md`
4. Skim recent commits (`git log --oneline -10`)
5. Start at task 1 above

When the alpha ships, log it in `current-state.md` and replace
this handoff with the v0.2 starting list.
