# Current State — opensalestax-magento

**Last updated:** 2026-05-15 (v1.3.3 shipped)
**Status:** **v1.3.3 released.** Composer-installable Magento 2 module wired against the OpenSalesTax engine. Unit-tested (74 tests, all green on PHP 8.1 + 8.2 CI matrix). PHPStan level 8 + PHPCS (Magento2 standard) + composer audit all clean. v1.3.3 fixed Bug D (plugin method arity didn't match target — `ArgumentCountError` on every checkout); v1.3.2 fixed Bug C (di.xml plugin target was non-existent class); v1.3.1 fixed Bugs A+B (backend-model Interceptor ctor pattern; engine v0.58 payload schema); v1.3.0 added per-tax-class → OST-category mapping. **Anyone on v1.3.0 / v1.3.1 / v1.3.2 should upgrade to v1.3.3 immediately** — v1.3.3 is the first release where Magento checkouts actually compute tax.

## What's shipped

### v0.1.0 (2026-05-13) — alpha

Initial installable release: HTTP client, the two plugins, admin config, ADR-001, 33 unit tests.

### v1.0.0 (2026-05-13) — production

- Typed exception hierarchy under `Exception\` (`OstaxEngineException` + 3 subclasses).
- `OstaxResponse::fromArray` refactor → cognitive complexity 16 → ≤10.
- Full SonarQube clean: zero open issues across all severities; security rating A.
- Demo VM provisioned (`magento-demo`, VMID 914, 10.32.161.183) — Magento bootstrap deferred to v1.1 pending Marketplace credentials.

### v1.1.0 (2026-05-13) — security hardening

- Backend URL revalidation: `Model\Config\Backend\ApiUrl` + `Model\Validator\ApiUrlValidator` apply server-side scheme allowlist + URL parse on save.
- New admin toggle `osstax/general/restrict_to_public_ips` (default off): when enabled, rejects engine URLs that resolve to RFC1918 / loopback / link-local / reserved IPs.
- Closes the two v1.0 audit carry-overs (A05, A10). Documented in `specs/security/audit-2026-05-13-v1.1.md`.
- 54 unit tests (up from 33). NCLOC 590 → 695.

### v1.2.0 (2026-05-13) — DNS rebinding closed

- Save-time IP pin: backend_model writes `osstax/general/api_url_pinned_ip` via `WriterInterface` when the toggle is on.
- Runtime `CURLOPT_RESOLVE`: `OstaxClient` reads the pin and forces cURL to dial that IP — bypasses DNS at request time, defeats DNS rebinding.
- Bundled with the existing `restrict_to_public_ips` toggle (label updated to "Restrict and Pin Engine URL"). Default still off.
- Closes the v1.1 caveat. Documented in `specs/security/audit-2026-05-13-v1.2.md`.
- 63 unit tests (up from 54). NCLOC 695 → 747.

### v1.3.3 (2026-05-15) — Bug D fix (plugin method arity)

- **Bug D** — `QuoteTotalsTaxPlugin::beforeCollect` declared `(object $subject, object $shippingAssignment, object $total)` (1+2 args). Magento's compiled Interceptor uses the plugin signature to decide what to forward to the parent; the target `Magento\Tax\Model\Sales\Total\Quote\Tax::collect(Quote, ShippingAssignment, Total)` takes 3 args. Once Bug C unmasked the plugin in v1.3.2, every `collectTotals()` crashed with `ArgumentCountError`. Both `beforeCollect` and `afterCollect` now mirror the target's 3-arg shape exactly.
- **`Test\Unit\Etc\PluginAritySignatureTest`** — generic regression coverage for the entire class of arity-mismatch bugs. Uses `ReflectionMethod` to walk every plugin's `before*`/`after*`/`around*` methods and asserts each declares the right number of parameters relative to the target method (looked up via a curated `TARGET_METHOD_ARITIES` map keyed by target class + method, with verifying `vendor/magento/...` path comments). Verified to fail on the historic 1+2 sig.
- 74 unit tests / 157 assertions (was 73 / 153).

### v1.3.2 (2026-05-15) — Bug C fix (di.xml totals-plugin target)

- **Bug C** — `etc/di.xml` registered the totals plugin against `Magento\Quote\Model\Quote\Address\Total\Tax` since v0.1.0. That class doesn't exist in Magento 2.4.x (the actual collector is `Magento\Tax\Model\Sales\Total\Quote\Tax`). Magento's DI compiler silently no-ops plugins on non-existent target classes, so `setup:di:compile` exited clean and the bug was invisible until a real `collectTotals()` call ran on VM 914. Single-line di.xml fix.
- **Regression test added** (`Test\Unit\Etc\DiXmlTargetClassTest`) parses every `<type name="…">` in di.xml and asserts the named class is loadable in PHP's class table or on a curated allowlist with verifying `vendor/magento/...` path comments. Verified to fail on the historic buggy class name.
- 73 unit tests / 153 assertions (was 71 / 145).

### v1.3.1 (2026-05-15) — Backend-model Interceptor ctor + engine v0.58 payload

- **Bug A** — `Model\Config\Backend\ApiUrl` (since v1.1.0) and `…\CategoryMapping` (new in v1.3.0) ctors used `(custom-dep, ...$parentArgs)` variadic pattern that broke Magento Interceptors (which forward parent ctor args BY POSITION). `bin/magento config:set` and admin save crashed with TypeError. Replaced with explicit Magento backend-model parent signature; added matching PHPStan stubs.
- **Bug B** — `Plugin\QuoteTotalsTaxPlugin::buildPayload()` emitted the legacy `{quote_id, destination, lines, shipping_amount}` shape; engine v0.58 only accepts the SDK-canonical `{address: {zip5}, line_items[]}` shape. Live MN cart silently returned $0 tax under fail-soft default. Refactored to canonical shape with decimal-string amounts.
- `Model\OstaxResponse` parser also updated for v0.58 response (no `line_id`, `rate_pct` percent strings); `extractRate()` helper accepts both shapes for forward/back compat.
- 71 unit tests / 145 assertions (was 70).

### v1.3.0 (2026-05-15) — Tax-class → OST-category mapping

- `OstCategory` canonical 7-value vocabulary (ADR-005 cross-portfolio): `general`, `clothing`, `groceries`, `prescription_drugs`, `prepared_food`, `digital_goods`, `''` (skip).
- `CategoryMapping` backend model — admin posts JSON `{tax_class_id: ost_category, ...}`; backend validates + serializes for `core_config_data`.
- `Config::resolveCategory(int $taxClassId)` resolves the mapped category at request time; defaults to `general` when unmapped.
- `QuoteTotalsTaxPlugin` now sends per-line OST categories in `POST /v1/calculate` (was hardcoded `general` before).
- Admin UI: new "Category Mapping" group under *Stores → Configuration → Sales → OpenSalesTax*.
- 70 unit tests (up from 63). No breaking changes — merchants without a configured mapping see identical v1.2 behavior.

## Where the upstream engine is

OpenSalesTax engine v1 HTTP API. Shared dev instance at `http://10.32.161.126:8080` (v0.55.4 confirmed during stage 04 health check). Production-ready engine pin: v0.22+ (pre-v0.22 had the SD-state-bleed bug closed in v0.22.0).

## Where the platform is

Magento 2 `^2.4.6`. Adobe's lifecycle policy keeps 2.4.6 + 2.4.7 supported through 2027. CI matrix: PHP 8.1 + 8.2 (8.3 deferred — `magento/magento-coding-standard ^32.0` caps at 8.2; lift constraint when Magento publishes the next standard).

## Spec-folder map

| File | Purpose |
|---|---|
| `specs/constitution.md` | Non-negotiable principles (license, architecture, USD-only) |
| `specs/current-state.md` | This file — snapshot for fresh sessions |
| `specs/handoff.md` | What the next session should pick up (v1.1 candidates) |
| `specs/research/magento-tax-module.md` | Magento Tax extension points — preference vs plugin, totals collector, admin config patterns |
| `specs/decisions/001-tax-extension-point.md` | ADR — plugin on `Calculation::getRate` vs preference on `TaxCalculationInterface` |
| `specs/security/audit-2026-05-13.md` | Stage 04 initial security audit (raised 1 CRITICAL + 8 MAJOR) |
| `specs/security/audit-2026-05-13-followup.md` | Stage 06 follow-up audit (0 open after refactor) |
| `specs/security/audit-2026-05-13-v1.1.md` | v1.1 audit (A05 + A10 carry-overs closed) |
| `specs/security/audit-2026-05-13-v1.2.md` | v1.2 audit (DNS rebinding closed via IP pinning) |
| `specs/demo-deployment.md` | Stage 05 status — VM up, Magento bootstrap blocked on Marketplace credentials |

## Distribution

- **GitHub:** <https://github.com/ejosterberg/opensalestax-magento>
- **Packagist:** `ejosterberg/module-opensalestax` (submission queued; pending Eric clicking Submit at <https://packagist.org/packages/submit>)
- **Magento Marketplace:** deferred to v1.1 (constitution explicitly defers this — 4-8 week review cycle)

## Sibling-project map

| Path | Stack | State |
|---|---|---|
| `opensalestax-Odoo/` | Planning hub | active |
| `opensalestax-python/` | Python SDK | shipped to PyPI |
| `opensalestax-odoo-src/` | Odoo connector | v0.4.1 shipped on PyPI; OCA PR queued |
| `opensalestax-medusa/` | Medusa v2 plugin | shipped; NPM `@ejosterberg/medusa-plugin-opensalestax` |
| `opensalestax-woocommerce/` | WordPress plugin | shipped |
| `opensalestax-stripe-php/` | Stripe-PHP connector | shipped, private repo pending Packagist flip |
| `opensalestax-php/` | PHP SDK | shipped, private repo pending Packagist flip |
| `opensalestax-saleor/` | Saleor Tax App | pre-alpha, specs only |
| `opensalestax-vendure/` | Vendure plugin | pre-alpha, specs only |
| `opensalestax-magento/` | **THIS** — Magento 2 module | **v1.3.3 shipped** (v1.3.0/v1.3.1/v1.3.2 broken at runtime — upgrade) |
