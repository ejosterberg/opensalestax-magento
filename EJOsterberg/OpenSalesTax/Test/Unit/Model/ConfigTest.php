<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetApiUrlReturnsTrimmedValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_API_URL)
            ->willReturn('https://ost.example.com/');

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertSame('https://ost.example.com', $config->getApiUrl());
    }

    public function testGetApiUrlReturnsEmptyStringWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertSame('', $config->getApiUrl());
    }

    public function testGetApiTokenDecryptsStoredValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('encrypted-blob');

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with('encrypted-blob')
            ->willReturn('plaintext-token');

        $config = new Config($scopeConfig, $encryptor, $this->createMock(Json::class));

        self::assertSame('plaintext-token', $config->getApiToken());
    }

    public function testGetApiTokenSkipsDecryptionWhenEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $config = new Config($scopeConfig, $encryptor, $this->createMock(Json::class));

        self::assertSame('', $config->getApiToken());
    }

    public function testIsFailHardReadsFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with(Config::PATH_FAIL_HARD)
            ->willReturn(true);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertTrue($config->isFailHard());
    }

    public function testIsConfiguredTracksApiUrl(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnOnConsecutiveCalls('https://ost.example.com', '');

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertTrue($config->isConfigured());
        self::assertFalse($config->isConfigured());
    }

    public function testIsRestrictToPublicIpsReadsFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->willReturnMap([
                [Config::PATH_RESTRICT_TO_PUBLIC_IPS, 'store', null, true],
            ]);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertTrue($config->isRestrictToPublicIps());
    }

    public function testGetPinnedIpReturnsStoredValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_PINNED_IP)
            ->willReturn('8.8.8.8');

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertSame('8.8.8.8', $config->getPinnedIp());
    }

    public function testGetPinnedIpReturnsEmptyStringWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertSame('', $config->getPinnedIp());
    }

    public function testGetCategoryMappingReturnsEmptyArrayWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_CATEGORY_MAPPING)
            ->willReturn(null);
        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $this->createMock(Json::class));

        self::assertSame([], $config->getCategoryMapping());
    }

    public function testGetCategoryMappingReturnsEmptyArrayOnMalformedJson(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_CATEGORY_MAPPING)
            ->willReturn('not json');
        $json = $this->createMock(Json::class);
        $json->method('unserialize')->willThrowException(new \InvalidArgumentException('bad json'));
        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $json);

        self::assertSame([], $config->getCategoryMapping());
    }

    public function testGetCategoryMappingFiltersBadEntries(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_CATEGORY_MAPPING)
            ->willReturn('{"2":"clothing","0":"general","7":"groceries","x":"digital_goods"}');
        $json = $this->createMock(Json::class);
        $json->method('unserialize')->willReturn(['2' => 'clothing', '0' => 'general', '7' => 'groceries', 'x' => 'digital_goods']);
        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $json);

        $result = $config->getCategoryMapping();
        // 0 and 'x' should be dropped (non-positive int / non-numeric)
        self::assertArrayHasKey(2, $result);
        self::assertSame('clothing', $result[2]);
        self::assertArrayHasKey(7, $result);
        self::assertSame('groceries', $result[7]);
        self::assertArrayNotHasKey(0, $result);
    }

    public function testResolveCategoryReturnsMappedValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_CATEGORY_MAPPING)
            ->willReturn('{"2":"clothing"}');
        $json = $this->createMock(Json::class);
        $json->method('unserialize')->willReturn(['2' => 'clothing']);
        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $json);

        self::assertSame('clothing', $config->resolveCategory(2));
    }

    public function testResolveCategoryFallsBackToGeneralForUnmappedClass(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_CATEGORY_MAPPING)
            ->willReturn('{"2":"clothing"}');
        $json = $this->createMock(Json::class);
        $json->method('unserialize')->willReturn(['2' => 'clothing']);
        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class), $json);

        self::assertSame('general', $config->resolveCategory(99));
    }
}
