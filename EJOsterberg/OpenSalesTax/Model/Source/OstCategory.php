<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Canonical OpenSalesTax category vocabulary.
 *
 * Mirrors ADR-005 in `opensalestax-vendure` (the cross-portfolio
 * vocabulary alignment ADR) so every connector emits the same enum
 * value over the wire and the engine's per-state rate tables don't
 * need per-connector branches.
 *
 * Values are the strings sent to the engine in the `category` field
 * of each line item in `POST /v1/calculate`. Empty string is the
 * "skip this line" sentinel — the strategy returns no tax line for
 * the affected order line, and Magento's built-in tax math handles
 * it (typically zero).
 */
class OstCategory implements OptionSourceInterface
{
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_CLOTHING = 'clothing';
    public const CATEGORY_GROCERIES = 'groceries';
    public const CATEGORY_PRESCRIPTION_DRUGS = 'prescription_drugs';
    public const CATEGORY_PREPARED_FOOD = 'prepared_food';
    public const CATEGORY_DIGITAL_GOODS = 'digital_goods';
    public const CATEGORY_SKIP = '';

    /**
     * Returns the canonical option set for an admin dropdown.
     *
     * Labels are raw English strings (not wrapped in `__()`) so this
     * class is instantiable in unit tests without Magento's translator
     * bootstrapped. v1.3 is English-only by design; i18n is a v1.4+
     * followup that can wrap these in `__()` when the dropdown UI lands.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::CATEGORY_GENERAL,            'label' => 'General'],
            ['value' => self::CATEGORY_CLOTHING,           'label' => 'Clothing'],
            ['value' => self::CATEGORY_GROCERIES,          'label' => 'Groceries (unprepared food)'],
            ['value' => self::CATEGORY_PRESCRIPTION_DRUGS, 'label' => 'Prescription Drugs'],
            ['value' => self::CATEGORY_PREPARED_FOOD,      'label' => 'Prepared Food'],
            ['value' => self::CATEGORY_DIGITAL_GOODS,      'label' => 'Digital Goods'],
            ['value' => self::CATEGORY_SKIP,               'label' => 'Skip (non-taxable)'],
        ];
    }

    /**
     * Returns the set of valid category values. Used by the backend
     * model to validate posted form data.
     *
     * @return array<int, string>
     */
    public static function getValidValues(): array
    {
        return [
            self::CATEGORY_GENERAL,
            self::CATEGORY_CLOTHING,
            self::CATEGORY_GROCERIES,
            self::CATEGORY_PRESCRIPTION_DRUGS,
            self::CATEGORY_PREPARED_FOOD,
            self::CATEGORY_DIGITAL_GOODS,
            self::CATEGORY_SKIP,
        ];
    }
}
