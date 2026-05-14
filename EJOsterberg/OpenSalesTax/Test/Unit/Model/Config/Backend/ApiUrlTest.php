<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Config\Backend\ApiUrl;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

final class ApiUrlTest extends TestCase
{
    public function testValidUrlPasses(): void
    {
        $model = new ApiUrl();
        $model->setValue('https://ost.example.com');

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }

    public function testEmptyValuePasses(): void
    {
        $model = new ApiUrl();
        $model->setValue('');

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }

    public function testMalformedUrlThrowsLocalizedException(): void
    {
        $model = new ApiUrl();
        $model->setValue('not a url');

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testWrongSchemeThrowsLocalizedException(): void
    {
        $model = new ApiUrl();
        $model->setValue('ftp://ost.example.com');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/http or https/');
        $model->beforeSave();
    }

    public function testRestrictFlagFromSiblingFieldIsRespected(): void
    {
        $model = new ApiUrl();
        $model->setValue('http://192.168.1.1:8080');
        $model->setFieldsetDataValue('restrict_to_public_ips', '1');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/public IP/');
        $model->beforeSave();
    }

    public function testRestrictFlagDefaultsOffWhenSiblingMissing(): void
    {
        $model = new ApiUrl();
        $model->setValue('http://192.168.1.1:8080');
        // No setFieldsetDataValue() call — sibling missing.

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }
}
