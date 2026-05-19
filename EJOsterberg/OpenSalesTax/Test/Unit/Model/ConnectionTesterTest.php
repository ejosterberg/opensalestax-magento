<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model;

use EJOsterberg\OpenSalesTax\Model\ConnectionTester;
use EJOsterberg\OpenSalesTax\Model\OstaxClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectionTesterTest extends TestCase
{
    public function testHappyPathShapesSuccessEnvelope(): void
    {
        $client = $this->createMock(OstaxClient::class);
        $client->method('healthCheck')->willReturn([
            'ok'           => true,
            'version'      => '0.59.0',
            'db_connected' => true,
            'rtt_ms'       => 42,
        ]);

        $tester = new ConnectionTester($client, $this->createMock(LoggerInterface::class));
        $envelope = $tester->test();

        self::assertTrue($envelope['ok']);
        self::assertArrayHasKey('message', $envelope);
        self::assertStringContainsString('0.59.0', $envelope['message']);
        self::assertStringContainsString('connected', $envelope['message']);
        self::assertStringContainsString('42 ms', $envelope['message']);
    }

    public function testReportsDbDisconnectedDistinctly(): void
    {
        $client = $this->createMock(OstaxClient::class);
        $client->method('healthCheck')->willReturn([
            'ok'           => true,
            'version'      => '0.59.0',
            'db_connected' => false,
            'rtt_ms'       => 7,
        ]);

        $tester = new ConnectionTester($client, $this->createMock(LoggerInterface::class));
        $envelope = $tester->test();

        self::assertTrue($envelope['ok']);
        self::assertStringContainsString('disconnected', $envelope['message']);
    }

    public function testFailureBubblesErrorString(): void
    {
        $client = $this->createMock(OstaxClient::class);
        $client->method('healthCheck')->willReturn([
            'ok'           => false,
            'version'      => '',
            'db_connected' => false,
            'rtt_ms'       => 100,
            'error'        => 'HTTP 500',
        ]);

        $tester = new ConnectionTester($client, $this->createMock(LoggerInterface::class));
        $envelope = $tester->test();

        self::assertFalse($envelope['ok']);
        self::assertSame('HTTP 500', $envelope['error']);
    }

    public function testUnconfiguredEngineReportsAsError(): void
    {
        $client = $this->createMock(OstaxClient::class);
        $client->method('healthCheck')->willReturn([
            'ok'           => false,
            'version'      => '',
            'db_connected' => false,
            'rtt_ms'       => 0,
            'error'        => 'API URL is not configured',
        ]);

        $tester = new ConnectionTester($client, $this->createMock(LoggerInterface::class));
        $envelope = $tester->test();

        self::assertFalse($envelope['ok']);
        self::assertStringContainsString('not configured', $envelope['error']);
    }

    public function testMissingErrorFieldFallsBackToDefault(): void
    {
        $client = $this->createMock(OstaxClient::class);
        // Simulate an unexpected response shape — no `error` key but `ok` is false.
        $client->method('healthCheck')->willReturn([
            'ok' => false,
        ]);

        $tester = new ConnectionTester($client, $this->createMock(LoggerInterface::class));
        $envelope = $tester->test();

        self::assertFalse($envelope['ok']);
        self::assertSame('unknown error', $envelope['error']);
    }
}
