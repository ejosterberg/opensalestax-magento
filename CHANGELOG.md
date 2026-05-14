# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/ejosterberg/opensalestax-magento/releases/tag/v0.1.0
