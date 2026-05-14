<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Validator;

use InvalidArgumentException;

/**
 * Server-side validator for the `osstax/general/api_url` admin field.
 *
 * Two checks always run:
 *  1. The value parses as a URL with both scheme and host present.
 *  2. The scheme is `http` or `https`.
 *
 * One optional check, gated by the `restrictToPublicIps` flag:
 *  3. The host resolves to a non-private, non-reserved IP.
 *
 * The optional check is opt-in (defaults to off) because the supported
 * deployment pattern is merchant-self-hosted OST on the same VM as Magento
 * (often `http://10.x.x.x:8080` or `http://localhost:8080`). Merchants who
 * point at an external engine and want defense-in-depth against an admin
 * pasting an arbitrary URL enable the flag.
 *
 * **Caveats** (documented for `specs/security/`):
 *  - DNS rebinding is NOT prevented. Save-time IP resolution can return a
 *    public address while request-time resolution returns a private one.
 *    A full mitigation would require pinning the resolved IP, which is out
 *    of scope for v1.1.
 *  - Empty value is considered valid; that is the "module disabled" state.
 */
class ApiUrlValidator
{
    /**
     * @param callable(string):?string $hostResolver Function that returns the
     *     resolved IPv4/IPv6 address for a hostname, or null on failure. The
     *     default uses `gethostbyname`; tests pass a deterministic mock.
     */
    public function __construct(
        private readonly bool $restrictToPublicIps,
        private $hostResolver = null
    ) {
        if ($this->hostResolver === null) {
            $this->hostResolver = static function (string $host): ?string {
                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return $host;
                }
                $resolved = gethostbyname($host);
                return $resolved === $host ? null : $resolved;
            };
        }
    }

    /**
     * @throws InvalidArgumentException with a human-readable message. The
     *     backend_model translates this to a Magento LocalizedException.
     */
    public function validate(string $url): void
    {
        if ($url === '') {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(
                'The Engine API URL must be a fully-qualified URL (e.g. https://ost.example.com).'
            );
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'The Engine API URL must use http or https.'
            );
        }

        if ($this->restrictToPublicIps) {
            $this->validatePublicHost($parts['host']);
        }
    }

    /**
     * @throws InvalidArgumentException When the host fails to resolve or
     *     resolves to a private / reserved IP.
     */
    private function validatePublicHost(string $host): void
    {
        $ip = ($this->hostResolver)($host);
        if ($ip === null) {
            throw new InvalidArgumentException(
                'The Engine API URL host could not be resolved.'
            );
        }
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($isPublic === false) {
            throw new InvalidArgumentException(
                'The Engine API URL must resolve to a public IP when "Restrict to public IPs" is enabled.'
            );
        }
    }
}
