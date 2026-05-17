<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Reader for the module's admin settings.
 *
 * Wraps Magento's `core_config_data` so the rest of the module never touches
 * raw config paths. Keeps the API token decrypt step localised here so it
 * cannot leak into a log message by accident.
 */
class Config
{
    public const PATH_API_URL = 'osstax/general/api_url';
    public const PATH_API_TOKEN = 'osstax/general/api_token';
    public const PATH_FAIL_HARD = 'osstax/general/fail_hard';
    public const PATH_RESTRICT_TO_PUBLIC_IPS = 'osstax/general/restrict_to_public_ips';
    public const PATH_PINNED_IP = 'osstax/general/api_url_pinned_ip';
    public const PATH_CATEGORY_MAPPING = 'osstax/category_mapping/mapping';

    /** Default OST category when a line's tax class is unmapped (per ADR-005). */
    public const DEFAULT_CATEGORY = 'general';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    /**
     * Returns the configured OST engine base URL, or empty string if unset.
     */
    public function getApiUrl(?string $scopeCode = null): string
    {
        $raw = $this->scopeConfig->getValue(
            self::PATH_API_URL,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        return is_string($raw) ? rtrim($raw, '/') : '';
    }

    /**
     * Returns the decrypted API token (empty string if unset).
     *
     * Never log the return value of this method.
     */
    public function getApiToken(?string $scopeCode = null): string
    {
        $raw = $this->scopeConfig->getValue(
            self::PATH_API_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        return $this->encryptor->decrypt($raw);
    }

    /**
     * Whether the merchant has opted into fail-hard behavior.
     *
     * Default (constitution Â§8) is false â€” the module falls back to Magento's
     * built-in tax tables when the engine is unreachable.
     */
    public function isFailHard(?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_FAIL_HARD,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    /**
     * True when admin has supplied an engine URL. Used as a quick on/off probe
     * so the module is inert in a default install until the merchant configures it.
     */
    public function isConfigured(?string $scopeCode = null): bool
    {
        return $this->getApiUrl($scopeCode) !== '';
    }

    /**
     * Whether the admin has opted into rejecting engine URLs that resolve to
     * a private or reserved IP range. Defaults to false because the supported
     * pattern is merchant-self-hosted on the same VM as Magento.
     */
    public function isRestrictToPublicIps(?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_RESTRICT_TO_PUBLIC_IPS,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    /**
     * Returns the pinned IP the backend_model captured at save time, or empty
     * string if no pin is in place. Used by `OstaxClient` to force `CURLOPT_RESOLVE`
     * so the runtime cURL connection bypasses DNS â€” defends against DNS rebinding.
     */
    public function getPinnedIp(?string $scopeCode = null): string
    {
        $raw = $this->scopeConfig->getValue(
            self::PATH_PINNED_IP,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        return is_string($raw) ? $raw : '';
    }

    /**
     * Returns the merchant-configured map of `magento_tax_class_id` to OST
     * category. Returns an empty array when unset, malformed, or the stored
     * value is not a JSON object â€” fail-soft, never throws.
     *
     * @return array<int, string>
     */
    public function getCategoryMapping(?string $scopeCode = null): array
    {
        $raw = $this->scopeConfig->getValue(
            self::PATH_CATEGORY_MAPPING,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $taxClassId => $ostCategory) {
            if (!is_string($ostCategory)) {
                continue;
            }
            $id = (int)$taxClassId;
            if ($id <= 0) {
                continue;
            }
            $out[$id] = $ostCategory;
        }
        return $out;
    }

    /**
     * Resolve the OST category to send for a given Magento product tax class.
     * Falls back to `DEFAULT_CATEGORY` ('general') when unmapped.
     *
     * Hot path â€” called for every line on every quote-total recompute.
     * Callers should cache the mapping array once per request lifecycle
     * via `getCategoryMapping()` rather than calling this method
     * repeatedly inside a tight loop.
     */
    public function resolveCategory(int $taxClassId, ?string $scopeCode = null): string
    {
        $mapping = $this->getCategoryMapping($scopeCode);
        return $mapping[$taxClassId] ?? self::DEFAULT_CATEGORY;
    }
}
