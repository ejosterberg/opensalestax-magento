# Magento 2 Tax Module — technical research

> Snapshot of Magento 2's tax extension surface as of 2026-05.
> Magento docs evolve; re-validate every URL and interface name
> before writing code against it.

## 1. What a Magento "module" is

A Magento 2 module is a PHP package that registers itself with
the framework via `registration.php` and an `etc/module.xml`
declaration. The module lives at `<vendor>/<module-name>/`
inside the Magento app's `app/code/` (for source installs) or
`vendor/<vendor>/<package>/` (for composer installs).

Our module:

- **Vendor namespace**: `EJOsterberg`
- **Module name**: `OpenSalesTax`
- **Module identifier**: `EJOsterberg_OpenSalesTax`
- **Composer package**: `ejosterberg/module-opensalestax`
- **Source path inside repo**: `EJOsterberg/OpenSalesTax/`

Composer installs route the package's contents into
`vendor/ejosterberg/module-opensalestax/`, where Magento's
autoloader picks up `registration.php`.

Docs: <https://developer.adobe.com/commerce/php/development/build/component-file-structure/>

## 2. Magento's tax architecture (where we hook in)

Magento's tax flow at checkout / order time:

1. **`Magento\Quote\Model\Quote\Address\Total\Tax`** — a totals
   collector. Iterates the quote's lines + shipping, asks for a
   rate per line, accumulates the per-line tax, writes it onto
   the quote totals.
2. **`Magento\Tax\Model\Calculation`** — the workhorse class.
   Method `getRate(\Magento\Framework\DataObject $request)`
   takes a request bag (with customer tax class, product tax
   class, destination country / region / postcode) and returns
   a percent rate.
3. **`Magento\Tax\Api\TaxCalculationInterface`** — the public
   service-contract interface. `calculateTax(...)` is the
   top-level entry point for tax computation; merchants and
   third-party modules normally swap THIS via `<preference>`.

We have two clean hook points:

| Pattern | Target | Notes |
|---|---|---|
| **A.** `<preference>` | `Magento\Tax\Api\TaxCalculationInterface` | Full control. Risk: other modules can't ALSO swap this without conflict. Cleaner API surface; harder to share the seat with another tax module. |
| **B.** `<plugin>` (`afterGetRate`) | `Magento\Tax\Model\Calculation::getRate` | Narrower seam. Multiple plugins on the same method are allowed and run in sortOrder. Plays nicely with the merchant's existing tax rules — we only override the RATE, not the entire flow. |

Decision deferred to `specs/decisions/001-tax-extension-point.md`
in stage 02 task 4. Initial bias: **B** (plugin on `getRate`),
because it's the lowest-conflict integration and Magento's docs
explicitly favor plugins over preferences when the seam is
narrow enough.

Plus, regardless of A/B, a **plugin on
`Magento\Quote\Model\Quote\Address\Total\Tax::collect`** is
required to populate per-line breakdown so the customer's
checkout summary + order detail screens show jurisdiction-level
tax. `getRate` alone returns a single percent; the totals
collector is where per-line dollar amounts get written.

Docs:
- <https://developer.adobe.com/commerce/php/development/components/plugins/>
- <https://developer.adobe.com/commerce/php/development/components/dependency-injection/>
- <https://developer.adobe.com/commerce/php/development/components/service-contracts/>

## 3. The DI wiring

`EJOsterberg/OpenSalesTax/etc/di.xml`:

```xml
<?xml version="1.0"?>
<!-- SPDX-License-Identifier: Apache-2.0 -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">

    <!-- Pattern B: plugin on rate calc -->
    <type name="Magento\Tax\Model\Calculation">
        <plugin name="ejosterberg_opensalestax_calculation"
                type="EJOsterberg\OpenSalesTax\Plugin\CalculationPlugin"
                sortOrder="10"/>
    </type>

    <!-- Pattern B (always): plugin on totals collector for per-line breakdown -->
    <type name="Magento\Quote\Model\Quote\Address\Total\Tax">
        <plugin name="ejosterberg_opensalestax_quote_totals_tax"
                type="EJOsterberg\OpenSalesTax\Plugin\QuoteTotalsTaxPlugin"
                sortOrder="10"/>
    </type>

</config>
```

If the ADR picks pattern A instead, swap the first `<type>` for:

```xml
<preference for="Magento\Tax\Api\TaxCalculationInterface"
            type="EJOsterberg\OpenSalesTax\Model\TaxCalculation"/>
```

