<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Exception;

/**
 * Engine returned a 2xx HTTP status but the body did not parse as the expected JSON shape.
 *
 * Distinguishes from {@see OstaxEngineUnreachableException} so we can log the
 * two cases at different severities.
 */
class OstaxMalformedResponseException extends OstaxEngineException
{
}
