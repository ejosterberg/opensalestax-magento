<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Plugin;

use EJOsterberg\OpenSalesTax\Exception\OstaxEngineException;
use EJOsterberg\OpenSalesTax\Model\Config;
use EJOsterberg\OpenSalesTax\Model\OstaxClient;
use EJOsterberg\OpenSalesTax\Model\QuoteTaxRegistry;
use Psr\Log\LoggerInterface;

/**
 * Plugin on `Magento\Quote\Model\Quote\Address\Total\Tax::collect`.
 *
 * Responsibilities:
 *  1. `beforeCollect`: pre-warm the QuoteTaxRegistry by calling the OST
 *     engine once for this quote. Magento's totals pipeline will then call
 *     `Calculation::getRate` per line, and our `CalculationPlugin` will
 *     read from the registry instead of Magento's tax tables.
 *  2. `afterCollect`: surface the per-jurisdiction breakdown onto the
 *     totals object so cart / order summary screens display
 *     "MN State Tax: $6.88, Hennepin County Tax: $1.50, ..." instead of
 *     a single opaque tax line.
 *
 * Gates (constitution §5):
 *  - Quote currency must be USD; otherwise return control to Magento.
 *  - Shipping country must be US; otherwise return control to Magento.
 *  - Module must be configured (api_url set); otherwise no-op.
 *
 * Failure model (constitution §8):
 *  - Default fail-soft: any engine error is logged, registry stays empty,
 *    Magento's built-in tax_rate calc takes over.
 *  - `osstax/general/fail_hard=1` opts into rethrow → Magento surfaces a
 *    checkout exception, blocking the flow.
 */
class QuoteTotalsTaxPlugin
{
    private const COUNTRY_US = 'US';
    private const CURRENCY_USD = 'USD';

