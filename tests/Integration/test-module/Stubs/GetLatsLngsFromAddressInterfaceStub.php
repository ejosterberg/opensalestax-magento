<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OstaxTestStubs\Stubs;

use Magento\InventoryDistanceBasedSourceSelectionApi\Api\GetLatsLngsFromAddressInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;

/**
 * No-op stub for Magento_InventoryDistanceBasedSourceSelectionApi's
 * GetLatsLngsFromAddressInterface, used only by the Mg-1 CI integration
 * test (LiveMagentoTaxTest).
 *
 * Why this exists: Magento OSS 2.4.6-p10 / 2.4.7-p3 ship a DI resolution
 * bug where `$quote->collectTotals()` triggers source-selection code that
 * tries to instantiate the upstream concrete `GetLatsLngsFromAddress`
 * (in module InventoryDistanceBasedSourceSelection, NOT the Api). That
 * concrete class wires `GetGeoCodesForAddress` whose ctor expects
 * `\Magento\Framework\HTTP\ClientInterface` but Magento's DI hands it
 * `\Magento\Framework\HTTP\Client\Curl`. PHP strict typing throws even
 * though Curl IS a ClientInterface implementation in modern Magento.
 *
 * This stub short-circuits that entire branch by returning an empty
 * lat/lng list ("no geocoding data available for this address"). That's
 * a legal contract response per the interface PHPDoc (the return type
 * is `LatLngInterface[]`, and the source-selection algorithm tolerates
 * an empty list as "fall back to next strategy").
 *
 * Our OST module doesn't care about MSI source-selection at all - we
 * calculate tax on the cart, not allocate stock - so wiping this
 * branch is loss-less for the Mg-1 assertion's coverage.
 *
 * IMPORTANT: this class lives under `tests/Integration/test-module/`
 * in the source tree, NOT inside `EJOsterberg/OpenSalesTax/`. It is
 * NOT shipped to merchants who install the module via composer. The
 * CI workflow copies this entire `test-module/` tree into
 * `$MAGENTO_DIR/app/code/EJOsterberg/OstaxTestStubs/` before running
 * `composer install` so Magento's PSR-0 autoloader (which maps
 * `app/code/` for any vendor) picks it up.
 *
 * Tracked in `portfolio/improvement-queue.md` as Mg-1.1.
 */
class GetLatsLngsFromAddressInterfaceStub implements GetLatsLngsFromAddressInterface
{
    /**
     * Return an empty lat/lng list. See class PHPDoc for rationale.
     *
     * @param AddressInterface $address
     * @return \Magento\InventoryDistanceBasedSourceSelectionApi\Api\Data\LatLngInterface[]
     */
    public function execute(AddressInterface $address): array
    {
        return [];
    }
}
