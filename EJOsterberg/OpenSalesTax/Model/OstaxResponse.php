<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

/**
 * Typed value object for a `/v1/calculate` response from the OST engine.
 *
 * The engine returns a JSON document with per-line tax amounts and an
 * aggregated breakdown. We model it as a frozen DTO so callers can rely
 * on shape without hand-checking array keys at every read site.
 *
 * Engine contract (v0.58+; same wire shape used by Saleor / Medusa /
 * OpenCart / Bagisto / Invoice Ninja connectors via the SDK):
 * ```json
 * {
 *   "subtotal":   "100.00",
 *   "tax_total":  "9.025",
 *   "lines": [
 *     {
 *       "amount":   "100.00",
 *       "category": "general",
 *       "tax":      "9.025",
 *       "rate_pct": "9.025",
 *       "jurisdictions": [
 *         {"name": "Minnesota", "type": "state", "rate_pct": "6.875", "tax": "6.875"}
 *       ]
 *     }
 *   ],
 *   "disclaimer": "..."
 * }
 * ```
 *
 * Internal model: numeric values normalize to floats (engine sends
 * strings); rates store as decimals (e.g. 0.06875 = 6.875%) so existing
 * consumer code that does `rate * 100` keeps working. Lines are keyed by
 * a synthetic 0-based string index since the engine no longer emits
 * `line_id` (kept for shape stability of `lineTaxes`).
 *
 * `shipping_tax` is no longer a top-level field — shipping is folded
 * into `lines[]` as a category. Kept on the DTO for backward-compat
 * (defaults to 0.0); deprecated.
 */
class OstaxResponse
{
    /**
     * @param array<int|string, array{tax: float, rate: float, jurisdictions: array<int, array{name: string, rate: float, tax: float}>}> $lineTaxes
     *     Keyed by line_id. Each entry has tax (USD), rate (decimal, e.g. 0.08 = 8%), and a jurisdictions list.
     */
    public function __construct(
        public readonly float $taxTotal,
        public readonly float $shippingTax,
        public readonly array $lineTaxes
    ) {
    }

    /**
     * Build from a decoded JSON array. Validates required keys; quietly skips
     * lines that lack a line_id.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            isset($payload['tax_total']) ? (float)$payload['tax_total'] : 0.0,
            isset($payload['shipping_tax']) ? (float)$payload['shipping_tax'] : 0.0,
            self::parseLines($payload['lines'] ?? [])
        );
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

    /**
     * Parse the top-level `lines` array. Each line is parsed by parseLine().
     *
     * Engine v0.58 dropped per-line `line_id`. We synthesize one from the
     * 0-based array index so `lineTaxes` keeps its keyed-array shape (the
     * actual key value isn't relied on by any consumer; only the iteration
     * order matters, which preserves request order).
     *
     * @param mixed $rawLines
     * @return array<int|string, array{tax: float, rate: float, jurisdictions: array<int, array{name: string, rate: float, tax: float}>}>
     */
    private static function parseLines($rawLines): array
    {
        if (!is_array($rawLines)) {
            return [];
        }
        $lines = [];
        foreach (array_values($rawLines) as $idx => $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }
            // Prefer engine-supplied line_id when present (older engines);
            // otherwise synthesize from the array index.
            if ($parsed['line_id'] !== '') {
                $key = $parsed['line_id'];
            } else {
                $key = (string)$idx;
            }
            $lines[$key] = $parsed['entry'];
        }
        return $lines;
    }

    /**
     * Parse a single line. Returns null when the line is unparseable.
     *
     * Accepts BOTH the v0.58+ shape (no line_id, `rate_pct` percent string)
     * and the legacy shape (`line_id`, `rate` decimal float) — the legacy
     * support lets older engines on a slow upgrade cycle keep working.
     *
     * @param mixed $line
     * @return array{line_id: string, entry: array{tax: float, rate: float, jurisdictions: array<int, array{name: string, rate: float, tax: float}>}}|null
     */
    private static function parseLine($line): ?array
    {
        if (!is_array($line)) {
            return null;
        }
        return [
            'line_id' => (string)($line['line_id'] ?? ''),
            'entry'   => [
                'tax'           => (float)($line['tax'] ?? 0.0),
                'rate'          => self::extractRate($line),
                'jurisdictions' => self::parseJurisdictions($line['jurisdictions'] ?? []),
            ],
        ];
    }

    /**
     * Extract a rate as a decimal (0.06875 = 6.875%). Engine v0.58+ sends
     * `rate_pct` as a percent string ("6.875"); legacy engines sent `rate`
     * as a decimal float (0.06875). Accept both.
     *
     * @param array<string, mixed> $entry
     */
    private static function extractRate(array $entry): float
    {
        if (isset($entry['rate_pct'])) {
            return (float)$entry['rate_pct'] / 100.0;
        }
        return (float)($entry['rate'] ?? 0.0);
    }

    /**
     * Parse a line's jurisdictions list. Returns an empty array on malformed input.
     *
     * @param mixed $rawJurisdictions
     * @return array<int, array{name: string, rate: float, tax: float}>
     */
    private static function parseJurisdictions($rawJurisdictions): array
    {
        if (!is_array($rawJurisdictions)) {
            return [];
        }
        $jurisdictions = [];
        foreach ($rawJurisdictions as $jurisdiction) {
            if (!is_array($jurisdiction)) {
                continue;
            }
            $jurisdictions[] = [
                'name' => (string)($jurisdiction['name'] ?? ''),
                'rate' => self::extractRate($jurisdiction),
                'tax'  => (float)($jurisdiction['tax'] ?? 0.0),
            ];
        }
        return $jurisdictions;
    }
}
