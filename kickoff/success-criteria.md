# Success criteria — what "near-perfect v1.0" means

> This file is the single canonical tracker for the kickoff
> plan's exit condition. Stage 06 (iteration loop) terminates
> when every "Open" item is moved to "Resolved" or "Deferred
> to v1.1 (with documented rationale)."
>
> Update this file after every meaningful fix during stage 06.

## Headline target

Anyone can `composer require ejosterberg/module-opensalestax`
into a clean Magento 2.4.6+ install and have a production-quality
tax calculation routing real US sales tax through their
self-hosted OpenSalesTax engine within 10 minutes — with zero
known critical issues.

## Functional success criteria

| # | Criterion | Status |
|---|-----------|--------|
| F1 | Module registers cleanly: `bin/magento module:status` shows `EJOsterberg_OpenSalesTax` enabled after `setup:upgrade` | Open |
| F2 | `composer require ejosterberg/module-opensalestax` from a clean Magento install works end-to-end | Open |
| F3 | US/USD round-trip via storefront: $100 MN cart returns correct per-line + shipping tax in <2s | Open |
| F4 | US/USD round-trip via REST API: same payload via `/rest/V1/carts/.../totals-information` produces the same numbers | Open |
| F5 | Non-USD checkout: returns control to Magento's built-in tax_rate calc (no OST call logged) | Open |
| F6 | Non-US shipping address: returns control to Magento's built-in tax_rate calc | Open |
| F7 | Engine 5xx (default fail-soft): returns Magento's built-in tax rate, logs warning, checkout proceeds | Open |
| F8 | Engine 5xx with `osstax/general/fail_hard=1`: raises checkout exception, blocks the flow | Open |
| F9 | Tax breakdown: per-jurisdiction rates surface in the cart / order summary screens (not just a single total) | Open |

## Quality success criteria

| # | Criterion | Status |
|---|-----------|--------|
| Q1 | `composer check` (phpunit + phpstan + phpcs + composer audit) passes on `main` | Open |
| Q2 | Test coverage: ≥80% lines overall, ≥85% for `Model/` and `Plugin/` | Open |
| Q3 | CI green on `main` HEAD | Open |
| Q4 | No `mixed` return types without inline justification comment | Open |
| Q5 | No `@phpstan-ignore-line` without follow-up issue link | Open |
| Q6 | SPDX header on every PHP and XML file | Open |
| Q7 | PHPDoc on every public method on `OstaxClient`, tax calc class, plugins | Open |
| Q8 | `declare(strict_types=1);` at the top of every PHP file | Open |

## Security success criteria

| # | Criterion | Status |
|---|-----------|--------|
| S1 | OWASP A01-A10 manual review walked; findings filed | Open |
| S2 | SonarQube: 0 BLOCKER issues | Open |
| S3 | SonarQube: 0 CRITICAL issues | Open |
| S4 | SonarQube: security rating A (1.0) | Open |
| S5 | SonarQube: 0 unreviewed security hotspots | Open |
| S6 | `composer audit`: 0 advisories | Open |
| S7 | API token stored encrypted via `Magento\Framework\Encryption\Encryptor`; not logged in any code path (verified by inspection + negative test) | Open |
| S8 | Quote payloads do not appear in logs (no PII leak; verified by inspection of test-run log output) | Open |
| S9 | Admin config section gated by ACL `EJOsterberg_OpenSalesTax::config` | Open |
| S10 | `osstax/general/api_url` validated at save time (URL parser + scheme allowlist) | Open |
| S11 | `specs/security/audit-YYYY-MM-DD.md` committed | Open |

## Deployment success criteria

| # | Criterion | Status |
|---|-----------|--------|
| D1 | Demo Proxmox VM provisioned (magento-demo, VMID 900-999) | Open |
| D2 | Magento 2.4.6+ devbox running on the demo VM via `markshust/docker-magento` | Open |
| D3 | OST engine running on the demo VM (separate from Magento container) | Open |
| D4 | Module installed into the devbox via Composer path repository | Open |
| D5 | Module enabled and configured via admin (API URL set, optional token, fail_hard=no) | Open |
| D6 | Real $100 MN checkout returns nonzero plausible tax through the full stack | Open |
| D7 | `composer require ejosterberg/module-opensalestax` from Packagist (not the path repo) succeeds on a separate clean Magento install | Open |
| D8 | Magento Marketplace submission opened OR deferred to v1.1 with documented rationale | Open |

## Documentation success criteria

| # | Criterion | Status |
|---|-----------|--------|
| X1 | README walks a new merchant from `composer require` to first taxed checkout in ≤10 minutes | Open |
| X2 | README documents every admin config field (path, purpose, default, example value) | Open |
| X3 | README has a "Troubleshooting" section covering: install fails, module disabled after upgrade, DI compile error, engine unreachable, tax returns zero unexpectedly | Open |
| X4 | CHANGELOG.md follows Keep-a-Changelog format; v0.1.0 → v1.0.0 entries complete | Open |
| X5 | SECURITY.md describes vulnerability reporting process | Open |
| X6 | CONTRIBUTING.md mandates DCO sign-off and Apache-2.0 license agreement | Open |
| X7 | `specs/constitution.md`, `specs/current-state.md`, `specs/handoff.md` all current | Open |
| X8 | `specs/decisions/001-tax-extension-point.md` exists and documents the preference-vs-plugin rationale | Open |

## Release success criteria

| # | Criterion | Status |
|---|-----------|--------|
| R1 | `v1.0.0` tag exists on origin/main | Open |
| R2 | GitHub release `v1.0.0` published with release notes | Open |
| R3 | Packagist auto-published v1.0.0 (verify via `composer require --dry-run` against a clean install) | Open |
| R4 | Magento Marketplace submission opened OR deferral documented in `specs/decisions/` | Open |
| R5 | `kickoff/` archived | Open |
| R6 | Summary message sent to Eric | Open |

## Status legend

- **Open** — work needed; sitting in the stage 06 backlog
- **Resolved (commit `<sha>`)** — fixed and verified
- **Deferred to v1.1** — won't fix in this release;
  rationale in `specs/decisions/NNN-<slug>.md` and tracked
  in `specs/handoff.md`
- **N/A** — criterion not applicable to this release (rare;
  needs explicit justification)

## Stage 06 exit condition

When every row above is either Resolved, Deferred-to-v1.1, or
N/A: the iteration loop is complete. Proceed to stage 07.

If any row remains Open after a reasonable iteration attempt
and the path forward isn't clear, pause and ask Eric — see
`06-iteration-loop.md` "When to ask the user."
