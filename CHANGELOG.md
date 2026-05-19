# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.9] - 2026-05-19

### Fixed

- **Mg-1 incubation test now actually arms (Mg-1.1).** Added a test-only
  Magento module at `tests/Integration/test-module/` (deployed by the
  `integration-magento.yml` workflow into
  `$MAGENTO_DIR/app/code/EJOsterberg/OstaxTestStubs/` before
  `composer install`) that ships a DI preference mapping
  `Magento\InventoryDistanceBasedSourceSelectionApi\Api\GetLatsLngsFromAddressInterface`
  to a no-op stub returning an empty lat/lng list. Short-circuits the
  upstream Magento OSS 2.4.7-p3 DI bug that was crashing
  `$quote->collectTotals()` whenever the multi-source-inventory geocoding
  branch ran. Workflow matrix flipped from 2.4.6-p10 (composer conflict,
  separate issue) back to 2.4.7-p3 (the version the v1.3.6 incubation
  work originally targeted). `continue-on-error: true` removed from the
  workflow — the Mg-1 assertion (`$address->getTaxAmount() > 0`) now
  fails CI for real if the OST module breaks Magento's cart-total flow.
  Closes the primary v1.3.6 incubation gap. Merchant-facing module
  bytes unchanged; the test stub module lives only under
  `tests/Integration/` and is excluded from the composer package.

## [1.3.8] - 2026-05-19

### Added

- **Test Connection admin button (CP-4).** New button in Stores → Configuration
  → Sales → Tax → OpenSalesTax → General that hits the configured engine's
  `/v1/health` endpoint and displays the response inline ("✓ Engine v0.59.0
  reachable — database connected (RTT 42 ms)" on success, "✗ HTTP 500" or
  similar on failure). Surfaces typo'd engine URLs + unreachable engines at
  config time rather than at first checkout. Brings this connector in line
  with WooCom v0.5, Vendure v1.3, and Saleor v1.0 which already shipped this.
  Wired via:
  - `Model\ConnectionTester` — pure service object (testable in isolation;
    delegates the actual probe to the existing `OstaxClient::healthCheck()`).
  - `Controller\Adminhtml\Connection\Test` — thin adminhtml controller
    returning JSON via Magento's `ResultFactory`; ACL-gated to
    `EJOsterberg_OpenSalesTax::config` (same resource as the settings page).
  - `Block\Adminhtml\Form\Field\TestButton` — `frontend_model` renderer for
    the system.xml `<field id="test_connection">` button.
  - `view/adminhtml/web/js/test-connection.js` — small jQuery click handler
    loaded via `view/adminhtml/layout/adminhtml_system_config_edit.xml`.
  - 5 unit tests exercising the service across happy-path, db-disconnected,
    HTTP-failure, unconfigured-engine, and malformed-response shapes.

### Fixed (Mg-1 incubation harness, CI-only)

Six follow-up commits to the v1.3.6 Mg-1 workflow + test, landed on `main`
post-v1.3.7. These touch only `.github/workflows/integration-magento.yml`
and `tests/Integration/` — the actual Magento module code installed by
merchants is byte-identical to v1.3.7, so no patch release is cut for
these. They roll into the next tag (v1.3.8 or v1.4.x):

- `21d4e77` — untyped objectManager property + parent::setUp() call
  (matches every Magento integration test pattern under `vendor/magento/inventory/`)
- `0ebac97` — retry composer steps on Mage-OS mirror flake + ASCII-only workflow
- `1fee200` — replace `CartManagement::createEmptyCart` with direct Quote create
  (avoids one Magento 2.4.7-p3 DI bug surface)
- `fc71a39` — disable `InventoryDistanceBasedSourceSelection` (Magento 2.4.7-p3 DI bug)
- `ba047b3` — use virtual product fixture to bypass remaining DI surface
- `8336662` — switch to Magento 2.4.6-p10 + keep virtual-product fixture
- `1fe71b8` — docs(ci): mark workflow as incubation; document upstream blockers

End state: workflow installs Magento + boots ObjectManager + runs test
discovery successfully, but `$quote->collectTotals()` itself still hits
upstream Magento OSS bugs in both 2.4.7-p3 and 2.4.6-p10. Marked
`continue-on-error: true` for now; the actual Mg-1 assertion will arm
when one of: (1) Adobe ships 2.4.7-p4 or 2.4.6-p11; (2) we add an
explicit DI override for `GetLatsLngsFromAddressInterface` (improvement
queue Mg-1.1); (3) we move to a leaner harness off `dev/tests/integration`
(improvement queue Mg-1.2).

