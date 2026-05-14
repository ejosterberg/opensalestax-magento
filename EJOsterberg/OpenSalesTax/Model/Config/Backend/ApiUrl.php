<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Model\Config\Backend;

use EJOsterberg\OpenSalesTax\Model\Validator\ApiUrlValidator;
use InvalidArgumentException;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for `osstax/general/api_url`.
 *
 * Server-side re-validation of the URL the admin enters. The frontend
 * `validate-url` class already runs in the browser, but a determined admin
 * can bypass JS — this is the defense-in-depth layer.
 *
 * The `restrict_to_public_ips` sibling field is read from the same admin
 * form submission (NOT from `core_config_data`) so the new value the admin
 * is about to save takes precedence over the persisted one.
 */
class ApiUrl extends Value
{
    private const SIBLING_RESTRICT_TO_PUBLIC_IPS = 'restrict_to_public_ips';

    /**
     * @throws LocalizedException When the URL is malformed, uses the wrong
     *     scheme, or — with the restrict-to-public-IPs flag on — resolves to
     *     a private / reserved IP.
     */
    public function beforeSave(): self
    {
        $value = (string)$this->getValue();
        $validator = new ApiUrlValidator($this->isRestrictToPublicIpsBeingSaved());
        try {
            $validator->validate($value);
        } catch (InvalidArgumentException $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        parent::beforeSave();
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
