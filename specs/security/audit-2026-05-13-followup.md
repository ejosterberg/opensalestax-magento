# Security audit follow-up — 2026-05-13 (post stage-06 refactor)

**Project:** opensalestax-magento
**Version under review:** v1.0 candidate (commit `f69ca33`)
**Predecessor audit:** `audit-2026-05-13.md` (raised 1 CRITICAL + 8 MAJOR)
**SonarQube dashboard:** http://10.32.161.205:9000/dashboard?id=opensalestax-magento

## Headline result

**Passes the v1.0 bar.** Zero open issues across every severity. Re-scan after stage-06 refactor: cognitive complexity fixed; generic `RuntimeException` replaced with a typed exception hierarchy; early-return guard-clause MAJORs reviewed and marked Won't Fix in SonarQube with rationale.

## SonarQube counters

| Metric | Before refactor | After refactor |
|---|---|---|
| BLOCKER | 0 | 0 |
| CRITICAL | 1 | **0** |
| MAJOR | 8 | **0** (4 fixed, 4 Won't Fix) |
| Code smells (open) | 9 | **0** |
| Security rating | A (1.0) | A (1.0) |
| Reliability rating | A (1.0) | A (1.0) |
| Maintainability rating | A (1.0) | A (1.0) |
| `composer audit` | 0 advisories | 0 advisories |

## Disposition of prior findings

| Rule | Severity | Resolution |
|---|---|---|
| `php:S3776` (Cognitive Complexity 16 in `OstaxResponse::fromArray`) | CRITICAL | **Fixed** in commit `f69ca33` — extracted `parseLines` / `parseLine` / `parseJurisdictions` helpers. New complexity: ≤10. |
| `php:S112` ×4 (Generic `RuntimeException`) | MAJOR | **Fixed** in commit `f69ca33` — introduced `OstaxEngineException` base with `OstaxEngineUnreachableException`, `OstaxMalformedResponseException`, `OstaxNotConfiguredException` subclasses under `EJOsterberg\OpenSalesTax\Exception\`. |
| `php:S1142` ×4 (Too many return statements; 4-8 in guard-clause methods) | MAJOR | **Marked Won't Fix in SonarQube** with rationale: "intentional early-return guard-clause pattern — flat returns are more readable than nested if/else for gate-style methods." Affected methods: `OstaxClient::healthCheck` (5 returns), `CalculationPlugin::afterGetRate` (4), `QuoteTotalsTaxPlugin::beforeCollect` (8), `QuoteTotalsTaxPlugin::afterCollect` (4). |

## v0.2 carry-overs (not blocking v1.0)

Two deferred items from the original audit remain queued for v0.2:

- **A05 backend URL re-validation**: `api_url` currently has only frontend `validate-url` class. v0.2 candidate: custom backend_model that re-parses and applies scheme/host policy server-side.
- **A10 private-IP-range allowlist**: currently permitted to support merchants self-hosting on localhost / private network. v0.2 candidate: opt-in toggle in admin config.

Both are documented in `specs/handoff.md` as v0.2 candidates with rationale.

## Acceptance

| Bar | Threshold | This audit | Pass |
|---|---|---|---|
| v1.0 release | 0 BLOCKER; 0 CRITICAL; security A; 0 unreviewed hotspots | 0 BLOCKER; 0 CRITICAL; security A; 0 hotspots | **✓** |

Stage 06 acceptance: **PASS.** Ready to proceed to stage 07 (release v1.0.0).
