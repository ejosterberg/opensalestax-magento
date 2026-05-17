<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Model\OstaxResponse;
use EJOsterberg\OpenSalesTax\Model\QuoteTaxRegistry;
use PHPUnit\Framework\TestCase;

final class QuoteTaxRegistryTest extends TestCase
{
    public function testSetAndGetRoundTrip(): void
    {
        $registry = new QuoteTaxRegistry();
        $response = OstaxResponse::fromArray(['tax_total' => 5.0, 'lines' => []]);

        $registry->set(42, 'US', $response);

        self::assertTrue($registry->has(42));
        self::assertSame($response, $registry->get(42));
        self::assertSame('US', $registry->getDestinationCountry(42));
    }

    public function testGetReturnsNullForUnknownQuote(): void
    {
        $registry = new QuoteTaxRegistry();

        self::assertFalse($registry->has(99));
        self::assertNull($registry->get(99));
        self::assertNull($registry->getDestinationCountry(99));
    }

    public function testClearRemovesEntry(): void
    {
        $registry = new QuoteTaxRegistry();
        $response = OstaxResponse::fromArray([]);

        $registry->set(1, 'US', $response);
        $registry->clear(1);

        self::assertFalse($registry->has(1));
    }
}