    public function __construct(
        private readonly Config $config,
        private readonly OstaxClient $client,
        private readonly QuoteTaxRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Pre-warm the registry. Magento's totals pipeline then calls getRate
     * per line and our other plugin reads from the registry.
     *
     * Method signature MUST mirror the target's `collect()` arity exactly
     * — Magento's compiled Interceptor uses the plugin-method signature to
     * decide what to forward to the parent. The target is
     * `Magento\Tax\Model\Sales\Total\Quote\Tax::collect(Quote, ShippingAssignment, Total)`
     * → three args after `$subject`. (Bug D: the prior signature omitted
     * `$quote` → parent called with 2 args → ArgumentCountError. Verified
     * 2026-05-15 on VM 914. Regression test:
     * `Test/Unit/Etc/PluginAritySignatureTest.php`.)
     *
     * @param object $subject Magento\Tax\Model\Sales\Total\Quote\Tax (untouched)
     * @param object $quote Magento\Quote\Model\Quote
     * @param object $shippingAssignment Magento\Quote\Api\Data\ShippingAssignmentInterface
     * @param object $total Magento\Quote\Model\Quote\Address\Total
     * @return array{0: object, 1: object, 2: object}
     */
    public function beforeCollect(
        object $subject,
        object $quote,
        object $shippingAssignment,
        object $total
    ): array {
        $passthrough = [$quote, $shippingAssignment, $total];

        if (!$this->config->isConfigured()) {
            return $passthrough;
        }

        $quoteId = (int)(method_exists($quote, 'getId') ? $quote->getId() : 0);
        if ($quoteId <= 0) {
            // Fall back to the legacy quote-via-shipping-assignment lookup
            // for callers that drive the plugin in non-standard ways (some
            // custom totals collectors wrap the quote inside the
            // ShippingAssignment before the official collect call).
            $altQuote = $this->extractQuote($shippingAssignment);
            if ($altQuote === null) {
                return $passthrough;
            }
            $quote = $altQuote;
            $quoteId = (int)$quote->getId();
            if ($quoteId <= 0) {
                return $passthrough;
            }
        }

        // Bug E (v1.3.4 fix): `method_exists()` returns FALSE on Magento
        // Interceptor objects for magic getters (routed via __call/getData).
        // `is_callable()` consults __call and returns true for the
        // compiled-from-getData-key getters. Verified 2026-05-15 on VM 914.
        $currency = (string)(is_callable([$quote, 'getQuoteCurrencyCode']) ? $quote->getQuoteCurrencyCode() : '');
        if ($currency !== self::CURRENCY_USD) {
            return $passthrough;
        }

        $shippingAddress = $this->extractShippingAddress($shippingAssignment);
        if ($shippingAddress === null) {
            return $passthrough;
        }

        $countryId = (string)$shippingAddress->getCountryId();
        if ($countryId !== self::COUNTRY_US) {
            return $passthrough;
        }

        $items = $this->extractItems($shippingAssignment);
        if ($items === []) {
            return $passthrough;
        }

        try {
            $payload = $this->buildPayload($quoteId, $shippingAddress, $items, $total);
            $response = $this->client->calculate($payload);
            $this->registry->set($quoteId, $countryId, $response);
        } catch (\Throwable $e) {
            $this->logger->warning('opensalestax: engine call failed; applying fail-soft policy', [
                'quote_id'  => $quoteId,
                'fail_hard' => $this->config->isFailHard(),
            ]);
            if ($this->config->isFailHard()) {
                throw new OstaxEngineException(
                    'OST engine unreachable; checkout blocked (fail-hard mode)',
                    0,
                    $e
                );
            }
        }

        return $passthrough;
    }

    /**
     * Surface per-jurisdiction breakdown onto the totals object.
     *
     * Method signature MUST mirror `Tax::collect()` arity — same Bug D
     * reasoning as `beforeCollect` above. Magento passes the return value
     * of the original method as `$result`, followed by the same args the
     * method received.
     *
     * @param object $subject Magento\Tax\Model\Sales\Total\Quote\Tax (untouched)
     * @param object $result The collector return value (usually $subject)
     * @param object $quote Magento\Quote\Model\Quote
     * @param object $shippingAssignment Magento\Quote\Api\Data\ShippingAssignmentInterface
     * @param object $total Magento\Quote\Model\Quote\Address\Total
     */
    public function afterCollect(
        object $subject,
        object $result,
        object $quote,
        object $shippingAssignment,
        object $total
    ): object {
        if (!method_exists($quote, 'getId')) {
            $quote = $this->extractQuote($shippingAssignment);
            if ($quote === null) {
                return $result;
            }
        }

        $quoteId = (int)$quote->getId();
        $response = $this->registry->get($quoteId);
        if ($response === null) {
            return $result;
        }

        $appliedTaxes = [];
        foreach ($response->lineTaxes as $line) {
            foreach ($line['jurisdictions'] as $jurisdiction) {
                $name = $jurisdiction['name'];
                if (!isset($appliedTaxes[$name])) {
                    $appliedTaxes[$name] = [
                        'rates'      => [['percent' => $jurisdiction['rate'] * 100.0, 'code' => $name, 'title' => $name]],
                        'percent'    => $jurisdiction['rate'] * 100.0,
                        'id'         => $name,
                        'amount'     => 0.0,
                        'base_amount' => 0.0,
                    ];
                }
                $appliedTaxes[$name]['amount'] += $jurisdiction['tax'];
                $appliedTaxes[$name]['base_amount'] += $jurisdiction['tax'];
            }
        }

        if ($appliedTaxes !== [] && method_exists($total, 'setAppliedTaxes')) {
            $total->setAppliedTaxes(array_values($appliedTaxes));
        }

        return $result;
    }

    /**
     * Build the engine request body from the quote.
     *
     * Schema is OpenSalesTax engine v0.58+:
     * ```
     * {
     *   "address":    {"zip5": "55403"},
     *   "line_items": [{"amount": "100.00", "category": "general"}, ...]
     * }
     * ```
     *
     * Notes:
     *  - `amount` MUST be a decimal STRING (not float) — engine quantizes
     *    per-jurisdiction in fixed-point; floats lose precision.
     *  - Shipping is folded in as an extra line_item with `category=shipping`
     *    when non-zero — engine handles per-state shipping-tax rules via
     *    that category (matches the Saleor / Medusa connector pattern).
     *  - `address.zip5` only — engine resolves the rest via ZIP. The ZIP is
     *    extracted as the first 5 digits of the Magento postcode.
     *  - Lines without a usable id or a non-positive amount are skipped.
     *  - The 4 unused parameters `$quoteId`, `$shippingAddress.region/city/country`
     *    that the v1.3.0 payload included are dropped — the engine ignores
     *    them anyway in v0.58.
     *
     * @param array<int, object> $items
     * @return array<string, mixed>
     */
    private function buildPayload(int $quoteId, object $shippingAddress, array $items, object $total): array
    {
        // Cache the mapping once per buildPayload call (one quote-total recompute).
        // Avoids N scope_config reads + JSON-decode passes when a quote has N lines.
        $categoryMapping = $this->config->getCategoryMapping();

        $lineItems = [];
        foreach ($items as $item) {
            $lineId = (string)(method_exists($item, 'getId') ? $item->getId() : '');
            if ($lineId === '') {
                continue;
            }
            // Bug E: see comment at the currency check above. `getRowTotal`,
            // `getTaxClassId`, `getShippingAmount` are all Magento magic
            // getters dispatched via `__call` → `method_exists()` returns
            // false on Interceptor; `is_callable()` returns true.
            $amount = (float)(is_callable([$item, 'getRowTotal']) ? $item->getRowTotal() : 0.0);
            if ($amount <= 0.0) {
                continue;
            }

            // Resolve OST category from the line's Magento tax class. Try the
            // item's getTaxClassId(); fall back to the underlying product if the
            // item is wrapping one. Unknown / zero / unmapped → DEFAULT_CATEGORY.
            $taxClassId = 0;
            if (is_callable([$item, 'getTaxClassId'])) {
                $taxClassId = (int)$item->getTaxClassId();
            }
            if ($taxClassId === 0 && method_exists($item, 'getProduct')) {
                $product = $item->getProduct();
                if (is_object($product) && is_callable([$product, 'getTaxClassId'])) {
                    $taxClassId = (int)$product->getTaxClassId();
                }
            }
            $category = $categoryMapping[$taxClassId] ?? \EJOsterberg\OpenSalesTax\Model\Config::DEFAULT_CATEGORY;

            $lineItems[] = [
                'amount'   => number_format($amount, 2, '.', ''),
                'category' => $category,
            ];
        }

        $shippingAmount = (float)(is_callable([$total, 'getShippingAmount']) ? $total->getShippingAmount() : 0.0);
        if ($shippingAmount > 0.0) {
            $lineItems[] = [
                'amount'   => number_format($shippingAmount, 2, '.', ''),
                'category' => 'shipping',
            ];
        }

        $rawPostcode = (string)$shippingAddress->getPostcode();
        $digitsOnly = preg_replace('/\D/', '', $rawPostcode) ?? '';
        $zip5 = strlen($digitsOnly) >= 5 ? substr($digitsOnly, 0, 5) : $digitsOnly;

        return [
            'address'    => [
                'zip5' => $zip5,
            ],
            'line_items' => $lineItems,
        ];
    }

    private function extractQuote(object $shippingAssignment): ?object
    {
        if (!method_exists($shippingAssignment, 'getShipping')) {
            return null;
        }
        $shipping = $shippingAssignment->getShipping();
        if (!is_object($shipping) || !method_exists($shipping, 'getAddress')) {
            return null;
        }
        $address = $shipping->getAddress();
        if (!is_object($address) || !method_exists($address, 'getQuote')) {
            return null;
        }
        $quote = $address->getQuote();
        return is_object($quote) ? $quote : null;
    }

    private function extractShippingAddress(object $shippingAssignment): ?object
    {
        if (!method_exists($shippingAssignment, 'getShipping')) {
            return null;
        }
        $shipping = $shippingAssignment->getShipping();
        if (!is_object($shipping) || !method_exists($shipping, 'getAddress')) {
            return null;
        }
        $address = $shipping->getAddress();
        return is_object($address) ? $address : null;
    }

    /**
     * @return array<int, object>
     */
    private function extractItems(object $shippingAssignment): array
    {
        if (!method_exists($shippingAssignment, 'getItems')) {
            return [];
        }
        $items = $shippingAssignment->getItems();
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter($items, 'is_object'));
    }
}