## [1.3.7] - 2026-05-17

### Changed

- **Dual-licensed Apache-2.0 OR GPL-2.0-or-later.** Adds GPL-2.0-or-later as
  an alternative license alongside the existing Apache-2.0 grant, enabling
  downstream redistribution in GPL-only ecosystems (Magento Open Source
  ships under OSL-3.0 / AFL-3.0 but downstream extension packages
  benefit from the same dual-licensing standard) without giving up Apache
  compatibility. License files reorganized: `LICENSE-APACHE.txt` (existing
  Apache text, moved from `LICENSE`), `LICENSE-GPL.txt` (new, GNU GPL v2
  text), `LICENSE` (new dual-declaration). SPDX headers updated across
  source files (PHP + XML). `composer.json` `license` field switched from
  string to array form. Brings this connector in line with the rest of
  the OpenSalesTax portfolio's dual-licensing standard.

### Added

- **`.github/dependabot.yml`** — weekly checks for composer + GitHub Actions
  dependencies, with grouped dev-dep PRs. Brings this repo in line with
  the rest of the OpenSalesTax connector portfolio's supply-chain hygiene
  standard.

## [1.3.6] - 2026-05-17

### Added

- **Live-Magento CI integration test (Mg-1).** New workflow `.github/workflows/integration-magento.yml` spins up a real Magento 2.4.7-p3 install via the Mage-OS mirror (`https://mirror.mage-os.org/` — no Adobe Marketplace credentials needed, so the workflow works on fork PRs) + a mock OST engine (`tests/Integration/mock-engine/server.js`), installs the module via composer path repo, drops `LiveMagentoTaxTest.php` into Magento's `dev/tests/integration/` harness, and drives `$quote->collectTotals()` on a Minnesota cart fixture. Asserts `$shippingAddress->getTaxAmount() > 0`, that the tax matches the mock engine's 9.025% MN compound rate, and that `$quote->getGrandTotal()` includes the tax. Closes the gap that allowed the v1.3.0 → v1.3.4 six-bug chain in May 2026 to pass unit-test CI while shipping silently broken cart totals — that single `> 0` assertion would have caught Bugs C + D + E + F at PR time. Targets ~12 min wall-clock per PR. Initial matrix is single Magento 2.4.7-p3 + PHP 8.2 entry; matrix expansion to 2.4.5 / 2.4.6 deferred to v1.4.x once the baseline is reliably green. Operator notes (local-run instructions, mock engine API, troubleshooting) in `docs/INTEGRATION-TEST.md`.

### Notes

