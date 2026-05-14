<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Validator\ApiUrlValidator;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for `osstax/general/api_url`.
 *
 * Three layers of defense:
 *  1. **beforeSave** — server-side scheme + parse validation (defeats JS-disabled
 *     bypass of the frontend `validate-url` class).
 *  2. **beforeSave (conditional)** — when `restrict_to_public_ips` is on, the
 *     URL host must resolve to a non-private, non-reserved IP.
 *  3. **afterSave (conditional)** — when `restrict_to_public_ips` is on, the
 *     resolved IP is pinned to `osstax/general/api_url_pinned_ip` in the same
 *     scope. The OstaxClient reads that pin at request time and passes it to
 *     cURL via `CURLOPT_RESOLVE` so subsequent calls bypass DNS entirely —
 *     defends against DNS rebinding.
 *
 * The pin is cleared (1) when the URL is saved empty and (2) when
 * `restrict_to_public_ips` is off. Off-mode preserves the existing
 * "engine IP rotates" use case where pinning would break legitimate
 * operational moves.
 */
class ApiUrl extends Value
{
    public const PATH_PINNED_IP = 'osstax/general/api_url_pinned_ip';
    private const SIBLING_RESTRICT_TO_PUBLIC_IPS = 'restrict_to_public_ips';

    private ?string $resolvedIpForPinning = null;
    private bool $restrictWasOnAtSave = false;

    /**
     * @param WriterInterface $configWriter Magento's config writer; used in afterSave() to persist the resolved IP pin.
     * @param mixed ...$parentArgs Pass-through to the Magento\Framework\App\Config\Value parent constructor.
     */
    public function __construct(
        protected readonly WriterInterface $configWriter,
        ...$parentArgs
    ) {
        parent::__construct(...$parentArgs);
    }

    /**
     * @throws LocalizedException When the URL is malformed, uses the wrong
     *     scheme, or — with the restrict-to-public-IPs flag on — resolves to
     *     a private / reserved IP.
     */
    public function beforeSave(): self
    {
        $value = (string)$this->getValue();
        $this->restrictWasOnAtSave = $this->isRestrictToPublicIpsBeingSaved();
        $validator = new ApiUrlValidator($this->restrictWasOnAtSave);
        try {
            $this->resolvedIpForPinning = $validator->validate($value);
        } catch (InvalidArgumentException $e) {
            $this->resolvedIpForPinning = null;
            throw new LocalizedException(__($e->getMessage()));
        }

        parent::beforeSave();
        return $this;
    }

    /**
     * Persists the pinned IP to a sibling config path when the restrict flag
     * was on and the URL resolved cleanly. Clears the pin otherwise so a flag
     * flip from on → off does not leave a stale pin behind.
     */
    public function afterSave(): self
    {
        parent::afterSave();

        $scope = $this->getScope() ?: 'default';
        $scopeId = $this->getScopeId();

        if ($this->resolvedIpForPinning !== null && $this->restrictWasOnAtSave) {
            $this->configWriter->save(self::PATH_PINNED_IP, $this->resolvedIpForPinning, $scope, $scopeId);
        } else {
            $this->configWriter->delete(self::PATH_PINNED_IP, $scope, $scopeId);
        }

        return $this;
    }

    /**
     * Reads the sibling `restrict_to_public_ips` value from the SAME admin
     * form submission, so a user who is toggling the flag and changing the
     * URL in one save sees consistent validation.
     */
    private function isRestrictToPublicIpsBeingSaved(): bool
    {
        $raw = $this->getFieldsetDataValue(self::SIBLING_RESTRICT_TO_PUBLIC_IPS);
        if ($raw === null) {
            return false;
        }
        return (bool)$raw;
    }
}
