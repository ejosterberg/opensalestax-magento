<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

use Psr\Log\LoggerInterface;

/**
 * Service object that powers the admin "Test Connection" button.
 *
 * Delegates the actual probe to `OstaxClient::healthCheck()` (which already
 * exists and never throws). This class shapes the result into the JSON
 * envelope the admin controller returns to the browser.
 *
 * Why a separate service from the controller? Magento's `Backend\App\Action`
 * pulls in a heavy DI graph (Context, request, response, sessions, ACL,
 * URL builder...) that's painful to stub in unit tests. The controller
 * stays a thin XML-glue wrapper and the testable logic lives here.
 */
class ConnectionTester
{
    public function __construct(
        private readonly OstaxClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Probe the engine and return a JSON-ready envelope.
     *
     * @return array{ok: bool, message?: string, error?: string}
     */
    public function test(): array
    {
        $result = $this->client->healthCheck();

        if (!empty($result['ok'])) {
            $message = sprintf(
                'Engine v%s reachable — database %s (RTT %d ms)',
                $result['version'] !== '' ? $result['version'] : 'unknown',
                !empty($result['db_connected']) ? 'connected' : 'disconnected',
                $result['rtt_ms']
            );
            $this->logger->info('opensalestax: test connection ok', [
                'version' => $result['version'],
                'rtt_ms'  => $result['rtt_ms'],
            ]);
            return ['ok' => true, 'message' => $message];
        }

        $error = (string)($result['error'] ?? 'unknown error');
        $this->logger->warning('opensalestax: test connection failed', ['error' => $error]);
        return ['ok' => false, 'error' => $error];
    }
}