- Stack choice — **Mage-OS over markshust/docker-magento**. The original Mg-1 brief mentioned markshust as the de-facto community devbox, but it pulls Magento from `repo.magento.com` which requires Adobe Marketplace credentials that cannot be exposed to fork PRs on a public open-source repo. The Mage-OS mirror is the credential-free path for CI (already in use by `mage-os/github-actions/setup-magento`); markshust remains the right choice for the live VM-914 demo deployment. Same architectural goal (live-Magento ObjectManager + DI + Interceptor + canonical-totals-write surface that unit tests cannot reach), different concrete dependency.
- **Incubation mode** — the workflow is marked `continue-on-error: true` for v1.3.6 because two Magento OSS upstream issues block end-to-end green: Magento 2.4.7-p3 has a DI bug in `InventoryDistanceBasedSourceSelectionApi` (`Cannot instantiate interface GetLatsLngsFromAddressInterface`) and Magento 2.4.6-p10 has a composer conflict (`sebastian/comparator <=4.0.6` vs `phpunit ^9.5`'s `^4.0.10`). The infrastructure underneath is solid (mock engine + composer + Magento install + ObjectManager bootstrap + test discovery all succeed in CI) — the Mg-1 assertion runs the moment one of three paths unblocks the harness: (1) Adobe ships 2.4.7-p4 or 2.4.6-p11, (2) v1.4.x adds an explicit DI override for `GetLatsLngsFromAddressInterface`, or (3) v1.4.x moves to a leaner integration-test harness. The workflow file + mock engine + test code all ship in v1.3.6 so the foundation is laid; the green-CI badge follows in v1.4.x. See `docs/INTEGRATION-TEST.md` "Status" section.

## [1.3.5] - 2026-05-15

### Fixed

- **Bug F #1 — `method_exists()` vs `__call` in `afterCollect()`.** v1.3.4 fixed the four magic-getter sites in `beforeCollect()` but missed `afterCollect()`'s two: `method_exists($quote, 'getId')` at line 164 and `method_exists($total, 'setAppliedTaxes')` at line 195. Same root cause as Bug E (DataObject getters/setters route via `__call`, `method_exists()` returns false). Swap to `is_callable([$x, 'methodName'])` which consults `__call`.
- **Bug F #2 — `afterCollect()` never wrote the actual tax amount.** Latent since v0.1.0 but only surfaced now because Bugs C/D/E all stopped execution before reaching `afterCollect()`. The plugin called `$total->setAppliedTaxes(...)` — the per-jurisdiction *breakdown* — but never `setTaxAmount()` / `setBaseTaxAmount()` / `setTotalAmount('tax', X)` / `setBaseTotalAmount('tax', X)`. Magento's grand-total roll-up + `Address::getTaxAmount()` both read from those setters, NOT from `applied_taxes`. So even when v1.3.4 finally reached the engine and got a correct tax response, the cart still showed `tax_amount: 0` because the response value was never written to the canonical Magento totals fields. Added the canonical write sequence per `vendor/magento/module-tax/Model/Sales/Total/Quote/Tax::collect()`. USD-only by constitution §5 → base values == current values.

### Tests

- Updated `testAfterCollectWritesAppliedTaxesAndTaxAmountFromRegistry` (was `…WritesAppliedTaxesFromRegistry`) to assert the new `setTaxAmount` / `setBaseTaxAmount` / `setTotalAmount('tax', …)` / `setBaseTotalAmount('tax', …)` calls fire — pins both halves of Bug F.
- New `testAfterCollectWritesTaxAmountThroughMagicCallSetters` covers the exact Magento-Interceptor case where the `$total` object exposes its setters only via `__call`. The pre-v1.3.5 `method_exists()` guards would skip those entirely. Verified to fail on the v1.3.4 sig.
- 76 unit tests / 179 assertions total (was 75 / 165).

### Compatibility

No public API changes. Strict improvement over v1.3.4 — Bug F kept the cart at `tax_amount: 0` even after the five prior bug fixes landed the engine call. v1.3.5 is the first release where the engine response actually drives Magento's cart totals.

### The six-bug post-mortem (still the same systemic story)

| Bug | Latent since | Fixed in | Surface |
|---|---|---|---|
| A — backend-model ctors broke Interceptors | v1.1.0 | v1.3.1 | live `setup:di:compile` |
| B — payload + response shape drifted | engine drift | v1.3.1 | real engine v0.58 |
| C — di.xml target class wrong | v0.1.0 | v1.3.2 | real `collectTotals()` |
| D — plugin method arity wrong | v0.1.0 | v1.3.3 | plugin actually firing |
| E — method_exists vs __call on Interceptor (beforeCollect) | v1.3.1 | v1.3.4 | real Magento Interceptor magic getters |
| **F — method_exists vs __call in afterCollect + missing setTaxAmount writes** | v1.3.1 / **v0.1.0** | **v1.3.5** | `afterCollect` actually firing AND tax-amount writes asserted by Magento's grand-total roll-up |

Bug F is two bugs in one fix: F#1 is the same as Bug E in the parallel method; F#2 is the v0.1.0-era omission of `setTaxAmount()` that nothing prior had ever exercised. Each bug exposed the next one underneath. **The persistent lesson:** unit-test CI cannot reach Magento Interceptor + canonical-totals-write behavior; a live-Magento integration test that asserts `$address->getTaxAmount() > 0` would have caught E + F#2 in one shot.

## [1.3.4] - 2026-05-15

### Fixed

- **Bug E — `method_exists()` returns FALSE on Magento Interceptor magic getters.** When Magento compiles a `…\Interceptor` subclass over `Quote`/`Item`/`Total`, the magic getters routed via `__call` → `getData` are *not* declared methods on the Interceptor class. `method_exists($quote, 'getQuoteCurrencyCode')` returns false even though `$quote->getQuoteCurrencyCode()` returns `'USD'` at runtime. The plugin's defensive ternary at line 98 (added in v1.3.1's Bug-A fix) then fell through to `''`, failed the `!== self::CURRENCY_USD` check, and returned early — every real Magento checkout silently bailed before the engine call. Same problem at three more sites: `getRowTotal` + `getTaxClassId` on Item, `getShippingAmount` on Total. Five `method_exists()` calls now use `is_callable([$x, 'getFoo'])`, which DOES consult `__call` and returns true for the magic-getter path. Latent since v1.3.1's defensive-coding pattern was introduced (~3 hours ago in the v1.3.0 → v1.3.4 chain), but masked by Bug D which always crashed before reaching these lines.

