<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Validator\ApiUrlValidator;
use InvalidArgumentException;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

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
     * Use Magento's explicit backend-model parent ctor signature. The
     * `...$parentArgs` variadic style breaks Magento's compiled
     * Interceptor subclasses, which forward parent ctor args BY POSITION
     * — Position 1 must be `Context`, not our custom dep. (Verified
     * 2026-05-15 via live setup:di:compile on VM 914.)
     *
     * Custom OST deps (e.g. `WriterInterface`) go AFTER the Magento ones,
     * mirroring how OCA-style modules layer their deps onto the parent.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configWriter = $configWriter;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /** @var WriterInterface */
    protected $configWriter;

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
