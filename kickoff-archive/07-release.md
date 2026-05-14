# Stage 07 — Release v1.0.0

> ~30 minutes. Tag, publish, announce. The mechanical end of
> the kickoff plan.

## Pre-release sanity checks

Before tagging, verify ONE more time:

```bash
cd C:/Users/ejosterberg/Documents/GITprojects/opensalestax-magento
git status                           # clean tree
git log --oneline -5                 # last few commits look right
git remote -v                        # origin → github.com:ejosterberg/opensalestax-magento.git
composer check                       # green
gh run list --branch main --limit 1  # CI green on tip
```

If any of these fails, fix it before tagging (don't tag broken
code).

## Finalize CHANGELOG

Open `CHANGELOG.md`. The `[Unreleased]` section should hold
everything since the last tag (v0.1.0, presumably). Promote
it:

```md
## [1.0.0] - 2026-MM-DD

### Added
- Initial production release of OpenSalesTax module for
  Magento 2 (`^2.4.6`)
- Tax calculation via `<plugin>` on
  `Magento\Tax\Model\Calculation::getRate` (or `<preference>`
  on `Magento\Tax\Api\TaxCalculationInterface` — whichever the
  ADR landed on)
- Per-line breakdown via `<plugin>` on
  `Magento\Quote\Model\Quote\Address\Total\Tax::collect`
- Admin config at Stores → Configuration → Sales → Tax →
  OpenSalesTax (API URL, optional encrypted token, fail-hard
  toggle)
- USD-only / US-only gating; non-US/non-USD checkouts fall
  back to Magento's built-in tax_rate calculation
- Packagist distribution
  (`composer require ejosterberg/module-opensalestax`)
- ...

### Changed
- (anything that drifted from v0.1.0)

### Security
- SonarQube clean (0 BLOCKER, 0 CRITICAL; security rating A)
- OWASP review complete; audit at
  `specs/security/audit-YYYY-MM-DD.md`

[1.0.0]: https://github.com/ejosterberg/opensalestax-magento/compare/v0.1.0...v1.0.0
[Unreleased]: https://github.com/ejosterberg/opensalestax-magento/compare/v1.0.0...HEAD
```

Re-open a fresh `[Unreleased]` section above 1.0.0.

## Bump version

