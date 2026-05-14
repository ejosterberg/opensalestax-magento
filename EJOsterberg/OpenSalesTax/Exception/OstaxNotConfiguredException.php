<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Exception;

/**
 * The module's admin config is missing required fields (typically `api_url`).
 *
 * The QuoteTotalsTaxPlugin short-circuits before calling the client when this
 * would be the case; the client raises this only as a defensive guard for
 * direct callers.
 */
class OstaxNotConfiguredException extends OstaxEngineException
{
}
