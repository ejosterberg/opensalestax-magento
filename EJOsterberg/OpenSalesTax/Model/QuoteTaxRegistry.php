<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

/**
 * Request-scoped registry holding OST engine responses for the active quote(s).
 *
 * Magento's totals pipeline calls `Calculation::getRate(...)` many times per
 * checkout — once per (customer-tax-class × product-tax-class × destination)
 * combination. We want to call the OST engine exactly once per quote, so the
 * totals-collector plugin pre-warms this registry in its `beforeCollect`
 * hook, and the `getRate` plugin reads from it.
 *
 * Magento DI defaults classes to singleton scope per request, so this
 * naturally lives for one HTTP request and is discarded after.
 */
class QuoteTaxRegistry
{
    /** @var array<int, OstaxResponse> */
    private array $responsesByQuoteId = [];

    /** @var array<int, string> Mirrors the country_id we built the registry for, used to gate getRate lookups. */
    private array $destinationCountryByQuoteId = [];

    public function set(int $quoteId, string $destinationCountry, OstaxResponse $response): void
    {
        $this->responsesByQuoteId[$quoteId] = $response;
        $this->destinationCountryByQuoteId[$quoteId] = $destinationCountry;
    }

    public function get(int $quoteId): ?OstaxResponse
    {
        return $this->responsesByQuoteId[$quoteId] ?? null;
    }

    public function getDestinationCountry(int $quoteId): ?string
    {
        return $this->destinationCountryByQuoteId[$quoteId] ?? null;
    }

    public function has(int $quoteId): bool
    {
        return isset($this->responsesByQuoteId[$quoteId]);
    }

    public function clear(int $quoteId): void
    {
        unset($this->responsesByQuoteId[$quoteId], $this->destinationCountryByQuoteId[$quoteId]);
    }
}