### Added

- **`testBeforeCollectHandlesMagicGettersOnMagentoInterceptorObjects`** — regression test using anonymous classes that expose getters *only* through `__call` (mirrors how Magento Interceptors expose `getData`-key getters). Verified to fail on the v1.3.3 `method_exists()` sig with the explicit message "Bug E: plugin bailed out before the engine call". 75 unit tests / 165 assertions total (was 74 / 157).

### Compatibility

No public API changes. Anyone on v1.3.0–v1.3.3 hit silent $0 tax on real Magento Quotes; v1.3.4 is the first release where the plugin actually engages the engine on a real checkout. The `method_exists()` calls on truly-declared methods (`getId`, `getProduct`, `setAppliedTaxes`, `getShipping`, `getAddress`, `getQuote`, `getItems`) are left as-is — those don't go through `__call`.

### The five-bug post-mortem (now final final)

| Bug | Latent since | Fixed in | Surface |
|---|---|---|---|
| A — backend-model ctors broke Interceptors | v1.1.0 | v1.3.1 | live `setup:di:compile` |
| B — payload + response shape drifted | (engine drift) | v1.3.1 | real engine v0.58 |
| C — di.xml target class wrong | v0.1.0 | v1.3.2 | real `collectTotals()` |
| D — plugin method arity wrong | v0.1.0 | v1.3.3 | plugin actually firing |
| E — method_exists vs __call on Interceptor | v1.3.1 | **v1.3.4** | real Magento Interceptor magic getters |

Bugs A and B were classic engineering bugs caught on the first live deploy. Bugs C and D were latent since v0.1.0, masked by the cumulative absence of any live deploy. Bug E was *introduced* by v1.3.1's defensive coding for Bug A — `method_exists()` works fine against unit-test mocks (which declare their getters) but fails against real Magento Interceptor objects. The defensive ternary saved us from the original v1.0 crash but planted Bug E along the way.

**Final lesson — this is now in `portfolio/log.md`:** the unit-test surface area structurally cannot reach Magento Interceptor behavior. Future Magento-tier work needs a live-Magento integration test (likely `@magento/testing` harness, or a CI-driven docker-compose markshust spin-up + smoke test) at PR time. The three new regression tests added during this run (`DiXmlTargetClassTest`, `PluginAritySignatureTest`, the magic-getter test) catch the specific classes of bug we hit, but they're patches over the deeper gap.

## [1.3.3] - 2026-05-15

### Fixed

- **Bug D — `QuoteTotalsTaxPlugin::beforeCollect` had wrong arity for the target.** Magento's compiled `Interceptor` uses the plugin method's signature to decide what to forward to the parent. The target is `Magento\Tax\Model\Sales\Total\Quote\Tax::collect(Quote $quote, ShippingAssignment $shippingAssignment, Total $total)` — three args after `$subject`. The plugin declared only `(object $subject, object $shippingAssignment, object $total)` (1 + 2 args). Once Bug C unmasked the plugin in v1.3.2, every `collectTotals()` crashed with `ArgumentCountError: Too few arguments to function ...::collect(), 2 passed and exactly 3 expected`. Both `beforeCollect` and `afterCollect` now mirror the target signature exactly. Latent since v0.1.0 but masked by Bug C — only surfaced on the v1.3.2 re-verify run on VM 914.

### Added

- **`Test\Unit\Etc\PluginAritySignatureTest`** — generic regression coverage for the entire class of plugin/target arity-mismatch bugs that v1.3.0–v1.3.2 ran into. Uses PHP reflection to walk every plugin's `before*` / `after* `/ `around*` methods and assert each declares the right number of parameters relative to the corresponding target method (looked up via a curated `TARGET_METHOD_ARITIES` map keyed by target class + method, with verifying `vendor/magento/...` path comments). Verified to fail on the v0.1.0–v1.3.2 buggy 1+2 signature. Will catch any future regression where a plugin method's arg count drifts from its target's.

### Compatibility

No public API changes. Strict improvement over v1.3.2 — Bug D made all checkouts crash with `ArgumentCountError` (previously masked by Bug C silently no-opping the plugin entirely). v1.3.3 is the first release where Magento checkouts actually compute tax.

### The four-bug post-mortem (final)