## 4. The HTTP client — `\Magento\Framework\HTTP\Client\Curl`

Magento ships a native cURL wrapper with a clean injectable
interface. Sample:

```php
public function __construct(
    private \Magento\Framework\HTTP\Client\Curl $curl,
    private \Magento\Framework\Serialize\Serializer\Json $json
) {}

public function calculate(array $payload): array
{
    $this->curl->setHeaders(['Content-Type' => 'application/json']);
    $this->curl->post(
        $this->getApiUrl() . '/v1/calculate',
        $this->json->serialize($payload)
    );
    if ($this->curl->getStatus() !== 200) {
        throw new \RuntimeException('OST engine error: ' . $this->curl->getStatus());
    }
    return $this->json->unserialize($this->curl->getBody());
}
```

Don't pull in Guzzle (heavier; collides with the Magento-shipped
version pinned in vendor). The native `Curl` class is sufficient
for our two endpoints.

Docs: <https://developer.adobe.com/commerce/php/development/framework/http-client/>

## 5. Admin config storage

Magento stores admin settings in `core_config_data`, accessed
via `\Magento\Framework\App\Config\ScopeConfigInterface`.
Pattern:

- Define fields in `etc/adminhtml/system.xml` (admin form)
- Define defaults in `etc/config.xml`
- Define ACL resource in `etc/acl.xml`
- Read at runtime via:
  ```php
  $apiUrl = $this->scopeConfig->getValue(
      'osstax/general/api_url',
      \Magento\Store\Model\ScopeInterface::SCOPE_STORE
  );
  ```

Config paths follow the convention `<section>/<group>/<field>`:

| Path | Type | Purpose |
|---|---|---|
| `osstax/general/api_url` | text | OST engine base URL |
| `osstax/general/api_token` | obscure (encrypted) | Optional bearer token |
| `osstax/general/fail_hard` | yesno | Block checkout on engine failure (default off) |

### Encrypted fields

Use `Magento\Config\Model\Config\Backend\Encrypted` as the
`backend_model` in `system.xml`:

```xml
<field id="api_token" translate="label" type="obscure" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
    <label>API Token (optional)</label>
    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
</field>
```

Magento auto-encrypts on save and decrypts on read via
`Magento\Framework\Encryption\EncryptorInterface`.

Docs:
- <https://developer.adobe.com/commerce/php/development/components/configuration/>
- <https://developer.adobe.com/commerce/php/development/security/acl-rule/>

## 6. The admin page

Lands at **Stores → Configuration → Sales → Tax → OpenSalesTax**
(grouped under the existing Sales/Tax section so merchants find
it where they expect tax settings).

Settings UI is form-driven; no custom React / KnockoutJS
required. `system.xml` is declarative.

## 7. ACL — protecting the admin endpoint

`etc/acl.xml`:

```xml
<?xml version="1.0"?>
<!-- SPDX-License-Identifier: Apache-2.0 -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Acl/etc/acl.xsd">
    <acl>
        <resources>
            <resource id="Magento_Backend::admin">
                <resource id="Magento_Backend::stores">
                    <resource id="Magento_Backend::store">
                        <resource id="Magento_Config::config">
                            <resource id="Magento_Tax::config_tax">
                                <resource id="EJOsterberg_OpenSalesTax::config"
                                          title="OpenSalesTax Section"
                                          sortOrder="10"/>
                            </resource>
                        </resource>
                    </resource>
                </resource>
            </resource>
        </resources>
    </acl>
</config>
```

The corresponding `system.xml` `<section>` references this resource
via `<resource>EJOsterberg_OpenSalesTax::config</resource>` so
only admins with the permission see and edit the section.

## 8. The customer-facing checkout flow

When the customer adds items + a shipping address and proceeds:

1. Magento's quote subtotal collector runs first.
2. The totals-collector pipeline reaches
   `Magento\Quote\Model\Quote\Address\Total\Tax::collect`.
3. That collector loops the lines; for each, asks the tax
   calculator for the applicable rate via the chain that
   eventually hits `Magento\Tax\Model\Calculation::getRate`.
