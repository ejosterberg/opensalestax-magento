# Stage 03 — Quality gate

> ~30 minutes. Verify the v0.1.0 alpha meets baseline quality
> before security review or deployment. This stage is a gate:
> nothing past this point starts until every check below is
> green on `main`.

## The four checks

Run each in order. Stop and fix on the first failure; don't
batch failures.

### 1. Tests

```bash
cd C:/Users/ejosterberg/Documents/GITprojects/opensalestax-magento
vendor/bin/phpunit --coverage-clover=coverage.xml --coverage-html=coverage-html
```

Expect:

- 0 failures
- ≥10 tests
- Line coverage ≥80% overall
- Line coverage ≥85% for `EJOsterberg/OpenSalesTax/Model/` and
  `EJOsterberg/OpenSalesTax/Plugin/`

If coverage is short on a critical class, add tests — don't
lower the threshold.

### 2. Static analysis (PHPStan)

```bash
vendor/bin/phpstan analyse
```

`phpstan.neon` should extend `bitExpert/phpstan-magento` and run
at level 8 (strictest). Expect 0 errors.

If PHPStan flags Magento-DI patterns it doesn't understand:

- Document the ignore inline in `phpstan.neon` with a `# `
  comment explaining why
- Never blanket-disable a rule
- Never use `@phpstan-ignore-line` without a follow-up issue
  link

### 3. Coding standard (PHPCS)

```bash
vendor/bin/phpcs --standard=Magento2 EJOsterberg/
```

The `magento/magento-coding-standard` package ships the
`Magento2` ruleset (PSR-2/PSR-12 + Magento conventions: short
arrays, strict types, final on plugin classes, etc.). Expect
0 errors.

If a warning is a legitimate false positive: add a
`// phpcs:disable Magento2.<Rule> -- <reason>` /
`// phpcs:enable` block with a one-line justification. Scope
disables as narrowly as possible.

### 4. Dependency audit

```bash
composer audit
```

Expect "No security vulnerability advisories found." If
high/critical CVEs surface:

- Bump the affected package to a patched version
- If no patch exists yet: pin to a safe range with `replace`
  in `composer.json`, and open a GitHub issue tracking the
  upstream fix
- Don't lower the audit threshold to make it pass

## Aggregate command

The `composer check` script (added during stage 02 task 1) runs
all four:

```json
{
  "scripts": {
    "check": [
      "vendor/bin/phpunit",
      "vendor/bin/phpstan analyse",
      "vendor/bin/phpcs --standard=Magento2 EJOsterberg/",
      "@composer audit"
    ]
  }
}
```

The user (and CI, and `04-security-review.md`) all rely on
`composer check` being the single command that gates merges.

## CI must agree

The CI workflow (`.github/workflows/ci.yml`, added in stage 02)
runs the same four checks. After `composer check` is green
locally, push to `main` (or a topic branch) and confirm GitHub
Actions reports green within ~3 minutes.

If local passes but CI fails:

- Likely a `composer.lock` vs vendor drift — make sure
  `composer.lock` is committed
- Or a PHP version mismatch — CI runs 8.1; if your local is
  8.2.4 (XAMPP), some syntax/feature might silently differ
- Or a path-case mismatch (Windows vs Linux); search for
  namespaced classes with wrong casing

## Manual smoke test

In addition to the automated gate, run a quick manual smoke
before moving on. The lightest-weight option is to load the
module into a `markshust/docker-magento` devbox locally (see
stage 05 for the full procedure) and verify the module enables
cleanly:

```bash
# In the devbox (whatever name you give it locally):
bin/magento module:enable EJOsterberg_OpenSalesTax
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean

# Verify module is loaded
bin/magento module:status | grep OpenSalesTax
# Expected: "EJOsterberg_OpenSalesTax" listed under "List of enabled modules"
```

`setup:di:compile` is the strongest signal — if your `di.xml` is
broken or your plugin classes can't be reflected, compilation
fails with a useful error.

Stop the devbox before continuing (or leave it running into
stage 05).

## Acceptance for stage 03

Stage 03 is done when:

- [ ] `composer check` passes locally
- [ ] Latest GitHub Actions run on `main` is green
- [ ] `bin/magento module:enable` + `setup:upgrade` +
  `setup:di:compile` complete without errors against a clean
  Magento 2.4.6+ devbox
- [ ] Coverage thresholds met (≥80% lines overall)
- [ ] `composer.json` has `check` script

Mark stage 03 complete in TodoWrite. Proceed to
`04-security-review.md`.