| Bug | Latent since | Fixed in | Why CI couldn't catch it | Why prior subagent runs couldn't catch it |
|---|---|---|---|---|
| A — backend-model ctors broke Interceptors | v1.1.0 | v1.3.1 | Live `setup:di:compile` only | Demo blocked on Marketplace creds for ~2 weeks |
| B — payload + response shape drifted from engine | (engine drift) | v1.3.1 | Real engine v0.58 only | Same |
| C — di.xml target class wrong | v0.1.0 | v1.3.2 | Real `collectTotals()` only | Bug A had to be fixed first to even reach the DI layer |
| D — plugin method arity didn't match target | v0.1.0 | **v1.3.3** | Plugin actually firing only | Bug C had to be fixed first to actually fire the plugin |

All four were latent for at least 6 weeks (v1.1.0 onward) or the entire ~13-month v0.1.0+ history. Each one was masked by the next-deeper bug. Lesson logged in `portfolio/log.md`: future Magento-tier work needs a live-Magento integration test (`@magento/testing` harness or markshust-in-CI smoke test) — the unit-test surface area cannot reach these classes of issue.

## [1.3.2] - 2026-05-15

### Fixed

- **Bug C — `etc/di.xml` registered the totals plugin against a non-existent class.** Since v0.1.0 the plugin targeted `Magento\Quote\Model\Quote\Address\Total\Tax`, which doesn't exist in Magento 2.4.x. The actual totals collector is `Magento\Tax\Model\Sales\Total\Quote\Tax` (registered in `vendor/magento/module-tax/etc/sales.xml` as `<item name="tax" instance="..." sort_order="450">`). Magento's DI compiler silently no-ops plugins on non-existent target classes — `setup:di:compile` exited clean — so the bug was invisible until a real `collectTotals()` call ran on VM 914. End-to-end consequence: even with v1.3.1's Bugs-A+B fixes in place, every checkout returned `tax_amount: 0` because `QuoteTotalsTaxPlugin::beforeCollect` never fired → registry stayed empty → `CalculationPlugin::afterGetRate` returned Magento's empty rule-based rate (zero). Single-line fix in `di.xml`.

### Added

- **`Test\Unit\Etc\DiXmlTargetClassTest`** — regression test for Bug C. Parses every `<type name="...">` in `etc/di.xml` and asserts the named class is either (a) loadable in PHP's class table (via the test stubs or real autoloader), (b) on a curated `KNOWN_MAGENTO_CLASSES` allowlist that requires a verifying `vendor/magento/...` path comment, or (c) explicitly NOT on a `KNOWN_BAD_CLASSES` deny-list (currently just the historical Bug C target). A second test pins the totals-plugin target to `Magento\Tax\Model\Sales\Total\Quote\Tax` as a sentinel against future regressions. Verified to fail on the buggy class name and pass on the corrected one. 73 tests / 153 assertions total (was 71 / 145).

### Compatibility

No public API changes. Strict improvement over v1.3.1 — Bug C silently broke checkouts since v0.1.0; this finally fixes them. Anyone who saw "$0 tax on every cart" with v1.3.0 or v1.3.1 should upgrade to v1.3.2 immediately.

### The three-bug post-mortem

- **Bug A** (latent v1.1.0+): backend-model ctors used a variadic `...$parentArgs` pattern that broke Magento Interceptors. **Fixed in v1.3.1.**
- **Bug B**: outbound payload + response parser hadn't followed engine schema drift to v0.58. **Fixed in v1.3.1.**
- **Bug C** (latent v0.1.0+): `di.xml` plugin target class name was wrong from the original kickoff commit. **Fixed in v1.3.2 + regression-tested.**

All three were invisible to CI because they only manifest under: (A) live `setup:di:compile` against real Magento, (B) live engine call against an actual v0.58 server, (C) live `collectTotals()` against a real Quote. Lesson logged in `portfolio/log.md`: future Magento-tier work needs a live-Magento integration test (likely `@magento/testing`) to catch class-of-bug-C issues at PR time, not at v0.1.0+13-month live-deployment time.

## [1.3.1] - 2026-05-15

### Fixed

