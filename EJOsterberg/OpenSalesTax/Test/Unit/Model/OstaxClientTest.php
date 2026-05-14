<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Exception\OstaxEngineUnreachableException;
use EJOsterberg\OpenSalesTax\Exception\OstaxMalformedResponseException;
use EJOsterberg\OpenSalesTax\Exception\OstaxNotConfiguredException;
use EJOsterberg\OpenSalesTax\Model\Config;
use EJOsterberg\OpenSalesTax\Model\OstaxClient;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OstaxClientTest extends TestCase
{
    public function testCalculateHappyPath(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode([
            'tax_total' => 8.0,
            'lines'     => [['line_id' => '1', 'tax' => 8.0, 'rate' => 0.08]],
        ]) ?: '{}');
        $curl->expects(self::once())->method('post');

        $json = new Json();
        $config = $this->configMock('https://ost.example.com', '');

        $client = new OstaxClient($curl, $json, $config, $this->createMock(LoggerInterface::class));

        $response = $client->calculate(['lines' => []]);

        self::assertSame(8.0, $response->taxTotal);
    }

    public function testCalculateThrowsOnNon200(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(503);
        $curl->method('getBody')->willReturn('');

        $config = $this->configMock('https://ost.example.com', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));

        $this->expectException(OstaxEngineUnreachableException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');
        $client->calculate(['lines' => []]);
    }

    public function testCalculateThrowsOnUnconfiguredUrl(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects(self::never())->method('post');

        $config = $this->configMock('', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));

        $this->expectException(OstaxNotConfiguredException::class);
        $client->calculate(['lines' => []]);
    }

    public function testCalculateThrowsOnMalformedBody(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('"not an object"');

        $config = $this->configMock('https://ost.example.com', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));

        $this->expectException(OstaxMalformedResponseException::class);
        $client->calculate([]);
    }

    public function testCalculateSendsBearerTokenWhenConfigured(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"tax_total": 0, "lines": []}');
        $capturedHeaders = null;
        $curl->method('setHeaders')->willReturnCallback(function ($headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
        });

        $config = $this->configMock('https://ost.example.com', 'secret-token');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));
        $client->calculate(['lines' => []]);

        self::assertIsArray($capturedHeaders);
        self::assertArrayHasKey('Authorization', $capturedHeaders);
        self::assertSame('Bearer secret-token', $capturedHeaders['Authorization']);
    }

    public function testHealthCheckHappyPath(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode([
            'status'             => 'ok',
            'version'            => '0.55.4',
            'database_connected' => true,
        ]) ?: '{}');

        $config = $this->configMock('https://ost.example.com', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));
        $result = $client->healthCheck();

        self::assertTrue($result['ok']);
        self::assertSame('0.55.4', $result['version']);
        self::assertTrue($result['db_connected']);
    }

    public function testHealthCheckReportsUnconfigured(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects(self::never())->method('get');

        $config = $this->configMock('', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));
        $result = $client->healthCheck();

        self::assertFalse($result['ok']);
        self::assertArrayHasKey('error', $result);
    }

    public function testHealthCheckReportsNon200(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(500);
        $curl->method('getBody')->willReturn('');

        $config = $this->configMock('https://ost.example.com', '');

        $client = new OstaxClient($curl, new Json(), $config, $this->createMock(LoggerInterface::class));
        $result = $client->healthCheck();

        self::assertFalse($result['ok']);
        self::assertSame('HTTP 500', $result['error'] ?? '');
    }

    /**
     * Lightweight Config stub since Config itself is `final` (cannot be mocked
     * with createMock) and Magento's encryptor mock is heavy.
     */
    private function configMock(string $apiUrl, string $apiToken): Config
    {
        return new class ($apiUrl, $apiToken) extends Config {
            public function __construct(private string $apiUrl, private string $apiToken)
            {
            }

            public function getApiUrl(?string $scopeCode = null): string
            {
                return $this->apiUrl;
            }

            public function getApiToken(?string $scopeCode = null): string
            {
                return $this->apiToken;
            }

            public function isFailHard(?string $scopeCode = null): bool
            {
                return false;
            }

            public function isConfigured(?string $scopeCode = null): bool
            {
                return $this->apiUrl !== '';
            }
        };
    }
}
