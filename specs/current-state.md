# Current State — opensalestax-magento

**Last updated:** 2026-05-13 (v1.2.0 shipped)
**Status:** **v1.2.0 released.** Composer-installable Magento 2 module wired against the OpenSalesTax engine. Unit-tested (63 tests, all green on PHP 8.1 + 8.2 CI matrix). SonarQube clean (0 open issues; A across all ratings). v1.2 closed the DNS-rebinding caveat from v1.1 via save-time IP pinning + runtime `CURLOPT_RESOLVE`.

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
| `opensalestax-magento/` | **THIS** — Magento 2 module | **v1.0.0 shipped** |
