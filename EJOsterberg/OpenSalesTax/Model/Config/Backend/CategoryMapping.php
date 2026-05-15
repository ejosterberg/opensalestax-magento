<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Source\OstCategory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Event\ManagerInterface;

/**
 * Backend model for the Tax-Class → OST-Category Mapping admin field.
 *
 * Admin posts a dynamic-rows table:
 *   [
 *     'row_42' => ['tax_class_id' => '2', 'ost_category' => 'clothing'],
 *     'row_99' => ['tax_class_id' => '3', 'ost_category' => 'groceries'],
 *     ...
 *   ]
 *
 * `beforeSave()` validates each row + collapses to a flat
 *   ['2' => 'clothing', '3' => 'groceries']
 * map and JSON-encodes for storage in `core_config_data`.
 *
 * `afterLoad()` decodes the JSON back into the row-form the dynamic-
 * rows widget expects on next admin page render.
 *
 * Invalid posts throw `LocalizedException` — Magento surfaces these
 * as red banners on the admin form. The dropdown UI should never
 * produce them; defense-in-depth catches a tampered form.
 */
class CategoryMapping extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Json $json,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate + serialize before persisting.
     *
     * Accepts TWO input shapes:
     *  1. JSON string (v1.3.0 textarea UI): merchant posts e.g.
     *     `{"2":"clothing","3":"groceries"}`.
     *  2. Array of rows (v1.3.1+ dynamic-rows widget): merchant posts
     *     `['row_42' => ['tax_class_id'=>'2', 'ost_category'=>'clothing']]`.
     * Both end up serialized to the same JSON-object on-disk shape.
     */
    public function beforeSave(): self
    {
        $value = $this->getValue();

        // Path 1: JSON string from the textarea UI
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                $this->setValue('');
                return parent::beforeSave();
            }
            try {
                $decoded = $this->json->unserialize($value);
            } catch (\InvalidArgumentException $e) {
                throw new LocalizedException(
                    __('OpenSalesTax category mapping must be a JSON object — could not parse: %1', $e->getMessage())
                );
            }
            if (!is_array($decoded)) {
                throw new LocalizedException(
                    __('OpenSalesTax category mapping must be a JSON object mapping tax class id to OST category.')
                );
            }
            $flat = $this->validateAndNormalize($decoded);
            $this->setValue($this->json->serialize($flat));
            return parent::beforeSave();
        }

        // Path 2: dynamic-rows widget post (v1.3.1+)
        if (!is_array($value)) {
            $this->setValue('');
            return parent::beforeSave();
        }

        $valid = OstCategory::getValidValues();
        $invalid = [];
        $flat = [];
        foreach ($value as $rowKey => $row) {
            if (!is_array($row)) {
                continue;
            }
            $taxClassId = isset($row['tax_class_id']) ? (string)$row['tax_class_id'] : '';
            $ostCategory = isset($row['ost_category']) ? (string)$row['ost_category'] : '';
            if ($taxClassId === '' && $ostCategory === '') {
                continue;
            }
            if (!ctype_digit($taxClassId) || (int)$taxClassId <= 0) {
                $invalid[] = sprintf('row "%s": tax class id must be a positive integer (got "%s")', $rowKey, $taxClassId);
                continue;
            }
            if (!in_array($ostCategory, $valid, true)) {
                $invalid[] = sprintf('row "%s": "%s" is not a valid OST category', $rowKey, $ostCategory);
                continue;
            }
            $flat[$taxClassId] = $ostCategory;
        }
        if ($invalid !== []) {
            throw new LocalizedException(
                __(
                    'Invalid OpenSalesTax category mapping: %1. Valid categories: %2.',
                    implode('; ', $invalid),
                    $this->formatValidList($valid)
                )
            );
        }

        $this->setValue($this->json->serialize($flat));
        return parent::beforeSave();
    }

    /**
     * Validate and normalize a decoded JSON object into the flat
     * `tax_class_id => ost_category` shape used for storage.
     *
     * @param array<int|string, mixed> $decoded
     * @return array<string, string>
     */
    private function validateAndNormalize(array $decoded): array
    {
        $valid = OstCategory::getValidValues();
        $invalid = [];
        $flat = [];
        foreach ($decoded as $taxClassId => $ostCategory) {
            $idStr = (string)$taxClassId;
            if (!ctype_digit($idStr) || (int)$idStr <= 0) {
                $invalid[] = sprintf('"%s": tax class id must be a positive integer', $idStr);
                continue;
            }
            if (!is_string($ostCategory)) {
                $invalid[] = sprintf('"%s": OST category must be a string', $idStr);
                continue;
            }
            if (!in_array($ostCategory, $valid, true)) {
                $invalid[] = sprintf('"%s" => "%s": not a valid OST category', $idStr, $ostCategory);
                continue;
            }
            $flat[$idStr] = $ostCategory;
        }
        if ($invalid !== []) {
            throw new LocalizedException(
                __(
                    'Invalid OpenSalesTax category mapping: %1. Valid categories: %2.',
                    implode('; ', $invalid),
                    $this->formatValidList($valid)
                )
            );
        }
        return $flat;
    }

    /**
     * @param array<int, string> $valid
     */
    private function formatValidList(array $valid): string
    {
        return implode(', ', array_map(
            static fn (string $c): string => $c === '' ? "'' (skip)" : $c,
            $valid
        ));
    }

    /**
     * For the v1.3.0 textarea UI, return the JSON string as-is so the
     * admin sees their saved JSON in the textarea on form load.
     *
     * (When v1.3.1 adds the dynamic-rows widget, swap this to decode
     * into the row shape the widget expects.)
     */
    public function afterLoad(): self
    {
        // No-op for the textarea UI — the stored JSON string IS what
        // the textarea renders.
        return parent::afterLoad();
    }

    /**
     * @deprecated Not used by the v1.3.0 textarea UI; kept for v1.3.1 widget.
     */
    private function _afterLoadForDynamicRowsWidget(): self
    {
        $value = $this->getValue();
        if (!is_string($value) || $value === '') {
            $this->setValue([]);
            return parent::afterLoad();
        }
        try {
            $decoded = $this->json->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            $this->setValue([]);
            return parent::afterLoad();
        }
        if (!is_array($decoded)) {
            $this->setValue([]);
            return parent::afterLoad();
        }
        $rows = [];
        foreach ($decoded as $taxClassId => $ostCategory) {
            if (!is_string($ostCategory)) {
                continue;
            }
            $rows[] = [
                'tax_class_id' => (string)$taxClassId,
                'ost_category' => $ostCategory,
            ];
        }
        $this->setValue($rows);
        return parent::afterLoad();
    }
}