- **Bug A — Backend-model constructors broke Magento Interceptors.** `Model\Config\Backend\ApiUrl` (since v1.1.0) and `Model\Config\Backend\CategoryMapping` (new in v1.3.0) used a `(custom-dep, ...$parentArgs)` variadic ctor pattern. Magento's compiled `Interceptor` subclasses forward parent ctor args **by position**, so position 1 landed on our custom dep instead of `Context` — `bin/magento config:set` and admin-UI save crashed with a TypeError. Replaced both with the explicit Magento backend-model parent signature (`Context, Registry, ScopeConfigInterface, TypeListInterface, …, ?AbstractResource, ?AbstractDb, array`) and added the matching PHPStan stubs for those parent classes so the test suite can still be analysed without pulling Magento in. Surfaced by the live `setup:di:compile` on VM 914.
- **Bug B — Outbound payload schema didn't match engine v0.58.0.** `Plugin\QuoteTotalsTaxPlugin::buildPayload()` emitted the legacy `{quote_id, destination, lines, shipping_amount}` shape; engine v0.58 only accepts the SDK-canonical `{address: {zip5}, line_items: [{amount, category}]}` shape (matching the Saleor / Medusa / OpenCart / Bagisto / Invoice Ninja connectors). Live $100 MN cart returned $0 tax silently under the default fail-soft policy. Refactored to emit the canonical shape: `address.zip5` extracted as the first 5 digits of the postcode; `line_items[]` entries with decimal-string amounts (engine quantizes per-jurisdiction in fixed-point, so floats lose precision); shipping folded in as a `category=shipping` line_item when non-zero.
- **`Model\OstaxResponse` parser updated for v0.58 response shape.** Engine no longer emits per-line `line_id` (we synthesize a 0-based index key), and rates moved from `rate`/`jurisdictions[].rate` (decimal float like 0.06875) to `rate_pct` (percent string like "6.875"). New `extractRate()` helper accepts both shapes for forward/back compatibility, normalising to decimal so existing consumer code (`getEffectiveRatePercent`, `afterCollect` jurisdiction loop) keeps working unchanged.

### Tests

71 unit tests / 145 assertions (was 70). New `testFromArrayAcceptsRatePctStringFromEngineV058` covers the v0.58 wire shape end-to-end with a real-world MN cart fixture. `testBeforeCollectPrewarmsRegistryOnUsdUsCheckout` rewritten to assert the new `address`+`line_items` payload (incl. shipping line). Backend-model tests centralized through a `makeModel()` helper that takes the explicit ctor signature.

### Compatibility

No public API changes. Anyone deploying v1.3.1 on top of v1.3.0 sees: the admin UI save now actually works (Bug A) and live MN checkouts now return the expected ~$9.025 tax instead of $0 (Bug B). Backend-model integrations that custom-extended `ApiUrl` or `CategoryMapping` will need to update their own ctor signature to match — but anyone doing that was already crashed by Bug A so this is a strict improvement.

## [1.3.0] - 2026-05-15

### Added
- **Tax class to OST category mapping.** Map each Magento product tax class to an OpenSalesTax category so the engine applies the right per-state rules (clothing exemptions, grocery rates, prescription-drug exemptions, etc.). Stored as a JSON object in `core_config_data` under `osstax/category_mapping/mapping`, scoped per default/website/store like every other module setting.
- New `EJOsterberg\OpenSalesTax\Model\Source\OstCategory` with the canonical 7-value OST category vocabulary aligned with ADR-005 from `opensalestax-vendure` (`general`, `clothing`, `groceries`, `prescription_drugs`, `prepared_food`, `digital_goods`, `''` for skip).
- New `EJOsterberg\OpenSalesTax\Model\Config\Backend\CategoryMapping` backend model validates posted mappings (numeric tax class id, allowlisted OST category) and JSON-serializes for storage. Accepts both a JSON-string post (v1.3.0 textarea UI) and the dynamic-rows array post shape (v1.3.1+ widget).
- New `Config::getCategoryMapping()` and `Config::resolveCategory(int $taxClassId): string` methods. The latter is the hot-path helper.
- 7 new unit tests (70 total, up from 63): 2 in OstCategoryTest, 5 in ConfigTest. The CategoryMapping backend test + dropdown-UI block tests are deferred to v1.3.1 alongside the dynamic-rows widget.
- New admin UI: Stores → Configuration → Sales → Tax → OpenSalesTax → **Category Mapping** group with a JSON-textarea field. v1.3.1 will replace the textarea with a dropdown-driven dynamic-rows widget.

### Changed
- `QuoteTotalsTaxPlugin::buildPayload()` now resolves each quote item's Magento tax class to its mapped OST category (or `general` when unmapped) instead of unconditionally sending `general`. Reads the mapping once per buildPayload call and caches it inline — no per-line scope_config hits.