4. **Our plugin** (pattern B) intercepts `getRate`:
   - Checks the gate (USD currency + US country)
   - Calls OST `/v1/calculate` once per quote (caching the
     per-line response on the quote's extension attributes so
     we don't re-call for every line)
   - Returns the computed percent for the current line
5. **Our second plugin** intercepts the totals collector's
   `collect()` after-hook, reading the cached per-line response
   and writing per-jurisdiction breakdown into the collector's
   internal `_taxAmounts` / `_baseTaxAmounts` arrays so the
   summary screen surfaces it.

The cache key is `quote_id + address_hash` so we don't re-query
the engine on every cart refresh — Magento's totals pipeline
runs frequently (every add-to-cart, every line edit). Cache TTL
= the lifetime of the request (it's quote-extension-attribute
storage, not durable cache).

## 9. Test harness

Magento ships a PHPUnit base class for DI-aware unit tests:

```php
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class OstaxClientTest extends TestCase {
    private ObjectManager $objectManager;

    protected function setUp(): void {
        $this->objectManager = new ObjectManager($this);
    }

    public function testCalculateHappyPath(): void {
        $curlMock = $this->createMock(\Magento\Framework\HTTP\Client\Curl::class);
        $curlMock->method('getStatus')->willReturn(200);
        $curlMock->method('getBody')->willReturn(/* JSON fixture */);

        $client = $this->objectManager->getObject(
            \EJOsterberg\OpenSalesTax\Model\OstaxClient::class,
            ['curl' => $curlMock]
        );

        $result = $client->calculate(['lines' => []]);
        $this->assertArrayHasKey('tax_total', $result);
    }
}
```

Run with: `vendor/bin/phpunit`. Configured via `phpunit.xml.dist`
at the repo root.

For pure-PHP units (the HTTP client) `\PHPUnit\Framework\TestCase`
suffices. For DI-aware code (plugins, factories) use
`ObjectManager` to instantiate.

We do NOT run Magento's integration test framework (`dev/tests/integration`)
in CI — it requires a full Magento app context and is heavyweight.
Stage 05 demo deployment substitutes.

Docs:
- <https://developer.adobe.com/commerce/testing/guide/unit/>

## 10. Static analysis

Three tools, all run via composer scripts:

| Tool | Config | Purpose |
|---|---|---|
| **PHPUnit** | `phpunit.xml.dist` | Unit tests |
| **PHPStan** | `phpstan.neon` (extends `bitExpert/phpstan-magento`) | Type analysis, level 8 |
| **PHPCS** | `phpcs.xml` (Magento2 standard via `magento/magento-coding-standard`) | Coding-standards |

The `bitExpert/phpstan-magento` extension teaches PHPStan about
Magento's DI / factory patterns so it stops flagging legitimate
Magento code as broken.

Composer script:

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

`composer audit` is built into Composer 2.x and reads from
<https://packagist.org/security-advisories>.

## 11. Distribution

### Packagist

Repo registered at <https://packagist.org/packages/submit>. After
the initial submit, Packagist polls (or accepts a GitHub webhook
push) for new tags and auto-publishes them. Merchants install
with:

```bash
composer require ejosterberg/module-opensalestax
bin/magento module:enable EJOsterberg_OpenSalesTax
bin/magento setup:upgrade
bin/magento cache:clean
```

### Magento Marketplace (v1.1)

Adobe Commerce Marketplace at
<https://commercemarketplace.adobe.com/>. Submission requires:

- A "developer account" registered on Adobe Commerce Marketplace
- An EQP (Extension Quality Program) review pass — multiple
  rounds of automated + manual review
- A `magento/composer-root-update-plugin` compatibility
  declaration
- Marketplace-specific composer.json fields
  (`extra.magento.minimal_modules`, etc.)

Multi-week to multi-month process. Defer to v1.1 unless the user
specifically wants it in v1.0.

## 12. Open questions

- **PHPStan level**: target level 8 (strictest) but
  `bitExpert/phpstan-magento` sometimes still flags Magento DI
  patterns. May need a small `parameters.ignoreErrors` block —
  document each ignore in `phpstan.neon` comments.
- **HTTP client choice**: `Curl` (Magento-native) vs Guzzle
  (heavier but better timeout / retry primitives). Default to
  `Curl` for v0.1; revisit if we hit timeout issues.
- **Magento Marketplace submission**: deferred to v1.1. Their
  review cycle is weeks; not blocking the alpha.
- **MFTF tests**: deferred to v0.2. Heavyweight; stage 05's
  manual demo covers the happy path for v1.0.
- **Per-line OST response caching**: quote extension attribute
  vs a request-scoped registry. Either works; document the
  pick during step 5.
