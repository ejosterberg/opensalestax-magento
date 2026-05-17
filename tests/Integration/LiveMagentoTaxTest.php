<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
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
 * Closes the gap that allowed the v1.3.0 → v1.3.4 six-bug chain in
 * May 2026 to pass unit-test CI while shipping silently broken cart
 * totals. See CHANGELOG.md (v1.3.0–v1.3.5 entries) for the full
 * post-mortem.
 *
 * What this test exercises that unit tests structurally cannot:
 *
 *  1. **Real DI compilation** — boots Magento's ObjectManager, which
 *     compiles `…\Interceptor` subclasses over `Quote` / `Address` /
 *     `Total` etc. (Bugs A, C, D — all silent at unit-test time.)
 *
 *  2. **The canonical `Tax::collect()` code path** —
 *     `$quote->collectTotals()` → `CollectTotalsObserver` →
 *     `Magento\Tax\Model\Sales\Total\Quote\Tax::collect()` → our
 *     `QuoteTotalsTaxPlugin::beforeCollect` / `afterCollect`.
 *     Bugs C + D + E + F all manifest only here.
 *
 *  3. **Magic-getter dispatch** — `$quote->getQuoteCurrencyCode()`,
 *     `$item->getRowTotal()`, etc. are routed through `__call` on
 *     the Interceptor. `method_exists()` returns false on these;
 *     `is_callable()` returns true. (Bug E in `beforeCollect`,
 *     Bug F#1 in `afterCollect`.)
 *
 *  4. **The canonical totals-write sequence** — Magento's
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
 * a $100 cart → $9.025 tax). See `mock-engine/server.js` and
 * `fixtures/minnesota-cart.json`.
 */
class LiveMagentoTaxTest extends TestCase
{
    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->configureOpenSalesTaxModule();
    }

    /**
     * Configure the module to point at the mock engine via runtime config
     * overrides. Uses MutableScopeConfigInterface to avoid touching
     * core_config_data (which would require cache flushes and integration
     * test sandbox magic).
     */
    private function configureOpenSalesTaxModule(): void
    {
        /** @var MutableScopeConfigInterface $config */
        $config = $this->objectManager->get(MutableScopeConfigInterface::class);
        $engineUrl = getenv('OSTAX_TEST_ENGINE_URL') ?: 'http://127.0.0.1:8080';
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
        // Sanity probe — fail fast with a clear message if the mock engine
        // isn't reachable, so a misconfigured workflow doesn't masquerade
        // as a module bug.
        $this->assertMockEngineReachable();

        $product = $this->createFixtureProduct();
        $quote = $this->createMinnesotaQuote($product);

        // THE call. Drives the whole totals pipeline including our plugin.
        $quote->collectTotals();

        $shippingAddress = $quote->getShippingAddress();

        // THE assertion. Single line. Would have caught Bugs C + D + E + F
        // in one shot at PR time.
        $this->assertGreaterThan(
            0.0,
            (float)$shippingAddress->getTaxAmount(),
            'Bug C/D/E/F regression: $shippingAddress->getTaxAmount() is zero. '
            . 'The plugin failed to drive the engine response into Magento\'s '
            . 'canonical totals fields. Check the v1.3.0-v1.3.5 CHANGELOG for '
            . 'the six-bug post-mortem.'
        );

        // Tight bound: the mock returns 9.025% of $100 = $9.025.
        // Allow ±$0.01 for Magento's internal rounding.
        $this->assertEqualsWithDelta(
            9.025,
            (float)$shippingAddress->getTaxAmount(),
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
        /** @var Product $product */
        $product = $this->objectManager->create(Product::class);
        $product->setTypeId(Type::TYPE_SIMPLE)
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
                'manage_stock' => 1,
                'use_config_manage_stock' => 1,
            ]);

        /** @var ProductRepositoryInterface $repo */
        $repo = $this->objectManager->get(ProductRepositoryInterface::class);
        return $repo->save($product);
    }

    /**
     * Build a Quote with the MN address and the fixture product, persisted
     * to the integration-test sandbox DB so collectTotals() runs against
     * the same shape Magento sees in production.
     */
    private function createMinnesotaQuote(Product $product): Quote
    {
        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $store = $storeManager->getStore();

        /** @var CartManagementInterface $cartManagement */
        $cartManagement = $this->objectManager->get(CartManagementInterface::class);
        /** @var CartRepositoryInterface $cartRepository */
        $cartRepository = $this->objectManager->get(CartRepositoryInterface::class);

        $cartId = $cartManagement->createEmptyCart();
        /** @var Quote $quote */
        $quote = $cartRepository->get($cartId);
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->setQuoteCurrencyCode('USD');
        $quote->setBaseCurrencyCode('USD');
        $quote->setStoreCurrencyCode('USD');

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
        $quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->setShippingMethod('flatrate_flatrate');

        $cartRepository->save($quote);

        return $quote;
    }
}
