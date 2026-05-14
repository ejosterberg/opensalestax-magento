# Stage 02 — Build v0.1.0 alpha

> 3-4 hours of focused work. Follow `specs/handoff.md`'s 9-step
> task list; this file adds quality and ordering guidance on top.

## Order of operations

The task list in `specs/handoff.md` is the authoritative
implementation guide. Execute its 9 steps in order:

1. Project bootstrap (top-level `composer.json`, `phpunit.xml.dist`,
   `phpstan.neon`, `phpcs.xml`, `.gitignore`)
2. OST HTTP client (`Model/OstaxClient.php`, port from Medusa)
3. Module registration (`registration.php`, `etc/module.xml`,
   module-level `composer.json`)
4. **ADR — pick tax extension pattern** (preference on
   `TaxCalculationInterface` vs plugin on `Calculation::getRate`).
   Write `specs/decisions/001-tax-extension-point.md`.
5. Tax calculation class (or plugin) per the ADR + `etc/di.xml`
6. Quote totals tax plugin (per-line breakdown)
7. Admin settings (`acl.xml`, `adminhtml/system.xml`, `config.xml`)
8. Tests (PHPUnit unit tests for client, tax calc, plugin)
9. Release (CHANGELOG entry, tag, push)

## TDD discipline

Per Eric's global rule: write the test, then the code. For each
class in steps 2 / 5 / 6:

1. Create `Test/Unit/<Subpath>/<Name>Test.php` with the expected
   behavior
2. Run `vendor/bin/phpunit` — it fails (no implementation yet)
3. Create `EJOsterberg/OpenSalesTax/<Subpath>/<Name>.php`
   minimally
4. Run `vendor/bin/phpunit` — it passes
5. Refactor; tests still pass

Skip TDD only for trivial wiring (e.g., `registration.php` and
the XML files in `etc/`).

## Set up CI before you start writing code

Add `.github/workflows/ci.yml` as one of the first commits:

```yaml
name: ci
on:
  push:
    branches: [main]
  pull_request:
jobs:
  ci:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.1"
          coverage: xdebug
          tools: composer:v2
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/phpunit --coverage-clover=coverage.xml
      - run: vendor/bin/phpstan analyse
      - run: vendor/bin/phpcs --standard=Magento2 EJOsterberg/
      - run: composer audit
```

Use `shivammathur/setup-php@v2` — it's the de-facto PHP setup
action and pre-installs `composer`. Pin PHP at 8.1 (the constitution
floor); add an 8.2 + 8.3 matrix job in stage 06 polish.

This gives you fast feedback on every push from the first
commit. Add a top-level `composer check` script that runs all
four locally:

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

## Commit cadence

One logical change per commit. Sign every commit (`git commit -s`).
No AI co-author trailers.

Suggested commit grain:

- `chore: project bootstrap (composer.json, phpunit, phpstan, phpcs)`
- `feat: add OST HTTP client (ported from Medusa)`
- `test: unit tests for OST HTTP client`
- `feat: module registration (registration.php + module.xml)`
- `docs: ADR 001 — tax extension via plugin on Calculation::getRate`
- `feat: CalculationPlugin (or TaxCalculation preference)`
- `test: CalculationPlugin tests`
- `feat: QuoteTotalsTaxPlugin for per-line breakdown`
- `test: QuoteTotalsTaxPlugin tests`
- `feat: admin config section (acl.xml, system.xml, config.xml)`
- `docs: README v0.1.0`
- `chore: tag v0.1.0`

## Things to NOT skip

- **SPDX headers** on every PHP/XML file:
  - PHP: `// SPDX-License-Identifier: Apache-2.0`
  - XML: `<!-- SPDX-License-Identifier: Apache-2.0 -->`
- **PHPDoc on public APIs** — every public method on
  `OstaxClient`, the tax calc class, the plugins. Future
  contributors (and SonarQube) read these.
- **No `mixed` return types** without an explicit comment
  justifying it.
- **No `@phpstan-ignore-line`** without a follow-up issue link.
- **Sensitive logging** — never log full quote payloads (contain
  customer PII / shipping addresses). Log structured:
  `['quote_id' => $id, 'line_count' => $n, 'api_status' => $s]`
  only.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **`final` on plugin classes** — Magento's coding standard
  discourages extension of plugins.

## Reference: the OST HTTP client to port

Source (TypeScript):
`C:/Users/ejosterberg/Documents/GITprojects/opensalestax-medusa/src/providers/opensalestax/client.ts`

It's ~130 lines, uses global `fetch`. Port to PHP:

- Replace `fetch` calls with
  `\Magento\Framework\HTTP\Client\Curl::post()` / `::get()`
- Replace JSON parsing with
  `\Magento\Framework\Serialize\Serializer\Json`
- Inject both via constructor
- Add a `healthCheck()` method that GETs `/v1/health` and
  returns `['ok' => bool, 'version' => string, 'db_connected' => bool, 'rtt_ms' => int]`
- SPDX header (Apache-2.0)
- PHPDoc with `@param` / `@return` on every public method

## Magento's PHPUnit base classes

For unit tests, use the lighter of these two depending on what's
under test:

| Test class | Use for |
|---|---|
| `\PHPUnit\Framework\TestCase` | Pure-PHP units (the HTTP client) |
| `\Magento\Framework\TestFramework\Unit\Helper\ObjectManager` | Classes that need Magento DI (plugins, factories) |

Example (`Test/Unit/Model/OstaxClientTest.php`):

```php
<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\HTTP\Client\Curl;
use EJOsterberg\OpenSalesTax\Model\OstaxClient;

final class OstaxClientTest extends TestCase
{
    public function testHealthCheckHappyPath(): void
    {
        // ... mock Curl, instantiate via ObjectManager, assert
    }
}
```

## Acceptance for stage 02

Stage 02 is done when:

- [ ] `vendor/bin/phpunit` passes locally; ≥10 tests
- [ ] `vendor/bin/phpstan analyse` passes at level 8 (with
  documented ignores if any)
- [ ] `vendor/bin/phpcs --standard=Magento2 EJOsterberg/` shows
  0 errors
- [ ] `composer audit` shows 0 advisories
- [ ] CI on the latest `main` commit is green
- [ ] `bin/magento module:status` in a clean Magento install
  shows `EJOsterberg_OpenSalesTax` as enabled after
  `setup:upgrade` (verified during stage 05 demo, but a smoke
  via `markshust/docker-magento` here is a strong signal)
- [ ] `git tag v0.1.0` is pushed; GitHub release v0.1.0 created
  via `gh release create`

Mark stage 02 complete in TodoWrite. Proceed to
`03-quality-gate.md`.
