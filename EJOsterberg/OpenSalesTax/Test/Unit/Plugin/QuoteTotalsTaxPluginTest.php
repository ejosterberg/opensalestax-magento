<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Plugin;

use EJOsterberg\OpenSalesTax\Exception\OstaxEngineException;
use EJOsterberg\OpenSalesTax\Model\Config;
use EJOsterberg\OpenSalesTax\Model\OstaxClient;
use EJOsterberg\OpenSalesTax\Model\OstaxResponse;
use EJOsterberg\OpenSalesTax\Model\QuoteTaxRegistry;
use EJOsterberg\OpenSalesTax\Plugin\QuoteTotalsTaxPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

final class QuoteTotalsTaxPluginTest extends TestCase
{
    public function testBeforeCollectSkipsWhenUnconfigured(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(false);

        $client = $this->createMock(OstaxClient::class);
        $client->expects(self::never())->method('calculate');

        $registry = new QuoteTaxRegistry();
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $this->createMock(LoggerInterface::class));

        $plugin->beforeCollect(new stdClass(), new stdClass(), new stdClass(), new stdClass());

        self::assertFalse($registry->has(0));
    }

    public function testBeforeCollectSkipsWhenCurrencyIsNotUsd(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);

        $client = $this->createMock(OstaxClient::class);
        $client->expects(self::never())->method('calculate');

        $registry = new QuoteTaxRegistry();
        $logger = $this->createMock(LoggerInterface::class);
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $logger);

        $shippingAssignment = $this->buildShippingAssignment(
            quoteId: 5,
            currency: 'EUR',
            country: 'US',
            items: [['id' => '1', 'row_total' => 100.0, 'qty' => 1]],
        );

        $plugin->beforeCollect(new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $this->buildTotal());

        self::assertFalse($registry->has(5));
    }

    public function testBeforeCollectSkipsWhenCountryIsNotUs(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);

        $client = $this->createMock(OstaxClient::class);
        $client->expects(self::never())->method('calculate');

        $registry = new QuoteTaxRegistry();
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $this->createMock(LoggerInterface::class));

        $shippingAssignment = $this->buildShippingAssignment(
            quoteId: 6,
            currency: 'USD',
            country: 'CA',
            items: [['id' => '1', 'row_total' => 100.0, 'qty' => 1]],
        );

        $plugin->beforeCollect(new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $this->buildTotal());

        self::assertFalse($registry->has(6));
    }

    public function testBeforeCollectPrewarmsRegistryOnUsdUsCheckout(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isFailHard')->willReturn(false);

        $expectedResponse = OstaxResponse::fromArray([
            'tax_total' => 8.0,
            'lines'     => [['line_id' => '1', 'tax' => 8.0, 'rate' => 0.08]],
        ]);

        $capturedPayload = null;
        $client = $this->createMock(OstaxClient::class);
        $client->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function (array $payload) use (&$capturedPayload, $expectedResponse) {
                $capturedPayload = $payload;
                return $expectedResponse;
            });

        $registry = new QuoteTaxRegistry();
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $this->createMock(LoggerInterface::class));

        $shippingAssignment = $this->buildShippingAssignment(
            quoteId: 7,
            currency: 'USD',
            country: 'US',
            items: [['id' => '1', 'row_total' => 100.0, 'qty' => 2]],
        );

        $plugin->beforeCollect(new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $this->buildTotal(10.0));

        self::assertTrue($registry->has(7));
        self::assertSame($expectedResponse, $registry->get(7));
        self::assertIsArray($capturedPayload);

        // v0.58 wire shape: address.zip5 + line_items[]. The legacy
        // quote_id / destination / shipping_amount keys are gone; the
        // engine ignored them anyway and they polluted the request body.
        self::assertArrayHasKey('address', $capturedPayload);
        self::assertSame('55403', $capturedPayload['address']['zip5']);
        self::assertArrayNotHasKey('quote_id', $capturedPayload);
        self::assertArrayNotHasKey('destination', $capturedPayload);
        self::assertArrayNotHasKey('shipping_amount', $capturedPayload);
        self::assertArrayNotHasKey('lines', $capturedPayload);

        // Two line_items: the original product line + a synthesized
        // shipping line with category='shipping'. Amounts MUST be decimal
        // strings (engine quantizes per-jurisdiction in fixed-point).
        self::assertCount(2, $capturedPayload['line_items']);
        self::assertSame('100.00', $capturedPayload['line_items'][0]['amount']);
        self::assertSame('general', $capturedPayload['line_items'][0]['category']);
        self::assertSame('10.00', $capturedPayload['line_items'][1]['amount']);
        self::assertSame('shipping', $capturedPayload['line_items'][1]['category']);
    }

    public function testBeforeCollectFailSoftSwallowsEngineError(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isFailHard')->willReturn(false);

        $client = $this->createMock(OstaxClient::class);
        $client->method('calculate')->willThrowException(new RuntimeException('boom'));

        $registry = new QuoteTaxRegistry();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $logger);

        $shippingAssignment = $this->buildShippingAssignment(
            quoteId: 8,
            currency: 'USD',
            country: 'US',
            items: [['id' => '1', 'row_total' => 100.0, 'qty' => 1]],
        );

        $plugin->beforeCollect(new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $this->buildTotal());

        self::assertFalse($registry->has(8));
    }

    public function testBeforeCollectFailHardRethrows(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isFailHard')->willReturn(true);

        $client = $this->createMock(OstaxClient::class);
        $client->method('calculate')->willThrowException(new RuntimeException('boom'));

        $registry = new QuoteTaxRegistry();
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $this->createMock(LoggerInterface::class));

        $shippingAssignment = $this->buildShippingAssignment(
            quoteId: 9,
            currency: 'USD',
            country: 'US',
            items: [['id' => '1', 'row_total' => 100.0, 'qty' => 1]],
        );

        $this->expectException(OstaxEngineException::class);
        $this->expectExceptionMessageMatches('/fail-hard/');

        $plugin->beforeCollect(new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $this->buildTotal());
    }

    public function testAfterCollectWritesAppliedTaxesAndTaxAmountFromRegistry(): void
    {
        // Bug F (v1.3.5): in addition to `setAppliedTaxes` (the per-
        // jurisdiction *breakdown*), the plugin MUST call setTaxAmount,
        // setBaseTaxAmount, setTotalAmount('tax', X), setBaseTotalAmount-
        // ('tax', X). Magento reads $address->getTaxAmount() and the
        // grand-total roll-up from those â€” NOT from applied_taxes.
        $registry = new QuoteTaxRegistry();
        $registry->set(10, 'US', OstaxResponse::fromArray([
            'tax_total' => 7.025,
            'lines' => [
                [
                    'line_id' => '1',
                    'tax'     => 7.025,
                    'rate'    => 0.07025,
                    'jurisdictions' => [
                        ['name' => 'Minnesota State', 'rate' => 0.06875, 'tax' => 6.875],
                        ['name' => 'Hennepin County', 'rate' => 0.0015,  'tax' => 0.15],
                    ],
                ],
            ],
        ]));

        $plugin = new QuoteTotalsTaxPlugin(
            $this->createMock(Config::class),
            $this->createMock(OstaxClient::class),
            $registry,
            $this->createMock(LoggerInterface::class)
        );

        $shippingAssignment = $this->buildShippingAssignment(quoteId: 10, currency: 'USD', country: 'US', items: []);
        $total = new class () {
            /** @var array<int, array<string, mixed>>|null */
            public ?array $appliedTaxes = null;
            public ?float $taxAmount = null;
            public ?float $baseTaxAmount = null;
            /** @var array<string, float> */
            public array $totalAmounts = [];
            /** @var array<string, float> */
            public array $baseTotalAmounts = [];

            /** @param array<int, array<string, mixed>> $taxes */
            public function setAppliedTaxes(array $taxes): void
            {
                $this->appliedTaxes = $taxes;
            }
            public function setTaxAmount(float $amount): void
            {
                $this->taxAmount = $amount;
            }
            public function setBaseTaxAmount(float $amount): void
            {
                $this->baseTaxAmount = $amount;
            }
            public function setTotalAmount(string $code, float $amount): void
            {
                $this->totalAmounts[$code] = $amount;
            }
            public function setBaseTotalAmount(string $code, float $amount): void
            {
                $this->baseTotalAmounts[$code] = $amount;
            }
        };

        $plugin->afterCollect(new stdClass(), new stdClass(), $shippingAssignment->getShipping()->getAddress()->getQuote(), $shippingAssignment, $total);

        // Breakdown (already covered pre-v1.3.5)
        self::assertNotNull($total->appliedTaxes);
        self::assertCount(2, $total->appliedTaxes);
        self::assertSame('Minnesota State', $total->appliedTaxes[0]['id']);
        self::assertEqualsWithDelta(6.875, $total->appliedTaxes[0]['amount'], 0.0001);

        // The actual tax-amount writes (the Bug F #2 fix)
        self::assertEqualsWithDelta(7.025, $total->taxAmount ?? -1.0, 0.0001, 'setTaxAmount must be called (Bug F)');
        self::assertEqualsWithDelta(7.025, $total->baseTaxAmount ?? -1.0, 0.0001, 'setBaseTaxAmount must be called (Bug F)');
        self::assertArrayHasKey('tax', $total->totalAmounts, 'setTotalAmount(tax, X) must be called (Bug F)');
        self::assertEqualsWithDelta(7.025, $total->totalAmounts['tax'], 0.0001);
        self::assertArrayHasKey('tax', $total->baseTotalAmounts, 'setBaseTotalAmount(tax, X) must be called (Bug F)');
        self::assertEqualsWithDelta(7.025, $total->baseTotalAmounts['tax'], 0.0001);
    }

    /**
     * Bug F regression part 2: when the $total argument exposes its
     * setters ONLY via __call (mirroring how Magento's
     * `Quote\Address\Total` DataObject routes setTaxAmount/etc), the
     * v1.3.4 `method_exists()` guard skipped the call entirely. The
     * `is_callable()` check (which consults __call) must accept these.
     */
    public function testAfterCollectWritesTaxAmountThroughMagicCallSetters(): void
    {
        $registry = new QuoteTaxRegistry();
        $registry->set(20, 'US', OstaxResponse::fromArray([
            'tax_total' => 9.025,
            'lines' => [
                [
                    'line_id' => '1',
                    'tax'     => 9.025,
                    'rate'    => 0.09025,
                    'jurisdictions' => [
                        ['name' => 'Minnesota State', 'rate' => 0.06875, 'tax' => 6.875],
                    ],
                ],
            ],
        ]));

        $plugin = new QuoteTotalsTaxPlugin(
            $this->createMock(Config::class),
            $this->createMock(OstaxClient::class),
            $registry,
            $this->createMock(LoggerInterface::class)
        );

        // Quote that routes getId via __call (like Magento's Interceptor).
        $quote = new class () {
            public function __call(string $name, array $args): mixed
            {
                return match ($name) { 'getId' => 20, default => null };
            }
        };

        $shippingAssignment = $this->buildShippingAssignment(quoteId: 20, currency: 'USD', country: 'US', items: []);

        // $total exposes its setters ONLY via __call (like
        // Magento\Quote\Model\Quote\Address\Total â€” a DataObject).
        $total = new class () {
            /** @var array<string, mixed> */
            public array $captured = [];
            public function __call(string $name, array $args): mixed
            {
                $this->captured[$name] = $args;
                return $this;
            }
        };

        $plugin->afterCollect(new stdClass(), new stdClass(), $quote, $shippingAssignment, $total);

        self::assertArrayHasKey('setTaxAmount', $total->captured, 'Bug F: setTaxAmount must be called even when only __call exposes it');
        self::assertEqualsWithDelta(9.025, $total->captured['setTaxAmount'][0], 0.0001);
        self::assertArrayHasKey('setBaseTaxAmount', $total->captured);
        self::assertArrayHasKey('setTotalAmount', $total->captured);
        self::assertSame('tax', $total->captured['setTotalAmount'][0]);
        self::assertEqualsWithDelta(9.025, $total->captured['setTotalAmount'][1], 0.0001);
        self::assertArrayHasKey('setBaseTotalAmount', $total->captured);
        self::assertArrayHasKey('setAppliedTaxes', $total->captured);
    }

    /**
     * Bug E regression: Magento Interceptor wraps domain objects and routes
     * `getQuoteCurrencyCode` / `getRowTotal` / `getTaxClassId` /
     * `getShippingAmount` through `__call` â†’ `getData`. Those aren't
     * declared methods on the Interceptor class, so `method_exists()`
     * returns false even though `is_callable()` (which consults `__call`)
     * returns true. The pre-v1.3.4 plugin used `method_exists()` and so
     * silently bailed out before the engine call on every real Magento
     * checkout. This test pins the post-v1.3.4 `is_callable()` behavior
     * by passing objects that only expose getters through `__call` â€”
     * no declared methods.
     */
    public function testBeforeCollectHandlesMagicGettersOnMagentoInterceptorObjects(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isFailHard')->willReturn(false);

        $capturedPayload = null;
        $client = $this->createMock(OstaxClient::class);
        $client->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function (array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return OstaxResponse::fromArray([
                    'tax_total' => 9.025,
                    'lines'     => [['line_id' => '1', 'tax' => 9.025, 'rate' => 0.09025]],
                ]);
            });

        // Quote with currency exposed ONLY via __call (mirrors Magento\Quote\Model\Quote\Interceptor)
        $quote = new class () {
            public function getId(): int { return 11; }
            public function __call(string $name, array $args): mixed
            {
                return match ($name) {
                    'getQuoteCurrencyCode' => 'USD',
                    default => null,
                };
            }
        };

        // Item with row_total + tax_class_id exposed ONLY via __call
        $item = new class () {
            public function getId(): string { return 'magic-1'; }
            public function __call(string $name, array $args): mixed
            {
                return match ($name) {
                    'getRowTotal' => 100.0,
                    'getTaxClassId' => 2,
                    default => null,
                };
            }
        };

        $address = new class ($quote) {
            public function __construct(private object $quote) {}
            public function getCountryId(): string { return 'US'; }
            public function getRegionCode(): string { return 'MN'; }
            public function getPostcode(): string { return '55403'; }
            public function getCity(): string { return 'Minneapolis'; }
            public function getQuote(): object { return $this->quote; }
        };

        $shipping = new class ($address) {
            public function __construct(private object $address) {}
            public function getAddress(): object { return $this->address; }
        };

        $shippingAssignment = new class ($shipping, [$item]) {
            /** @param array<int, object> $items */
            public function __construct(private object $shipping, private array $items) {}
            public function getShipping(): object { return $this->shipping; }
            /** @return array<int, object> */
            public function getItems(): array { return $this->items; }
        };

        // Total with shipping_amount exposed ONLY via __call
        $total = new class () {
            public function __call(string $name, array $args): mixed
            {
                return match ($name) {
                    'getShippingAmount' => 10.0,
                    default => null,
                };
            }
        };

        $registry = new QuoteTaxRegistry();
        $plugin = new QuoteTotalsTaxPlugin($config, $client, $registry, $this->createMock(LoggerInterface::class));

        $plugin->beforeCollect(new stdClass(), $quote, $shippingAssignment, $total);

        // Must reach the engine â€” registry populated.
        self::assertTrue($registry->has(11), 'Bug E: plugin bailed out before the engine call (method_exists() vs __call mismatch).');
        self::assertIsArray($capturedPayload);
        self::assertSame('55403', $capturedPayload['address']['zip5']);
        // Two line_items: product + synthesized shipping line (from getShippingAmount via __call)
        self::assertCount(2, $capturedPayload['line_items']);
        self::assertSame('100.00', $capturedPayload['line_items'][0]['amount']);
        self::assertSame('10.00', $capturedPayload['line_items'][1]['amount']);
        self::assertSame('shipping', $capturedPayload['line_items'][1]['category']);
    }

    /**
     * @param array<int, array{id: string, row_total: float, qty: float}> $items
     */
    private function buildShippingAssignment(int $quoteId, string $currency, string $country, array $items): object
    {
        $quote = new class ($quoteId, $currency) {
            public function __construct(private int $quoteId, private string $currency)
            {
            }

            public function getId(): int
            {
                return $this->quoteId;
            }

            public function getQuoteCurrencyCode(): string
            {
                return $this->currency;
            }
        };

        $address = new class ($country, $quote) {
            public function __construct(private string $country, private object $quote)
            {
            }

            public function getCountryId(): string
            {
                return $this->country;
            }

            public function getRegionCode(): string
            {
                return 'MN';
            }

            public function getPostcode(): string
            {
                return '55403';
            }

            public function getCity(): string
            {
                return 'Minneapolis';
            }

            public function getQuote(): object
            {
                return $this->quote;
            }
        };

        $shipping = new class ($address) {
            public function __construct(private object $address)
            {
            }

            public function getAddress(): object
            {
                return $this->address;
            }
        };

        $itemObjects = [];
        foreach ($items as $i) {
            $itemObjects[] = new class ($i['id'], $i['row_total'], $i['qty']) {
                public function __construct(private string $id, private float $rowTotal, private float $qty)
                {
                }

                public function getId(): string
                {
                    return $this->id;
                }

                public function getRowTotal(): float
                {
                    return $this->rowTotal;
                }

                public function getQty(): float
                {
                    return $this->qty;
                }
            };
        }

        return new class ($shipping, $itemObjects) {
            /** @param array<int, object> $items */
            public function __construct(private object $shipping, private array $items)
            {
            }

            public function getShipping(): object
            {
                return $this->shipping;
            }

            /** @return array<int, object> */
            public function getItems(): array
            {
                return $this->items;
            }
        };
    }

    private function buildTotal(float $shippingAmount = 0.0): object
    {
        return new class ($shippingAmount) {
            public function __construct(private float $shippingAmount)
            {
            }

            public function getShippingAmount(): float
            {
                return $this->shippingAmount;
            }
        };
    }
}
