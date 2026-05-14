# Handoff — opensalestax-magento

> **Read first if you're a fresh agent.** Constitution + current state + this file are the canonical bring-up sequence.

## You are here — 2026-05-13 (v1.0.0 shipped)

The Magento 2 module shipped its first production release on 2026-05-13. The v1.0.0 tag is on `main`, GitHub release v1.0.0 is published, SonarQube dashboard is clean, all unit tests green.

## What the next session should pick up

### 1. Complete the demo deployment (carries D2-D7 from `kickoff/success-criteria.md`)

Eric needs to either:

- Generate Marketplace credentials at <https://marketplace.magento.com/customer/accessKeys/> and place them in `~/.composer/auth.json` on `magento-demo` and his Windows box, OR
- Switch the devbox bootstrap to Mage-OS (community fork, no Marketplace auth).

`specs/demo-deployment.md` documents the exact commands for both paths. Once Magento is up, the module install + admin config + $100 MN checkout test run mechanically.

After the demo lands, update `specs/current-state.md` and tick off D2-D7 in the (archived) `kickoff/success-criteria.md`.

### 2. Submit to Packagist

If not done at stage 07: open <https://packagist.org/packages/submit> in a logged-in browser, paste `https://github.com/ejosterberg/opensalestax-magento`, click Submit. Configure the GitHub webhook so future tags auto-publish. Verify with `composer require ejosterberg/module-opensalestax --dry-run` on a clean Magento install.

### 3. v1.2 polish queue (was v1.1 deferred)

In priority order:

- **DNS-rebinding mitigation for the engine URL** (v1.1 carry-over). v1.1 added save-time IP validation. v1.2 should pin the resolved IP at save time and force the runtime cURL client to dial that IP. See `specs/security/audit-2026-05-13-v1.1.md` "Caveats documented for v1.2."
- **PHP 8.3 support**. Blocked on `magento/magento-coding-standard` releasing a version that supports 8.3. Re-add to the CI matrix once the dep is bumped.
- **Tax-class → OST-category mapping**. Currently every line is sent as category `general`. Match the WooCom v0.3.3 / Odoo v0.1.13 pattern: admin UI maps Magento tax classes to OST categories.
- **Per-state nexus filter** (matches Odoo v0.3.0). Only call the engine for states where the merchant has nexus.
- **Operator telemetry**. Last successful calc, failure streak, threshold-crossing alert via Magento's system message framework.
- **Customer-group exemption-certificate hooks**. Magento has a built-in customer-group → tax-class mapping; expose an opt-out flag per group.
- **MFTF end-to-end test suite**. Heavyweight; deferred from v1.0 because the demo deployment covers the happy path.

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
