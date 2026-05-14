<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HTTP client for the OpenSalesTax engine v1 API.
 *
 * Wraps Magento's native cURL helper to call exactly two endpoints:
 * - POST /v1/calculate (per-quote tax computation)
 * - GET  /v1/health   (Test Connection probe)
 *
 * Authentication: if a token is configured, we send it as a Bearer header.
 * TLS verification is left at Curl defaults (peer-verify on).
 *
 * Failure model (constitution §8): this class throws on any non-2xx
 * response. The caller decides whether to fail-soft (catch + fall back to
 * Magento's built-in calc) or fail-hard (rethrow, block checkout).
 *
 * Logging: only structured metadata (no full payloads — they carry
 * customer addresses). The api_token is never logged.
 */
class OstaxClient
{
    private const ENDPOINT_CALCULATE = '/v1/calculate';
    private const ENDPOINT_HEALTH = '/v1/health';
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Call POST /v1/calculate. Returns the decoded response as a value object.
     *
     * @param array<string, mixed> $payload Engine-shaped request body.
     * @throws RuntimeException When the engine is unreachable or returns non-200.
     */
    public function calculate(array $payload): OstaxResponse
    {
        $url = $this->requireApiUrl() . self::ENDPOINT_CALCULATE;
        $start = microtime(true);

        $this->configureCurl();
        $this->curl->post($url, $this->json->serialize($payload));

        $status = $this->curl->getStatus();
        $rttMs = (int)round((microtime(true) - $start) * 1000);

        if ($status !== 200) {
            $this->logger->error('opensalestax: engine /v1/calculate failed', [
                'http_status' => $status,
                'rtt_ms'      => $rttMs,
            ]);
            throw new RuntimeException(
                sprintf('OST engine returned HTTP %d on /v1/calculate', $status)
            );
        }

        $decoded = $this->json->unserialize($this->curl->getBody());
        if (!is_array($decoded)) {
            $this->logger->error('opensalestax: engine returned non-array body', [
                'http_status' => $status,
                'rtt_ms'      => $rttMs,
            ]);
            throw new RuntimeException('OST engine returned malformed JSON body');
        }

        $this->logger->info('opensalestax: engine /v1/calculate ok', [
            'http_status' => $status,
            'rtt_ms'      => $rttMs,
            'line_count'  => is_array($decoded['lines'] ?? null) ? count($decoded['lines']) : 0,
        ]);

        return OstaxResponse::fromArray($decoded);
    }

    /**
     * Probe the engine. Returns a structured status dict; never throws.
     *
     * @return array{ok: bool, version: string, db_connected: bool, rtt_ms: int, error?: string}
     */
    public function healthCheck(): array
    {
        $url = $this->config->getApiUrl();
        if ($url === '') {
            return [
                'ok'           => false,
                'version'      => '',
                'db_connected' => false,
                'rtt_ms'       => 0,
                'error'        => 'API URL is not configured',
            ];
        }

        $start = microtime(true);
        try {
            $this->configureCurl();
            $this->curl->get($url . self::ENDPOINT_HEALTH);
        } catch (\Throwable $e) {
            return [
                'ok'           => false,
                'version'      => '',
                'db_connected' => false,
                'rtt_ms'       => (int)round((microtime(true) - $start) * 1000),
                'error'        => 'transport error',
            ];
        }

        $rttMs = (int)round((microtime(true) - $start) * 1000);
        $status = $this->curl->getStatus();
        if ($status !== 200) {
            return [
                'ok'           => false,
                'version'      => '',
                'db_connected' => false,
                'rtt_ms'       => $rttMs,
                'error'        => sprintf('HTTP %d', $status),
            ];
        }

        $decoded = $this->json->unserialize($this->curl->getBody());
        if (!is_array($decoded)) {
            return [
                'ok'           => false,
                'version'      => '',
                'db_connected' => false,
                'rtt_ms'       => $rttMs,
                'error'        => 'malformed JSON',
            ];
        }

        return [
            'ok'           => ($decoded['status'] ?? '') === 'ok',
            'version'      => (string)($decoded['version'] ?? ''),
            'db_connected' => (bool)($decoded['database_connected'] ?? false),
            'rtt_ms'       => $rttMs,
        ];
    }

    /**
     * Apply headers + timeout to the Curl instance. Called before every request.
     */
    private function configureCurl(): void
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $token = $this->config->getApiToken();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->curl->setHeaders($headers);
        $this->curl->setTimeout(self::DEFAULT_TIMEOUT_SECONDS);
    }

    private function requireApiUrl(): string
    {
        $url = $this->config->getApiUrl();
        if ($url === '') {
            throw new RuntimeException('OST engine API URL is not configured');
        }
        return $url;
    }
}
