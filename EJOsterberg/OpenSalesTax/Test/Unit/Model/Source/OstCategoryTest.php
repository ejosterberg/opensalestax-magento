<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model\Source;

use EJOsterberg\OpenSalesTax\Model\Source\OstCategory;
use PHPUnit\Framework\TestCase;

final class OstCategoryTest extends TestCase
{
    public function testGetValidValuesContainsCanonicalVocabulary(): void
    {
        $valid = OstCategory::getValidValues();
        self::assertContains('general', $valid);
        self::assertContains('clothing', $valid);
        self::assertContains('groceries', $valid);
        self::assertContains('prescription_drugs', $valid);
        self::assertContains('prepared_food', $valid);
        self::assertContains('digital_goods', $valid);
        self::assertContains('', $valid, 'empty string represents the skip-line sentinel');
        self::assertCount(7, $valid);
    }

    public function testValidValuesMatchAdr005Vocabulary(): void
    {
        // ADR-005 in opensalestax-vendure locks this exact 7-value enum
        // across the portfolio. Any drift here means the engine's per-state
        // rate tables get inconsistent categories from this connector.
        $expected = [
            'general',
            'clothing',
            'groceries',
            'prescription_drugs',
            'prepared_food',
            'digital_goods',
            '',
        ];
        sort($expected);
        $actual = OstCategory::getValidValues();
        sort($actual);
        self::assertSame($expected, $actual);
    }
}
