<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetApiUrlReturnsTrimmedValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::PATH_API_URL)
            ->willReturn('https://ost.example.com/');

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

        self::assertSame('https://ost.example.com', $config->getApiUrl());
    }

    public function testGetApiUrlReturnsEmptyStringWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

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

        $config = new Config($scopeConfig, $encryptor);

        self::assertSame('plaintext-token', $config->getApiToken());
    }

    public function testGetApiTokenSkipsDecryptionWhenEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $config = new Config($scopeConfig, $encryptor);

        self::assertSame('', $config->getApiToken());
    }

    public function testIsFailHardReadsFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with(Config::PATH_FAIL_HARD)
            ->willReturn(true);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

        self::assertTrue($config->isFailHard());
    }

    public function testIsConfiguredTracksApiUrl(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnOnConsecutiveCalls('https://ost.example.com', '');

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

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

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

        self::assertTrue($config->isRestrictToPublicIps());
    }
}
