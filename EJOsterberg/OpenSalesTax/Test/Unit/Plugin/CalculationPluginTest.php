<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Plugin;

use EJOsterberg\OpenSalesTax\Model\OstaxResponse;
use EJOsterberg\OpenSalesTax\Model\QuoteTaxRegistry;
use EJOsterberg\OpenSalesTax\Plugin\CalculationPlugin;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

final class CalculationPluginTest extends TestCase
{
    public function testReturnsOstRateWhenRegistryHasMatchingQuote(): void
    {
        $registry = new QuoteTaxRegistry();
        $registry->set(
            42,
            'US',
            OstaxResponse::fromArray(['lines' => [['line_id' => '1', 'rate' => 0.08025, 'tax' => 8.025]]])
        );

        $plugin = new CalculationPlugin($registry);
        $request = new DataObject(['quote_id' => 42, 'country_id' => 'US']);

        self::assertEqualsWithDelta(8.025, $plugin->afterGetRate(new \stdClass(), 5.0, $request), 0.0001);
    }

    public function testFallsThroughWhenQuoteIdMissing(): void
    {
        $registry = new QuoteTaxRegistry();
        $plugin = new CalculationPlugin($registry);
        $request = new DataObject(['country_id' => 'US']);

        self::assertSame(5.0, $plugin->afterGetRate(new \stdClass(), 5.0, $request));
    }

    public function testFallsThroughWhenRegistryHasNoEntry(): void
    {
        $registry = new QuoteTaxRegistry();
        $plugin = new CalculationPlugin($registry);
        $request = new DataObject(['quote_id' => 99, 'country_id' => 'US']);

        self::assertSame(5.0, $plugin->afterGetRate(new \stdClass(), 5.0, $request));
    }

    public function testFallsThroughWhenCountryDiffersFromPrewarm(): void
    {
        $registry = new QuoteTaxRegistry();
        $registry->set(
            42,
            'US',
            OstaxResponse::fromArray(['lines' => [['line_id' => '1', 'rate' => 0.08, 'tax' => 8.0]]])
        );

        $plugin = new CalculationPlugin($registry);
        $request = new DataObject(['quote_id' => 42, 'country_id' => 'CA']);

        self::assertSame(5.0, $plugin->afterGetRate(new \stdClass(), 5.0, $request));
    }
}
