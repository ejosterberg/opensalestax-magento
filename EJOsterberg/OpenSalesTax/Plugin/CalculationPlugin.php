<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Plugin;

use EJOsterberg\OpenSalesTax\Model\QuoteTaxRegistry;
use Magento\Framework\DataObject;

/**
 * Plugin on `Magento\Tax\Model\Calculation::getRate`.
 *
 * Per ADR-001, we hook the narrowest seam — a single rate lookup — instead
 * of preferencing the public `TaxCalculationInterface`. This plays nicely
 * with merchants who run other tax-adjacent modules.
 *
 * The plugin does NOT call the OST engine itself. The engine call happens
 * once per quote in `QuoteTotalsTaxPlugin::beforeCollect`. We just read the
 * resulting effective rate from the request-scoped registry.
 *
 * Fall-through semantics: if the registry has no entry for this quote
 * (because the gate decided non-US/non-USD, or because we are running
 * outside a totals collection — e.g., an admin rate-table preview), the
 * original Magento rate flows through unchanged.
 */
class CalculationPlugin
{
    public function __construct(
        private readonly QuoteTaxRegistry $registry
    ) {
    }

    /**
     * Replace Magento's calculated rate with the OST-derived effective rate
     * when we have one for the request's destination.
     *
     * @param object $subject The Magento\Tax\Model\Calculation instance. Untouched.
     * @param float $result The percent rate Magento computed from its tables (e.g., 7.5 for 7.5%).
     * @param DataObject $request Tax-rate request bag (country_id, region_id, postcode, etc.).
     */
    public function afterGetRate(object $subject, float $result, DataObject $request): float
    {
        $quoteId = $this->resolveQuoteId($request);
        if ($quoteId === null) {
            return $result;
        }

        $response = $this->registry->get($quoteId);
        if ($response === null) {
            return $result;
        }

        $countryFromRequest = (string)$request->getData('country_id');
        $countryAtPrewarm = $this->registry->getDestinationCountry($quoteId);
        if ($countryAtPrewarm !== null && $countryAtPrewarm !== $countryFromRequest) {
            return $result;
        }

        return $response->getEffectiveRatePercent();
    }

    /**
     * The DataObject request carries a `quote_id` key only on totals-driven
     * lookups. Admin rate-table previews (Stores → Tax → Tax Rates) do not
     * carry one, and we let those pass through.
     */
    private function resolveQuoteId(DataObject $request): ?int
    {
        $raw = $request->getData('quote_id');
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        return (int)$raw;
    }
}