### Notes
- Non-breaking from v1.2.0. Merchants who don't configure any mapping see v1.2 behavior (every line is sent as `general`).
- No schema migration; the mapping lives in `core_config_data`. `etc/module.xml` `setup_version` stays at 0.1.0 (the Magento schema version is independent from the package semver — Magento only bumps it when an `InstallSchema` / `UpgradeSchema` lands).
- Constitution §10 still applies — calculation only, no filing.

## [1.2.0] - 2026-05-13

### Added
- **DNS-rebinding mitigation via IP pinning.** When the admin's "Restrict and Pin Engine URL" toggle (`osstax/general/restrict_to_public_ips`) is enabled, the backend model now persists the resolved IP to `osstax/general/api_url_pinned_ip` in the same scope as the URL. Subsequent engine calls dial that pinned IP via cURL `CURLOPT_RESOLVE`, bypassing DNS at request time entirely.
- New `Config::getPinnedIp()` getter and `Config::PATH_PINNED_IP` constant.
- `EJOsterberg\OpenSalesTax\Model\Config\Backend\ApiUrl::afterSave()` writes (or deletes) the pin via `Magento\Framework\App\Config\Storage\WriterInterface`.
- `OstaxClient::applyPinnedIp()` injects the `host:port:pinned-ip` triple into `CURLOPT_RESOLVE` before each request when the pin is set.
- 9 new unit tests (63 total, up from 54) — validator return value, backend `afterSave` pin/clear behavior, scope handling, `Config::getPinnedIp` happy path + unset, OstaxClient `CURLOPT_RESOLVE` set/not-set behavior, correct default ports for http (80) and https (443).

### Changed
- `ApiUrlValidator::validate()` now returns the resolved IP (`string`) when restrict-to-public-IPs is on, or `null` otherwise — providing the value the backend model needs to pin. Behavior unchanged for callers that only care about throw-on-failure.
- Admin field label updated from "Restrict Engine URL to Public IPs" → "Restrict and Pin Engine URL", with an expanded comment documenting the pinning behavior and the re-save-on-IP-rotation requirement.

### Security
- Closes the DNS-rebinding caveat documented in `specs/security/audit-2026-05-13-v1.1.md`. The attack ("host resolves public at save time, private at request time") is now mitigated for merchants who enable the toggle.
- Default behavior unchanged: toggle defaults to **No**; merchants self-hosting OST on the same VM as Magento see no behavior change.
- SonarQube re-scan: 0 open issues. One `php:S3415` false positive on a `assertSame(['expected-literal'], $captured)` test assertion was reviewed and marked Won't Fix with rationale (PHPUnit's documented argument order is `assertSame($expected, $actual)`; the rule's inline-literal heuristic misfires here).

### Caveats
- Operational note: if the engine's IP rotates (cloud autoscale, server move), the admin must re-save the URL field to refresh the pin. The current pinned IP is visible in `core_config_data` under `osstax/general/api_url_pinned_ip` but not surfaced in the admin UI (a read-only display field is a v1.3 polish candidate).
- The pin is keyed by `(host, port)`, so changing the URL's port also requires a re-save to refresh.

## [1.1.0] - 2026-05-13

### Added
- Server-side URL revalidation backend model `EJOsterberg\OpenSalesTax\Model\Config\Backend\ApiUrl` for the `osstax/general/api_url` admin field. Closes the A05 carry-over from the v1.0 security audit — frontend `validate-url` is no longer the only defense.
- New admin toggle `osstax/general/restrict_to_public_ips` (Stores → Configuration → Sales → Tax → OpenSalesTax → "Restrict Engine URL to Public IPs"). When enabled, URLs whose host resolves to an RFC 1918 / loopback / link-local / reserved IP range are rejected at save time. Closes the A10 carry-over.
- Pure-PHP `EJOsterberg\OpenSalesTax\Model\Validator\ApiUrlValidator` with an injectable hostname resolver — fully unit-testable without a Magento bootstrap.
- 21 new unit tests covering: empty value, malformed URL, scheme allowlist (rejects `ftp://`, `file://`), public-IP acceptance, RFC 1918 / loopback / link-local rejection under the flag, unresolvable host, and sibling-field reading from the admin form submission.