There's no `package.json`-style auto-bump in Composer's vendor
metadata — Composer reads versions from git tags. So the only
bump is in `CHANGELOG.md` and any string constants in the
module that surface the version (e.g., a `VERSION` const on the
module's main class, if added).

```bash
git add CHANGELOG.md
git commit -s -m "chore: release v1.0.0"
git push
```

Wait for CI green on this commit.

## Tag and push

```bash
git tag -s v1.0.0 -m "v1.0.0 — first production release"
git push origin v1.0.0
```

The `-s` on `git tag` GPG-signs the tag. If GPG isn't set up,
fall back to `-a` (annotated, unsigned) and add a note in the
release body that signing will land in v1.0.1.

## Create the GitHub release

```bash
gh release create v1.0.0 \
  --title "v1.0.0 — OpenSalesTax for Magento 2" \
  --notes-file - <<'EOF'
First production release of the OpenSalesTax module for
Magento 2 (`^2.4.6`).

## Highlights
- Destination-based US sales tax via merchant's self-hosted
  OpenSalesTax engine
- No third-party API keys, no per-transaction fees
- Apache-2.0; free to fork, fork to ship
- Composer-installable; no separate service to run

## Quickstart
```bash
composer require ejosterberg/module-opensalestax
bin/magento module:enable EJOsterberg_OpenSalesTax
bin/magento setup:upgrade
bin/magento cache:clean
```

Then configure at Stores → Configuration → Sales → Tax →
OpenSalesTax. See `README.md` — ≤10 minutes to first
tax-calc.

## What's in the box
- DI-wired tax calculation swap on US/USD checkouts
- Per-line tax breakdown in cart + order summary
- Admin config with encrypted API token storage
- Fail-soft default; fail-hard opt-in
- USD/US-only gating; graceful fallback for non-US carts

## Security
SonarQube clean (0 BLOCKER, 0 CRITICAL, A rating). OWASP A-list
review captured in `specs/security/`. Reports welcome via
`SECURITY.md`.

## Thanks
To the Magento community for an extensible DI architecture, and
to the OpenSalesTax engine project that does the actual math.
EOF
```

Verify in browser: `gh release view v1.0.0 --web`.

## Publish to Packagist

Packagist (`https://packagist.org`) auto-publishes tags via a
GitHub webhook — **but only if the repo is registered**. If
v0.1.0 was already submitted, the v1.0.0 tag publishes
automatically within ~1 minute of the push.

If not yet registered:

```bash
# Open the submit form in your browser:
gh browse 'https://packagist.org/packages/submit'
# Paste: https://github.com/ejosterberg/opensalestax-magento
# Click Submit.
```

You need to be logged into Packagist with an account that
controls the `ejosterberg` namespace (or unclaimed; Packagist
matches the GitHub username automatically for first-time
submissions).

Verify after submission:

```bash
curl -sS 'https://packagist.org/packages/ejosterberg/module-opensalestax.json' | \
  jq '.package.versions | keys[]'
# Should list v0.1.0, v1.0.0 (and any others)
```

For belt-and-suspenders: install into a clean Magento devbox
and confirm `composer require ejosterberg/module-opensalestax`
pulls from Packagist (not the path repo):

```bash
ssh magento-demo \
  'cd /tmp && rm -rf test-install && mkdir test-install && cd test-install &&
   composer require ejosterberg/module-opensalestax --dry-run'
# Output should show "Locking ejosterberg/module-opensalestax (v1.0.0)"
```

## Optional: submit to Magento Marketplace

Adobe Commerce Marketplace is at
<https://commercemarketplace.adobe.com/>. The submission flow:

1. Register a developer account at the marketplace
2. Submit the module zip (or composer package) for EQP
   (Extension Quality Program) review
3. Iterate on automated + manual review feedback
4. Listing goes live after approval

This typically takes weeks of back-and-forth. Treat it as a
v1.1 follow-up unless Eric specifically wants it in v1.0.

If deferred, document in `specs/handoff.md`:

```md
### Marketplace submission (v1.1 candidate)

- Submit `ejosterberg/module-opensalestax` to Adobe Commerce
  Marketplace at <https://commercemarketplace.adobe.com/>
- Iterate EQP feedback
- Pre-req: declaration in `composer.json` of
  `extra.magento.minimal_modules` and `extra.magento.required-modules`
- Expected timeline: 4-8 weeks
```

## Wrap-up tasks

Per START-HERE.md:

1. Update `specs/current-state.md`:
   - Move "v1.0.0 alpha plan" → "Shipped"
   - Record the GitHub release URL, tag, Packagist package URL,
     demo VM details
   - Set "Last update" date
2. Update `specs/handoff.md` with v1.1 candidates:
   - Whatever was deferred from v1.0 ("Marketplace submission",
     "Tax class → OST category mapping", "Per-state nexus
     filter", "MFTF suite", etc.)
   - Any merchant feedback received post-release
3. Archive the kickoff directory:
   ```bash
   git mv kickoff kickoff-archive
   git commit -s -m "chore: archive kickoff/ (v1.0 shipped)"
   git push
   ```
4. Final summary message back to Eric. Template:

> v1.0 shipped: `<release URL>`. CI is green on `main`. Demo
> deployment lives on `magento-demo` (VMID `<NNN>`, IP
> `<ip>`); $100 MN checkout returned `$<X>` tax via the module.
> SonarQube: 0 BLOCKER / 0 CRITICAL / security rating A.
> `<N>` tests passing. Packagist live at
> `https://packagist.org/packages/ejosterberg/module-opensalestax`.
> Next: v1.1 candidates queued in `specs/handoff.md` — top of
> the list is `<top deferred item>`.

## Acceptance for stage 07

Stage 07 is done when:

- [ ] `v1.0.0` tag exists on `origin/main`
- [ ] GitHub release `v1.0.0` published
- [ ] CHANGELOG promoted; new `[Unreleased]` opened
- [ ] Packagist package available (auto-published or registered)
- [ ] (Optional) Marketplace submission opened or deferral
  documented
- [ ] `specs/current-state.md` and `specs/handoff.md`
  reflect the shipped state
- [ ] `kickoff/` archived
- [ ] Summary message sent to Eric

Mark stage 07 complete in TodoWrite. **You are done.** The
kickoff plan has concluded.
