<?php
// SPDX-License-Identifier: Apache-2.0
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

        $plugin->beforeCollect(new stdClass(), new stdClass(), new stdClass());

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

        $plugin->beforeCollect(new stdClass(), $shippingAssignment, $this->buildTotal());

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

        $plugin->beforeCollect(new stdClass(), $shippingAssignment, $this->buildTotal());

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

        $plugin->beforeCollect(new stdClass(), $shippingAssignment, $this->buildTotal(10.0));

        self::assertTrue($registry->has(7));
        self::assertSame($expectedResponse, $registry->get(7));
        self::assertIsArray($capturedPayload);
        self::assertSame(7, $capturedPayload['quote_id']);
        self::assertSame('US', $capturedPayload['destination']['country']);
        self::assertSame(10.0, $capturedPayload['shipping_amount']);
        self::assertCount(1, $capturedPayload['lines']);
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

        $plugin->beforeCollect(new stdClass(), $shippingAssignment, $this->buildTotal());

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

        $plugin->beforeCollect(new stdClass(), $shippingAssignment, $this->buildTotal());
    }

    public function testAfterCollectWritesAppliedTaxesFromRegistry(): void
    {
        $registry = new QuoteTaxRegistry();
        $registry->set(10, 'US', OstaxResponse::fromArray([
            'lines' => [
                [
                    'line_id' => '1',
                    'tax'     => 8.0,
                    'rate'    => 0.08,
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

            /**
             * @param array<int, array<string, mixed>> $taxes
             */
            public function setAppliedTaxes(array $taxes): void
            {
                $this->appliedTaxes = $taxes;
            }
        };

        $plugin->afterCollect(new stdClass(), new stdClass(), $shippingAssignment, $total);

        self::assertNotNull($total->appliedTaxes);
        self::assertCount(2, $total->appliedTaxes);
        self::assertSame('Minnesota State', $total->appliedTaxes[0]['id']);
        self::assertEqualsWithDelta(6.875, $total->appliedTaxes[0]['amount'], 0.0001);
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