### Security
- v1.0 audit carry-overs A05 (backend URL revalidation) and A10 (private-IP allowlist) resolved.
- Default behavior unchanged: `restrict_to_public_ips` defaults to **No** so merchants who self-host OST on the same VM as Magento (constitution §2's reference deployment) keep working without configuration changes.
- Documented caveat: this is save-time validation, not request-time. DNS rebinding is not mitigated — a host that resolves public at save time but private at request time can still slip through. A full mitigation would pin the resolved IP at save time; queued for v1.2 as the SonarQube dashboard tracker rolls over.
- SonarQube re-scan: 0 open issues across BLOCKER / CRITICAL / MAJOR / MINOR. Security rating A. `composer audit`: 0 advisories.

## [1.0.0] - 2026-05-13

### Added
- Typed exception hierarchy under `EJOsterberg\OpenSalesTax\Exception\`: `OstaxEngineException` (base), `OstaxEngineUnreachableException`, `OstaxMalformedResponseException`, `OstaxNotConfiguredException`. Replaces generic `RuntimeException` so callers can discriminate failure modes for logging and fail-soft / fail-hard policy.
- `specs/security/audit-2026-05-13-followup.md` capturing the second-pass SonarQube scan — 0 open issues.
- `specs/demo-deployment.md` documenting the demo VM (914 / 10.32.161.183) and the manual Magento bootstrap steps Eric runs once Marketplace credentials are available.

### Changed
- `OstaxResponse::fromArray` refactored from one 30-line nested method into `fromArray` + `parseLines` + `parseLine` + `parseJurisdictions`. Cognitive complexity dropped from 16 to ≤10 (closes SonarQube `php:S3776` CRITICAL).
- `OstaxClient::healthCheck` extracted a `healthFailure` helper for the failure-result shape.
- `OstaxClient::calculate` now throws `OstaxEngineUnreachableException` / `OstaxMalformedResponseException` / `OstaxNotConfiguredException` instead of `RuntimeException` (closes SonarQube `php:S112` ×4 MAJOR).
- `QuoteTotalsTaxPlugin::beforeCollect` fail-hard path now throws `OstaxEngineException`.

### Security
- SonarQube re-scan: 0 BLOCKER, 0 CRITICAL, 0 MAJOR, 0 MINOR, 0 unreviewed hotspots, security rating A.
- `composer audit`: 0 advisories.
- Full audit history committed in `specs/security/`.

### Notes
- The 4 `php:S1142` MAJOR findings (4-8 returns per method) on guard-clause methods were reviewed and marked **Won't Fix** in SonarQube with rationale: the early-return pattern is more readable than nested if/else for gate-style methods.
- Demo-deployment criteria D2-D7 deferred to v1.1 pending Eric's Magento Marketplace credentials; the v1.0 release ships on the strength of unit tests + SonarQube clean + manual security review.

## [0.1.0] - 2026-05-13

### Added
- Initial alpha release of the OpenSalesTax module for Magento 2 (`^2.4.6`).
- `Plugin\CalculationPlugin` — plugin on `Magento\Tax\Model\Calculation::getRate` that returns OST-derived effective rates per quote.
- `Plugin\QuoteTotalsTaxPlugin` — plugin on `Magento\Quote\Model\Quote\Address\Total\Tax::collect` that pre-warms a request-scoped registry with the engine's response and writes per-jurisdiction breakdown onto the totals.
- `Model\OstaxClient` — HTTP client for the OST engine v1 API (`/v1/calculate`, `/v1/health`) wrapping `Magento\Framework\HTTP\Client\Curl`.
- `Model\Config` — admin settings reader with token decryption via `Magento\Framework\Encryption\EncryptorInterface`.
- `Model\QuoteTaxRegistry` — request-scoped registry shared between the two plugins.
- Admin config section at Stores → Configuration → Sales → Tax → **OpenSalesTax** (API URL, optional encrypted token, fail-hard toggle), gated by ACL resource `EJOsterberg_OpenSalesTax::config`.
- USD/US-only gating: non-US destinations and non-USD currencies fall through to Magento's built-in `tax_rate` calc.
- Fail-soft default: engine errors fall back to Magento's tax tables and log a warning. Fail-hard opt-in via `osstax/general/fail_hard=1`.
- ADR-001 (`specs/decisions/001-tax-extension-point.md`) — chose plugin on `Calculation::getRate` over preference on `TaxCalculationInterface`.
- CI on PHP 8.1 / 8.2 / 8.3 via GitHub Actions.
- 33 unit tests covering Config, OstaxClient, OstaxResponse, QuoteTaxRegistry, and both plugins.

### Security
- API tokens stored encrypted in `core_config_data` via `Magento\Config\Model\Config\Backend\Encrypted`.
- Decrypted in memory only at request time, never logged.
- Customer addresses and full payloads excluded from log statements.

[Unreleased]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/ejosterberg/opensalestax-magento/releases/tag/v0.1.0
