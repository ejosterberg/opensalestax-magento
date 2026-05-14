<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Config\Backend\ApiUrl;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApiUrlTest extends TestCase
{
    private WriterInterface&MockObject $writer;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(WriterInterface::class);
    }

    public function testValidUrlPasses(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('https://ost.example.com');

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }

    public function testEmptyValuePasses(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('');

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }

    public function testMalformedUrlThrowsLocalizedException(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('not a url');

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testWrongSchemeThrowsLocalizedException(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('ftp://ost.example.com');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/http or https/');
        $model->beforeSave();
    }

    public function testRestrictFlagFromSiblingFieldIsRespected(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('http://192.168.1.1:8080');
        $model->setFieldsetDataValue('restrict_to_public_ips', '1');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/public IP/');
        $model->beforeSave();
    }

    public function testRestrictFlagDefaultsOffWhenSiblingMissing(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('http://192.168.1.1:8080');

        $model->beforeSave();

        $this->expectNotToPerformAssertions();
    }

    public function testAfterSaveClearsPinWhenRestrictWasOff(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('https://ost.example.com');
        $model->setScope('default');
        $model->setScopeId(0);

        $this->writer->expects(self::once())
            ->method('delete')
            ->with(ApiUrl::PATH_PINNED_IP, 'default', 0);
        $this->writer->expects(self::never())->method('save');

        $model->beforeSave();
        $model->afterSave();
    }

    public function testAfterSaveClearsPinForEmptyUrl(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('');

        $this->writer->expects(self::once())->method('delete');
        $this->writer->expects(self::never())->method('save');

        $model->beforeSave();
        $model->afterSave();
    }

    public function testAfterSavePinsResolvedIpWhenRestrictWasOn(): void
    {
        // Use a literal IP URL so we avoid real DNS and the validator can
        // skip its host resolver entirely.
        $model = new ApiUrl($this->writer);
        $model->setValue('http://8.8.8.8:8080');
        $model->setFieldsetDataValue('restrict_to_public_ips', '1');
        $model->setScope('default');
        $model->setScopeId(0);

        $this->writer->expects(self::once())
            ->method('save')
            ->with(ApiUrl::PATH_PINNED_IP, '8.8.8.8', 'default', 0);
        $this->writer->expects(self::never())->method('delete');

        $model->beforeSave();
        $model->afterSave();
    }

    public function testAfterSaveScopesPinToWebsiteWhenSet(): void
    {
        $model = new ApiUrl($this->writer);
        $model->setValue('http://8.8.8.8:8080');
        $model->setFieldsetDataValue('restrict_to_public_ips', '1');
        $model->setScope('websites');
        $model->setScopeId(2);

        $this->writer->expects(self::once())
            ->method('save')
            ->with(ApiUrl::PATH_PINNED_IP, '8.8.8.8', 'websites', 2);

        $model->beforeSave();
        $model->afterSave();
    }
}
