# Handoff — opensalestax-magento

> **Read first if you're a fresh agent.** Constitution + current state + this file are the canonical bring-up sequence.

## Resolved 2026-05-15 — Bugs C + D fixed in v1.3.2 + v1.3.3

The "Blocked on captain — Bug C" entry that previously occupied this
slot has been resolved. v1.3.2 (`fb39335`) shipped the di.xml fix +
`DiXmlTargetClassTest`. The re-verify run on VM 914 then surfaced
**Bug D**: `QuoteTotalsTaxPlugin::beforeCollect` had wrong arity
relative to the target's `collect(Quote, ShippingAssignment, Total)`.
Once Bug C unmasked the plugin, every checkout crashed with
`ArgumentCountError`. v1.3.3 (`d9b43ad`) ships the 4-arg signature
fix + `PluginAritySignatureTest` (generic reflection-based regression
coverage for the whole class of arity-mismatch bugs). Verified to
fail on the historic 1+2 sig.

Re-deploy v1.3.3 on VM 914 + drive `collectTotals()` end-to-end is
the captain's next step. v1.3.3 is the first release where Magento
checkouts should actually compute tax.

The full three-bug post-mortem (A latent v1.1+, B engine schema drift,
C latent v0.1.0+) lives in `specs/demo-deployment.md` § "Bug history"
and in the v1.3.2 CHANGELOG entry.

### Open VM 914 cleanup (low priority)

- Container path uses an `appdata` volume, NOT a bind mount, so host `git checkout` doesn't flow through. The re-verify subagent has been working around with `docker cp`. A future bootstrap-doc improvement: switch to a bind mount so source updates propagate naturally.

## You are here — 2026-05-15 (v1.3.3 shipped)

The Magento 2 module shipped seven releases (v1.0.0 → v1.3.3). Latest: v1.3.3 fixed Bug D (plugin method arity didn't match target — `ArgumentCountError` on every checkout); v1.3.2 fixed Bug C (di.xml plugin target was non-existent class); v1.3.1 fixed Bugs A+B (Interceptor ctor + engine v0.58 payload); v1.3.0 added per-tax-class → OST-category mapping. CI green on both PHP 8.1 + 8.2 (PHPUnit / PHPStan level 8 / PHPCS / composer audit). 74 unit tests.

## What the next session should pick up

### 1. Complete the demo deployment (carries D2-D7 from `kickoff/success-criteria.md`)

Eric needs to either:

- Generate Marketplace credentials at <https://marketplace.magento.com/customer/accessKeys/> and place them in `~/.composer/auth.json` on `magento-demo` and his Windows box, OR
- Switch the devbox bootstrap to Mage-OS (community fork, no Marketplace auth).

`specs/demo-deployment.md` documents the exact commands for both paths. Once Magento is up, the module install + admin config + $100 MN checkout test run mechanically.

After the demo lands, update `specs/current-state.md` and tick off D2-D7 in the (archived) `kickoff/success-criteria.md`.

### 2. Submit to Packagist

If not done at stage 07: open <https://packagist.org/packages/submit> in a logged-in browser, paste `https://github.com/ejosterberg/opensalestax-magento`, click Submit. Configure the GitHub webhook so future tags auto-publish. Verify with `composer require ejosterberg/module-opensalestax --dry-run` on a clean Magento install.

### 3. v1.4 polish queue (was v1.3 deferred)

In priority order:

- **Surface the pinned IP in the admin UI** (v1.2 cosmetic carry-over). Add a read-only display field under "Restrict and Pin Engine URL" that shows the currently-pinned IP. Helpful when troubleshooting a stale pin after engine IP rotation.
- **TLS certificate pinning** for HTTPS engines. v1.2 relies on standard cURL cert verification against the original hostname. A merchant on mutual TLS could opt into pinning a specific cert thumbprint.
- **PHP 8.3 support**. Blocked on `magento/magento-coding-standard` releasing a version that supports 8.3. Re-add to the CI matrix once the dep is bumped.
- **Per-state nexus filter** (matches Vendure v1.2 / Odoo v0.3.0). Only call the engine for states where the merchant has nexus.
- **Dynamic-rows widget for category mapping**. v1.3.0 ships a JSON textarea — usable but ugly. CategoryMapping backend model already accepts the rows-array shape; the UI swap is the remaining work.
- **i18n for category labels**. v1.3 ships English-only labels by design; wrap in `__()` when the dropdown UI lands.
- **Operator telemetry**. Last successful calc, failure streak, threshold-crossing alert via Magento's system message framework.
- **Customer-group exemption-certificate hooks**. Magento has a built-in customer-group → tax-class mapping; expose an opt-out flag per group.
- **MFTF end-to-end test suite**. Heavyweight; deferred from v1.0 because the demo deployment covers the happy path.

### v1.3 shipped (tax-class → OST-category mapping)

- **`OstCategory` canonical 7-value vocabulary** (ADR-005). `Model/Source/OstCategory.php`.
- **`CategoryMapping` backend model** at `osstax/general/category_mapping`. Accepts JSON, validates, JSON-encodes.
- **`Config::resolveCategory(int $taxClassId)`** maps tax class IDs at request time; defaults to `general`.
- **`QuoteTotalsTaxPlugin`** now sends per-line OST categories in `POST /v1/calculate`.

### v1.2 shipped (DNS rebinding closed)

- **DNS-rebinding mitigation**: save-time IP pin written to `osstax/general/api_url_pinned_ip` via `WriterInterface`; runtime `CURLOPT_RESOLVE` forces cURL to dial the pinned IP. Bundled with the existing `restrict_to_public_ips` toggle. Done in v1.2.0.

### v1.1 shipped (security carry-overs closed)

- **A05 — Backend URL revalidation**: server-side `parse_url` + scheme allowlist in `Model\Config\Backend\ApiUrl` + `Model\Validator\ApiUrlValidator`. Done in v1.1.0.
- **A10 — Private-IP allowlist toggle**: new admin field `osstax/general/restrict_to_public_ips` (default off; opt-in defense-in-depth). Done in v1.1.0.

### 4. Magento Marketplace submission (v1.1)

Open a developer account at <https://commercemarketplace.adobe.com/>. Submit the package for EQP review. Expect 4-8 weeks of back-and-forth on automated + manual review feedback. Pre-req: `composer.json` declaration of `extra.magento.minimal_modules` and `extra.magento.required-modules`.

## What's deferred (not on the immediate path)

- Tax filing / remittance (constitution §6 — never)
- Address validation / autocomplete (constitution §10)
- Non-USD currency / non-US destinations (constitution §5)
- Modifying upstream Magento source (constitution §2 — only via DI plugin)

## Standing rules

- Apache-2.0; DCO sign-off mandatory; no AI co-author trailers
- Constitution §5: USD-only, US-only; non-USD / non-US falls back to Magento's built-in `tax_rate` calc
- Constitution §8: fail-soft default; fail-hard opt-in via admin config
- Constitution §7: admin-config endpoints require ACL + form_key — never add public-facing endpoints

## Pre-flight for the next session

1. Read `specs/constitution.md`
2. Read `specs/current-state.md`
3. Read this file
4. Skim recent commits (`git log --oneline -10`)
5. Skim the latest entry in `specs/security/audit-*.md`
6. Pick from the "What the next session should pick up" list above
