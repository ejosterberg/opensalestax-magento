<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

// Namespace matches the file's destination inside Magento's integration
// testsuite directory (`dev/tests/integration/testsuite/EJOsterberg/OpenSalesTax/`)
// so the SuiteLoader's PSR-4-style discovery finds it. The CI workflow
// copies this file into that location at run time â€” see
// `.github/workflows/integration-magento.yml`.
namespace EJOsterberg\OpenSalesTax;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Mg-1: live-Magento integration test for the OpenSalesTax module.
 *
 * Closes the gap that allowed the v1.3.0 â†’ v1.3.4 six-bug chain in
 * May 2026 to pass unit-test CI while shipping silently broken cart
 * totals. See CHANGELOG.md (v1.3.0â€“v1.3.5 entries) for the full
 * post-mortem.
 *
 * What this test exercises that unit tests structurally cannot:
 *
 *  1. **Real DI compilation** â€” boots Magento's ObjectManager, which
 *     compiles `â€¦\Interceptor` subclasses over `Quote` / `Address` /
 *     `Total` etc. (Bugs A, C, D â€” all silent at unit-test time.)
 *
 *  2. **The canonical `Tax::collect()` code path** â€”
 *     `$quote->collectTotals()` â†’ `CollectTotalsObserver` â†’
 *     `Magento\Tax\Model\Sales\Total\Quote\Tax::collect()` â†’ our
 *     `QuoteTotalsTaxPlugin::beforeCollect` / `afterCollect`.
 *     Bugs C + D + E + F all manifest only here.
 *
 *  3. **Magic-getter dispatch** â€” `$quote->getQuoteCurrencyCode()`,
 *     `$item->getRowTotal()`, etc. are routed through `__call` on
 *     the Interceptor. `method_exists()` returns false on these;
 *     `is_callable()` returns true. (Bug E in `beforeCollect`,
 *     Bug F#1 in `afterCollect`.)
 *
 *  4. **The canonical totals-write sequence** â€” Magento's
 *     grand-total roll-up reads from `$total->getTaxAmount()` /
 *     `$total->getTotalAmount('tax')`, not from `applied_taxes`.
 *     `afterCollect` must call `setTaxAmount` / `setBaseTaxAmount` /
 *     `setTotalAmount('tax', X)` / `setBaseTotalAmount('tax', X)`
 *     or the cart shows a $0 tax even when the engine returned a
 *     correct value. (Bug F#2.)
 *
 * The assertion `$shippingAddress->getTaxAmount() > 0` IS the
 * single-line check that would have caught all six bugs at PR time.
 *
 * Mock engine: a Node.js HTTP server started by the CI workflow on
 * `127.0.0.1:8080`. Returns an MN compound-rate response (9.025% on
 * a $100 cart â†’ $9.025 tax). See `mock-engine/server.js` and
 * `fixtures/minnesota-cart.json`.
 */
class LiveMagentoTaxTest extends TestCase
{
    /**
     * @var ObjectManagerInterface|null
     *
     * Untyped property by intent — Magento's test framework does
     * reflection-based property handling in some annotation paths,
     * which can raise a `Cannot assign null to property of type X`
     * TypeError when the property is strictly typed and non-nullable.
     * Untyped + PHPDoc matches the convention used by every test
     * class under Magento's own integration test directories
     * (see vendor/magento/inventory/<module>/Test/Integration/).
     */
    private $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = Bootstrap::getObjectManager();
        if (!$objectManager instanceof ObjectManagerInterface) {
            self::fail(
                'Magento\TestFramework\Helper\Bootstrap::getObjectManager() returned null. '
                . 'This usually means the test was loaded via a PHPUnit path argument instead '
                . 'of via the Magento testsuite (which initializes the ObjectManager per test). '
                . 'Run via `--testsuite "Magento Integration Tests Real Suite" --filter LiveMagentoTaxTest`.'
            );
        }
        $this->objectManager = $objectManager;
        $this->configureOpenSalesTaxModule();
    }

    /**
     * Configure the module to point at the mock engine via runtime config
     * overrides. Uses MutableScopeConfigInterface to avoid touching
     * core_config_data (which would require cache flushes and integration
     * test sandbox magic).
     *
     * Mg-1.2 (v1.3.11): writes at BOTH SCOPE_TYPE_DEFAULT (the convention
     * vendor/magento/module-X/Test/Integration uses) AND SCOPE_STORE
     * 'default' (the scope the merchant's admin save lands at). Earlier
     * the test wrote ONLY at SCOPE_STORE 'default' — when the integration
     * test framework's current-store resolution didn't match store code
     * 'default' the plugin's `getValue(SCOPE_STORE, null)` read fell
     * through to SCOPE_TYPE_DEFAULT, which was empty, and `isConfigured()`
     * returned false → the `beforeCollect` gate short-circuited silently
     * → engine never got hit → tax stayed at 0. CI evidence: mock engine
     * stderr captured the `listen` event but never any `/v1/calculate`
     * request.
     */
    private function configureOpenSalesTaxModule(): void
    {
        /** @var MutableScopeConfigInterface $config */
        $config = $this->objectManager->get(MutableScopeConfigInterface::class);
        $engineUrl = getenv('OSTAX_TEST_ENGINE_URL') ?: 'http://127.0.0.1:8080';

        // Default-scope writes (the convention Magento's own integration
        // tests use; survives any store-resolution quirk in the test
        // harness because SCOPE_STORE reads fall through to default).
        $config->setValue('osstax/general/api_url', $engineUrl, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $config->setValue('osstax/general/fail_hard', '0', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $config->setValue('osstax/general/restrict_to_public_ips', '0', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        // Belt-and-braces store-scope writes (so a store-scoped read with
        // an explicit code 'default' also resolves; matches what the
        // merchant admin save produces).
        $config->setValue('osstax/general/api_url', $engineUrl, ScopeInterface::SCOPE_STORE, 'default');
        $config->setValue('osstax/general/fail_hard', '0', ScopeInterface::SCOPE_STORE, 'default');
        $config->setValue('osstax/general/restrict_to_public_ips', '0', ScopeInterface::SCOPE_STORE, 'default');
    }

    /**
     * The Mg-1 smoke test. Builds a $100 MN cart, calls collectTotals(),
     * asserts tax is non-zero AND matches the mock engine's response.
     *
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testCollectTotalsAppliesEngineTaxOnMinnesotaCart(): void
    {
        // Sanity probe â€” fail fast with a clear message if the mock engine
        // isn't reachable, so a misconfigured workflow doesn't masquerade
        // as a module bug.
        $this->assertMockEngineReachable();

        $product = $this->createFixtureProduct();
        $quote = $this->createMinnesotaQuote($product);

        // Mg-1.2 diagnostic: dump the state the plugin sees right before
        // collectTotals(). If the assertion fails downstream the workflow
        // logs show whether the engine URL was visible to the plugin AND
        // whether the OST module is enabled. Cheap to keep — turns silent
        // "engine never got hit" failures into one-look diagnoses.
        $this->dumpDiagnostics($quote);

        // THE call. Drives the whole totals pipeline including our plugin.
        $quote->collectTotals();

        // For virtual products Magento applies the tax to the billing
        // address (no shipping address for items with no weight). The
        // same canonical-totals-write path runs for either - billing
        // is just where the values land for virtual carts.
        $taxAddress = $quote->getBillingAddress();

        // THE assertion. Single line. Would have caught Bugs C + D + E + F
        // in one shot at PR time.
        $this->assertGreaterThan(
            0.0,
            (float)$taxAddress->getTaxAmount(),
            'Bug C/D/E/F regression: $address->getTaxAmount() is zero. '
            . 'The plugin failed to drive the engine response into Magento\'s '
            . 'canonical totals fields. Check the v1.3.0-v1.3.5 CHANGELOG for '
            . 'the six-bug post-mortem.'
        );

        // Tight bound: the mock returns 9.025% of $100 = $9.025.
        // Allow +/-$0.01 for Magento's internal rounding.
        $this->assertEqualsWithDelta(
            9.025,
            (float)$taxAddress->getTaxAmount(),
            0.01,
            'Tax amount diverged from mock engine response. Engine returned '
            . '9.025% on $100; cart should show ~$9.025 tax.'
        );

        // Grand total balances ($100 product + $9.025 tax = $109.025).
        $this->assertEqualsWithDelta(
            109.025,
            (float)$quote->getGrandTotal(),
            0.01,
            'Grand total did not include the engine-computed tax.'
        );
    }

    /**
     * Mg-1.2 (v1.3.11): emit a structured one-shot diagnostic block to
     * STDERR right before `$quote->collectTotals()`. The block answers
     * the questions that the v1.3.10 silent-zero-tax failure couldn't:
     *
     *  1. Is the OST module actually enabled in the test instance?
     *  2. What does `ScopeConfigInterface::getValue` return for the engine
     *     URL — at SCOPE_DEFAULT *and* at the cart's actual store?
     *  3. What is the test cart's quote currency / address country / ZIP?
     *
     * Output goes to STDERR so it survives PHPUnit's stdout buffering and
     * appears in the GitHub Actions log next to the PHPUnit FAILURE line.
     */
    private function dumpDiagnostics(Quote $quote): void
    {
        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);

        $store = $storeManager->getStore();
        $currentStoreCode = $store->getCode();
        $currentStoreId   = (int)$store->getId();

        $apiUrlDefault = $scopeConfig->getValue('osstax/general/api_url', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $apiUrlStore   = $scopeConfig->getValue('osstax/general/api_url', ScopeInterface::SCOPE_STORE, $currentStoreCode);
        $apiUrlNullSc  = $scopeConfig->getValue('osstax/general/api_url', ScopeInterface::SCOPE_STORE);

        // Module enabled? The integration framework's deploymentConfig
        // tracks this in app/etc/config.php — read it via the runtime
        // ModuleList interface so we don't shell out to `bin/magento`.
        $moduleListInterface = 'Magento\\Framework\\Module\\ModuleListInterface';
        $isOstModuleOn = false;
        $isStubModuleOn = false;
        if (interface_exists($moduleListInterface)) {
            /** @var \Magento\Framework\Module\ModuleListInterface $moduleList */
            $moduleList = $this->objectManager->get($moduleListInterface);
            $isOstModuleOn  = $moduleList->has('EJOsterberg_OpenSalesTax');
            $isStubModuleOn = $moduleList->has('EJOsterberg_OstaxTestStubs');
        }

        $billing = $quote->getBillingAddress();
        $shipping = $quote->getShippingAddress();

        // Did the DI compiler actually wire our plugin onto the totals
        // collector? Magento generates Interceptor subclasses at runtime
        // (in dev mode) — if the target class isn't loadable as an
        // Interceptor, or the plugin reference isn't in
        // \Magento\Framework\Interception\PluginListInterface for that
        // target, then beforeCollect never fires regardless of any gate.
        $pluginListInterface = 'Magento\\Framework\\Interception\\PluginListInterface';
        $totalsTargetClass   = 'Magento\\Tax\\Model\\Sales\\Total\\Quote\\Tax';
        $totalsInterceptor   = $totalsTargetClass . '\\Interceptor';
        $interceptorLoadable = class_exists($totalsInterceptor);
        $pluginsForTarget    = [];
        $pluginListClass     = null;
        $pluginsRawSample    = null;
        if (interface_exists($pluginListInterface)) {
            /** @var \Magento\Framework\Interception\PluginListInterface $plugins */
            $plugins = $this->objectManager->get($pluginListInterface);
            $pluginListClass = get_class($plugins);
            try {
                // Walk PluginList internals via reflection. The structure
                // varies by Magento version; look for any property whose
                // serialized JSON contains "EJOsterberg" (our vendor).
                $reflect = new \ReflectionClass($plugins);
                while ($reflect !== false) {
                    foreach ($reflect->getProperties() as $prop) {
                        $prop->setAccessible(true);
                        $value = $prop->getValue($plugins);
                        if (is_array($value)) {
                            $json = json_encode($value);
                            if (is_string($json) && stripos($json, 'EJOsterberg') !== false) {
                                // Trim to a manageable chunk for the log.
                                $pluginsRawSample = substr($json, 0, 1500);
                                $pluginsForTarget[$prop->getName()] = true;
                            }
                        }
                    }
                    $reflect = $reflect->getParentClass();
                }
                // Direct probe: ask the PluginList what plugins fire for
                // our exact target+method. PluginListInterface::getNext
                // returns the chain head; reflection walk above is the
                // belt-and-braces for older Magento versions.
                if (method_exists($plugins, 'getNext')) {
                    $next = $plugins->getNext($totalsTargetClass, 'collect');
                    $pluginsForTarget['__getNext_collect__'] = $next === null ? null : (array)$next;
                }
            } catch (\Throwable $e) {
                $pluginsForTarget = ['__error__' => $e->getMessage()];
            }
        }

        // Confirm the actual concrete class Magento resolved for the
        // totals target — should be a *\Interceptor subclass once
        // plugins are wired. If it's the bare class, no plugin runs.
        $resolvedTotals = $this->objectManager->get($totalsTargetClass);

        $diag = [
            'evt'                 => 'mg1_diag',
            'module_ost_enabled'  => $isOstModuleOn,
            'module_stub_enabled' => $isStubModuleOn,
            'store_code'          => $currentStoreCode,
            'store_id'            => $currentStoreId,
            'api_url_at_default'  => $apiUrlDefault === null ? null : (string)$apiUrlDefault,
            'api_url_at_store'    => $apiUrlStore === null ? null : (string)$apiUrlStore,
            'api_url_at_store_null_code' => $apiUrlNullSc === null ? null : (string)$apiUrlNullSc,
            'quote_currency'      => (string)$quote->getQuoteCurrencyCode(),
            'quote_active'        => (bool)$quote->getIsActive(),
            'quote_items_count'   => is_array($quote->getAllItems()) ? count($quote->getAllItems()) : 0,
            'billing_country'     => $billing ? (string)$billing->getCountryId() : null,
            'billing_postcode'    => $billing ? (string)$billing->getPostcode() : null,
            'shipping_country'    => $shipping ? (string)$shipping->getCountryId() : null,
            'shipping_postcode'   => $shipping ? (string)$shipping->getPostcode() : null,
            'totals_interceptor_loadable' => $interceptorLoadable,
            'totals_resolved_class'       => get_class($resolvedTotals),
            'plugin_list_class'           => $pluginListClass,
            'ost_plugins_registered'      => $pluginsForTarget,
            'plugins_raw_sample'          => $pluginsRawSample,
        ];
        fwrite(STDERR, "\n[MG-1-DIAG] " . json_encode($diag) . "\n");
    }

    private function assertMockEngineReachable(): void
    {
        $engineUrl = getenv('OSTAX_TEST_ENGINE_URL') ?: 'http://127.0.0.1:8080';
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $body = @file_get_contents($engineUrl . '/v1/health', false, $ctx);
        $this->assertNotFalse(
            $body,
            sprintf('Mock OST engine unreachable at %s/v1/health. '
                . 'Did the workflow start the mock-engine background process?', $engineUrl)
        );
        $decoded = json_decode((string)$body, true);
        $this->assertIsArray($decoded, 'Mock OST engine returned non-JSON health response.');
        $this->assertSame('ok', $decoded['status'] ?? null, 'Mock OST engine health status != "ok".');
    }

    private function createFixtureProduct(): Product
    {
        // Use a VIRTUAL product (Magento type 'virtual') rather than
        // 'simple'. Virtual products skip the multi-source-inventory
        // check (no stock_source resolution) and skip shipping-rate
        // collection (no carrier DI). This is a deliberate choice to
        // keep the test focused on the OST plugin's actual surface,
        // avoiding unrelated DI activity that doesn't affect tax math.
        //
        // Tax calculation behavior is product-type-agnostic in our
        // plugin: QuoteTotalsTaxPlugin reads $item->getRowTotal() and
        // writes the canonical Tax::collect() output fields the same
        // way for either type. The bug surface we're guarding (Bugs
        // C+D+E+F) lives entirely in beforeCollect/afterCollect,
        // which fire for virtual products the same as simple.
        //
        // For virtual products Magento applies the tax to the billing
        // address (not shipping), so the assertion below reads from
        // $quote->getBillingAddress()->getTaxAmount().
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->setTypeId('virtual')
            ->setAttributeSetId(4)
            ->setName('OST Mg-1 Fixture Product')
            ->setSku('ostax-mn-fixture-' . uniqid())
            ->setPrice(100.00)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setTaxClassId(2) // Taxable Goods (Magento default tax class for products)
            ->setWebsiteIds([1])
            ->setStockData([
                'qty' => 100,
                'is_in_stock' => 1,
                'manage_stock' => 0,
                'use_config_manage_stock' => 0,
            ]);

        /** @var ProductRepositoryInterface $repo */
        $repo = $this->objectManager->get(ProductRepositoryInterface::class);
        return $repo->save($product);
    }

    /**
     * Build a Quote with the MN address and the fixture product, persisted
     * to the integration-test sandbox DB so collectTotals() runs against
     * the same shape Magento sees in production.
     *
     * Uses ObjectManager::create(Quote::class) + manual ->save() rather
     * than CartManagementInterface::createEmptyCart() because the latter
     * requires session/customer context that the integration test sandbox
     * doesn't have by default. The lower-level pattern is what every
     * Magento integration test under vendor/magento/inventory/.../
     * SalesQuoteItem/AddSalesQuoteItem*Test.php uses for the same reason.
     */
    private function createMinnesotaQuote(Product $product): Quote
    {
        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $store = $storeManager->getStore();

        /** @var CartRepositoryInterface $cartRepository */
        $cartRepository = $this->objectManager->get(CartRepositoryInterface::class);

        /** @var Quote $quote */
        $quote = $this->objectManager->create(Quote::class);
        $quote->setStore($store);
        $quote->setQuoteCurrencyCode('USD');
        $quote->setBaseCurrencyCode('USD');
        $quote->setStoreCurrencyCode('USD');
        $quote->setIsActive(true);
        $quote->setIsMultiShipping(false);
        // Use Customer guest checkout - matches the common Magento checkout
        // path that the six-bug evening surfaced bugs on.
        $quote->setCustomerEmail('mg1-fixture@example.com');
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerFirstname('Test');
        $quote->setCustomerLastname('Customer');

        $quote->addProduct($product, 1);

        $addressData = [
            'firstname'  => 'Test',
            'lastname'   => 'Customer',
            'street'     => '100 N 6th St',
            'city'       => 'Minneapolis',
            'region'     => 'Minnesota',
            'region_id'  => 35, // MN
            'postcode'   => '55401',
            'country_id' => 'US',
            'telephone'  => '612-555-0100',
        ];

        /** @var Address $shippingAddress */
        $shippingAddress = $this->objectManager->create(Address::class)
            ->setData($addressData)
            ->setAddressType(Address::TYPE_SHIPPING);

        /** @var Address $billingAddress */
        $billingAddress = $this->objectManager->create(Address::class)
            ->setData($addressData)
            ->setAddressType(Address::TYPE_BILLING);

        $quote->setShippingAddress($shippingAddress);
        $quote->setBillingAddress($billingAddress);
        // Virtual products skip shipping-rate collection; don't ask
        // for it (avoids spinning up carrier DI surfaces unrelated to
        // tax math). The plugin still fires - the ShippingAssignment
        // generated by Magento's totals collector wraps the virtual
        // item's billing address as its "shipping" address for the
        // canonical Tax::collect() interface.

        $cartRepository->save($quote);

        return $quote;
    }
}
