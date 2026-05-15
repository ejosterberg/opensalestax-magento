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

/**
 * Backend model for the Tax-Class → OST-Category Mapping admin field.
 *
 * Accepts two input shapes (defense-in-depth — both paths normalize to
 * the same on-disk JSON object):
 *   1. JSON string from the v1.3.0 textarea UI:
 *        `{"2":"clothing","3":"groceries"}`
 *   2. Array of rows from a future dynamic-rows widget:
 *        `['row_42' => ['tax_class_id'=>'2','ost_category'=>'clothing']]`
 *
 * Invalid posts throw `LocalizedException` — Magento surfaces these as
 * red banners on the admin form. The dropdown UI should never produce
 * them; this is defense against a tampered form post.
 *
 * Constructor uses the `...$parentArgs` pattern (matches ApiUrl) so we
 * don't have to redeclare Magento's full backend-model DI signature
 * (`Context`, `Registry`, etc.) — those classes aren't stubbed for
 * PHPStan and pulling them in would force a Marketplace composer
 * dependency.
 */
class CategoryMapping extends Value
{
    /**
     * Use Magento's explicit backend-model parent ctor signature. The
     * `...$parentArgs` variadic style breaks Magento's compiled
     * Interceptor subclasses, which forward parent ctor args BY POSITION
     * — Position 1 must be `Context`, not our custom dep. (Verified
     * 2026-05-15 via live setup:di:compile on VM 914.)
     *
     * Custom OST deps (`Json`) go AFTER the Magento ones so Interceptor's
     * positional forward fills the parent ctor cleanly.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Json $json,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->json = $json;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /** @var Json */
    protected $json;

    /**
     * Validate + serialize before persisting.
     */
    public function beforeSave(): self
    {
        $value = $this->getValue();

        // Path 1: JSON string from the textarea UI
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                $this->setValue('');
                parent::beforeSave();
                return $this;
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
            parent::beforeSave();
            return $this;
        }

        // Path 2: dynamic-rows widget post (future v1.3.1+)
        if (!is_array($value)) {
            $this->setValue('');
            parent::beforeSave();
            return $this;
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
                $invalid[] = sprintf('row "%s": tax class id must be a positive integer (got "%s")', (string)$rowKey, $taxClassId);
                continue;
            }
            if (!in_array($ostCategory, $valid, true)) {
                $invalid[] = sprintf('row "%s": "%s" is not a valid OST category', (string)$rowKey, $ostCategory);
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
        parent::beforeSave();
        return $this;
    }

    /**
     * Validate and normalize a decoded JSON object into the flat
     * `tax_class_id => ost_category` shape used for storage.
     *
     * @param array<int|string, mixed> $decoded
     * @return array<int|string, string>
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
}
