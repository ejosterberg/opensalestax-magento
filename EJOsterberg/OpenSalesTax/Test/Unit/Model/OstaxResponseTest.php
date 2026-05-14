<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Model\OstaxResponse;
use PHPUnit\Framework\TestCase;

final class OstaxResponseTest extends TestCase
{
    public function testFromArrayParsesFullPayload(): void
    {
        $response = OstaxResponse::fromArray([
            'tax_total'    => 8.83,
            'shipping_tax' => 0.83,
            'lines' => [
                [
                    'line_id' => '1',
                    'tax'     => 8.00,
                    'rate'    => 0.08025,
                    'jurisdictions' => [
                        ['name' => 'Minnesota State', 'rate' => 0.06875, 'tax' => 6.875],
                        ['name' => 'Hennepin County', 'rate' => 0.0015,  'tax' => 0.15],
                    ],
                ],
            ],
        ]);

        self::assertSame(8.83, $response->taxTotal);
        self::assertSame(0.83, $response->shippingTax);
        self::assertArrayHasKey('1', $response->lineTaxes);
        self::assertSame(0.08025, $response->lineTaxes['1']['rate']);
        self::assertCount(2, $response->lineTaxes['1']['jurisdictions']);
        self::assertSame('Minnesota State', $response->lineTaxes['1']['jurisdictions'][0]['name']);
    }

    public function testFromArrayToleratesMissingFields(): void
    {
        $response = OstaxResponse::fromArray([]);

        self::assertSame(0.0, $response->taxTotal);
        self::assertSame(0.0, $response->shippingTax);
        self::assertSame([], $response->lineTaxes);
    }

    public function testFromArraySkipsLinesWithoutLineId(): void
    {
        $response = OstaxResponse::fromArray([
            'lines' => [
                ['tax' => 1.0, 'rate' => 0.05],
                ['line_id' => '2', 'tax' => 2.0, 'rate' => 0.05],
            ],
        ]);

        self::assertCount(1, $response->lineTaxes);
        self::assertArrayHasKey('2', $response->lineTaxes);
    }

    public function testGetEffectiveRatePercentConvertsDecimalToPercent(): void
    {
        $response = OstaxResponse::fromArray([
            'lines' => [
                ['line_id' => '1', 'rate' => 0.08025, 'tax' => 8.025],
            ],
        ]);

        self::assertEqualsWithDelta(8.025, $response->getEffectiveRatePercent(), 0.0001);
    }

    public function testGetEffectiveRatePercentReturnsZeroForEmptyResponse(): void
    {
        $response = OstaxResponse::fromArray([]);

        self::assertSame(0.0, $response->getEffectiveRatePercent());
    }
}
