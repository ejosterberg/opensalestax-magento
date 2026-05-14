<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

/**
 * Typed value object for a `/v1/calculate` response from the OST engine.
 *
 * The engine returns a JSON document with per-line tax amounts and an
 * aggregated breakdown. We model it as a frozen DTO so callers can rely on
 * shape without hand-checking array keys at every read site.
 *
 * Engine contract (v1):
 * ```json
 * {
 *   "tax_total": 8.83,
 *   "shipping_tax": 0.83,
 *   "lines": [
 *     {
 *       "line_id": "1",
 *       "tax": 8.00,
 *       "rate": 0.08,
 *       "jurisdictions": [
 *         {"name": "Minnesota State", "rate": 0.06875, "tax": 6.875}
 *       ]
 *     }
 *   ]
 * }
 * ```
 */
class OstaxResponse
{
    /**
     * @param array<string, array{tax: float, rate: float, jurisdictions: array<int, array{name: string, rate: float, tax: float}>}> $lineTaxes
     *     Keyed by line_id. Each entry has tax (USD), rate (decimal, e.g. 0.08 = 8%), and a jurisdictions list.
     */
    public function __construct(
        public readonly float $taxTotal,
        public readonly float $shippingTax,
        public readonly array $lineTaxes
    ) {
    }

    /**
     * Build from a decoded JSON array. Validates required keys; throws on shape mismatch.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $taxTotal = isset($payload['tax_total']) ? (float)$payload['tax_total'] : 0.0;
        $shippingTax = isset($payload['shipping_tax']) ? (float)$payload['shipping_tax'] : 0.0;

        $lineTaxes = [];
        $rawLines = $payload['lines'] ?? [];
        if (!is_array($rawLines)) {
            $rawLines = [];
        }
        foreach ($rawLines as $line) {
            if (!is_array($line) || !isset($line['line_id'])) {
                continue;
            }
            $lineId = (string)$line['line_id'];
            $jurisdictions = [];
            $rawJurisdictions = $line['jurisdictions'] ?? [];
            if (is_array($rawJurisdictions)) {
                foreach ($rawJurisdictions as $jurisdiction) {
                    if (!is_array($jurisdiction)) {
                        continue;
                    }
                    $jurisdictions[] = [
                        'name' => (string)($jurisdiction['name'] ?? ''),
                        'rate' => (float)($jurisdiction['rate'] ?? 0.0),
                        'tax'  => (float)($jurisdiction['tax'] ?? 0.0),
                    ];
                }
            }
            $lineTaxes[$lineId] = [
                'tax'           => (float)($line['tax'] ?? 0.0),
                'rate'          => (float)($line['rate'] ?? 0.0),
                'jurisdictions' => $jurisdictions,
            ];
        }

        return new self($taxTotal, $shippingTax, $lineTaxes);
    }

    /**
     * Effective rate for the destination, as a percent (e.g. 8.025 for 8.025%).
     *
     * Picks the first line's rate (all lines for a single destination share
     * the same jurisdictional rate in the engine's v1 model). Returns 0.0 if
     * the response has no lines.
     */
    public function getEffectiveRatePercent(): float
    {
        foreach ($this->lineTaxes as $line) {
            return $line['rate'] * 100.0;
        }
        return 0.0;
    }
}
