# Live-Magento integration test (Mg-1)

This is the operator's reference for the integration test that closes the
gap exposed by the v1.3.0 -> v1.3.5 six-bug evening (May 2026). See
`CHANGELOG.md` for the full post-mortem.

## Status (v1.3.9)

**Armed.** The Mg-1 assertion (`$address->getTaxAmount() > 0`) now
runs against the real Magento Interceptor/DI/canonical-totals-write
path on every PR + push to main. `continue-on-error` is OFF — a
failure in this workflow fails CI for real.

The upstream Magento OSS 2.4.7-p3 DI bug
(`Cannot instantiate interface GetLatsLngsFromAddressInterface`)
that previously blocked `$quote->collectTotals()` is short-circuited
by a test-only Magento module living at
`tests/Integration/test-module/` (deployed into
`$MAGENTO_DIR/app/code/EJOsterberg/OstaxTestStubs/` by the workflow
before `composer install`). The module maps the broken interface to
a no-op stub class returning an empty lat/lng list — a legal contract
response per the interface PHPDoc, and loss-less for the OST plugin's
actual surface (we calculate tax on the cart, not allocate stock).

### Matrix scope

Current matrix: Magento 2.4.7-p3 / PHP 8.2 only. Magento 2.4.6-p10
remains parked behind its `sebastian/comparator` composer conflict
(`<=4.0.6` vs `phpunit ^9.5`'s `^4.0.10`). v1.4.x will add 2.4.6-p10
back via a parallel composer-override treatment — track at portfolio
improvement-queue Mg-1.

### When to remove the DI override

When either:

1. Adobe ships a Magento OSS patch that fixes the underlying DI
   resolution chain (2.4.7-p4 or later). Delete
   `tests/Integration/test-module/` + the workflow's "Install Mg-1.1
   test-only DI override module" step.
2. We migrate to a leaner integration harness off
   `dev/tests/integration` that doesn't trigger MSI source-selection
   at totals-collection time (improvement-queue Mg-1.2).

## What it tests

`tests/Integration/LiveMagentoTaxTest.php` boots a real Magento install
(via Magento's own `dev/tests/integration/` PHPUnit harness), creates an
MN cart fixture, calls `$quote->collectTotals()`, and asserts:

1. `$shippingAddress->getTaxAmount() > 0`
2. The tax amount matches the mock engine's response (~$9.025 on a $100 MN
   cart at the compound rate of 6.875% MN state + 0.15% Hennepin County +
   2.0% Minneapolis = 9.025%)
3. `$quote->getGrandTotal()` includes the tax (~$109.025)

That single `> 0` assertion would have caught Bugs C + D + E + F in one
shot at PR time.

## Why this beats unit tests

Unit tests instantiate plugins directly with mocks. They cannot reach:

- **DI-compiled `…\Interceptor` subclasses** — Bugs A, C, D
- **Magic-getter dispatch via `__call`** — `method_exists()` returns
  false on Interceptor magic getters; `is_callable()` returns true.
  Bugs E (in `beforeCollect`) and F#1 (in `afterCollect`).
- **The canonical totals-write sequence** — Magento's grand-total
  roll-up reads from `$total->getTaxAmount()` /
  `$total->getTotalAmount('tax')`, NOT from `applied_taxes`. Bug F#2.

## Architecture

```
GitHub Actions runner (ubuntu-latest)
├── Services:
│   ├── mysql:8.0 (port 3306)         ← Magento integration test DB
│   └── opensearch:2 (port 9200)      ← Magento search backend
├── Background process:
│   └── node tests/Integration/mock-engine/server.js (port 8080)
│       ↑ Mock OST engine. Returns 9.025% on /v1/calculate.
└── Foreground:
    ├── composer create-project from https://mirror.mage-os.org/
    │   ↑ Mage-OS mirror — no Adobe Marketplace credentials needed
    ├── composer require ejosterberg/module-opensalestax:@dev
    │   ↑ Via composer path repo pointing at $GITHUB_WORKSPACE
    └── vendor/bin/phpunit LiveMagentoTaxTest.php
        ↑ Magento's integration test harness boots ObjectManager,
          installs Magento into the sandbox DB, runs the test.
```

## Running locally

You need: Docker (for MySQL + OpenSearch), Node 24, PHP 8.2, composer.

```bash
cd /path/to/opensalestax-magento

# 1. Start the mock OST engine (terminal A — leave running)
node tests/Integration/mock-engine/server.js
# logs to stderr; ^C to stop

# 2. Start MySQL + OpenSearch (terminal B)
docker run -d --name mg-integration-mysql \
  -p 3306:3306 \
  -e MYSQL_ROOT_PASSWORD=rootpw \
  -e MYSQL_DATABASE=magento_integration_tests \
  mysql:8.0
docker run -d --name mg-integration-opensearch \
  -p 9200:9200 \
  -e "discovery.type=single-node" \
  -e "plugins.security.disabled=true" \
  -e "OPENSEARCH_INITIAL_ADMIN_PASSWORD=OstaxIntegrationTest_1!" \
  opensearchproject/opensearch:2
# Wait ~30s for both to be healthy:
docker exec mg-integration-mysql mysqladmin ping -h 127.0.0.1 -uroot -prootpw
curl -sf http://localhost:9200/_cluster/health
# Allow stored function creators in MySQL (Magento integration setup needs this):
docker exec mg-integration-mysql mysql -uroot -prootpw \
  -e "SET GLOBAL log_bin_trust_function_creators = 1;"

# 3. Create a Magento project (terminal C)
MAGENTO_DIR=/tmp/magento-integration-test
composer create-project \
  --repository-url=https://mirror.mage-os.org/ \
  "magento/project-community-edition=2.4.7-p3" \
  "$MAGENTO_DIR" \
  --no-install
mkdir -p "$MAGENTO_DIR/app/etc"
cd "$MAGENTO_DIR"

# 4. Allow Magento composer plugins
composer config --no-interaction allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-interaction allow-plugins.laminas/laminas-dependency-plugin true
composer config --no-interaction allow-plugins.magento/composer-dependency-version-audit-plugin true
composer config --no-interaction allow-plugins.magento/composer-root-update-plugin true
composer config --no-interaction allow-plugins.magento/magento-composer-installer true
composer config --no-interaction allow-plugins.magento/inventory-composer-installer true

# 5. Register the module via path repo
composer config repositories.opensalestax-local path /path/to/opensalestax-magento
composer require "ejosterberg/module-opensalestax:@dev" --no-update
composer install

# 6. Configure Magento's integration test harness
cd dev/tests/integration
cp etc/install-config-mysql.php.dist etc/install-config-mysql.php
sed -i "s|'db-host' => 'localhost'|'db-host' => '127.0.0.1'|" etc/install-config-mysql.php
sed -i "s|'db-password' => '123123q'|'db-password' => 'rootpw'|" etc/install-config-mysql.php
sed -i "s|'search-engine' => 'elasticsearch7'|'search-engine' => 'opensearch'|" etc/install-config-mysql.php
sed -i "s|'opensearch-host' => 'localhost'|'opensearch-host' => '127.0.0.1'|" etc/install-config-mysql.php

# 7. Drop the test into the vendor module path
VENDOR_DIR="$MAGENTO_DIR/vendor/ejosterberg/module-opensalestax/EJOsterberg/OpenSalesTax/Test/Integration"
mkdir -p "$VENDOR_DIR"
cp /path/to/opensalestax-magento/tests/Integration/LiveMagentoTaxTest.php \
  "$VENDOR_DIR/LiveMagentoTaxTest.php"

# 8. Run it
cd "$MAGENTO_DIR/dev/tests/integration"
../../../vendor/bin/phpunit \
  --bootstrap framework/bootstrap.php \
  --configuration phpunit.xml.dist \
  ../../../vendor/ejosterberg/module-opensalestax/EJOsterberg/OpenSalesTax/Test/Integration/LiveMagentoTaxTest.php

# 9. Cleanup
docker rm -f mg-integration-mysql mg-integration-opensearch
rm -rf "$MAGENTO_DIR"
```

The local run takes 5-10 minutes the first time (Magento install is
slow). Subsequent runs reuse `$HOME/.composer/cache` and are faster.

## Mock engine

`tests/Integration/mock-engine/server.js` is a 70-line Node script. No
dependencies. Implements:

- `GET  /v1/health`    — returns `{"status":"ok", "version":"mock-1.0", "database_connected":true}`
- `POST /v1/calculate` — returns a 9.025% MN compound-rate response over
  the request payload's `line_items`. Per-jurisdiction breakdown matches
  what the real OST engine v0.58+ emits for the fixture address
  (Minneapolis 55401).

To run the mock standalone for ad-hoc curl probing:

```bash
node tests/Integration/mock-engine/server.js &
curl -s http://127.0.0.1:8080/v1/health
curl -s -X POST http://127.0.0.1:8080/v1/calculate \
  -H "Content-Type: application/json" \
  -d '{"address":{"zip5":"55401"},"line_items":[{"amount":"100.00","category":"general"}]}'
# {"subtotal":"100.00","tax_total":"9.0250","lines":[...],"disclaimer":"..."}
```

## Fixture

`tests/Integration/fixtures/minnesota-cart.json` is the canonical source
of truth for the test cart shape. The test code builds the actual Quote
from these values via Magento's ObjectManager — the JSON file exists so
future scenarios are obvious to add.

## Adding a new test scenario

To add a second scenario (e.g., a $500 NY cart, a shipping-tax case, a
multi-line cart, a non-USD currency that should fall back to Magento's
built-in tax tables):

1. Add the fixture data to `fixtures/<name>.json`
2. Add a second test method to `LiveMagentoTaxTest.php` that builds the
   Quote from the new fixture
3. If the new scenario expects a different mock-engine response shape,
   teach `mock-engine/server.js` to branch on the request's `zip5` or
   `line_items[].amount`

Don't add additional Magento versions to the CI matrix until v1.4.x —
the captain wants wall-clock under control until the baseline is
reliably green. See the CHANGELOG entry for v1.3.6.

## CI run time

Target: < 15 minutes per PR. Observed budget (rough):

| Step                                                | Time |
|-----------------------------------------------------|------|
| actions/checkout, setup-node, setup-php             | ~30s |
| Mock engine background start + health probe         | ~2s  |
| composer create-project (Mage-OS mirror)            | ~2-3min |
| composer install (Magento + module)                 | ~2-3min |
| Magento integration sandbox install (MySQL + ES)    | ~3-4min |
| LiveMagentoTaxTest itself                           | ~30s |
| Teardown                                            | ~5s  |
| **Total**                                           | **~8-11 min** |

Cached composer cuts ~2 min on repeat runs. The hard ceiling in the
workflow is 25 minutes (`timeout-minutes: 25`).

## Troubleshooting

**"Mock OST engine unreachable"** — The background `node server.js`
either crashed or never started. Check the "Dump mock engine log on
failure" step output. Common cause: port 8080 already bound (unlikely
on a clean runner, common locally — change `PORT` env var).

**"Bug C/D/E/F regression: $shippingAddress->getTaxAmount() is zero"** —
This is what the test is for. Read the assertion failure message and the
CHANGELOG entries for v1.3.0-v1.3.5 to understand what likely broke.
Recent suspect changes: anything in `Plugin/QuoteTotalsTaxPlugin.php`,
`etc/di.xml`, or the call sites that use `is_callable()` vs.
`method_exists()` on Magento Interceptor objects.

**Magento setup fails with "Unknown column ... search_engine"** —
OpenSearch service not healthy. Check
`opensearchproject/opensearch:2` started cleanly. The healthcheck in
the workflow waits up to 200s.

**Composer "Could not authenticate against repo.magento.com"** — You
shouldn't see this; we use the Mage-OS mirror. If you do, check
`composer.json` in the Magento project root — `composer create-project`
might have written a repo entry pointing at repo.magento.com. Remove it.
