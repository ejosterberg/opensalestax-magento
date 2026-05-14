<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Exception;

/**
 * Engine returned a non-2xx HTTP status (or the call did not complete).
 *
 * Carries the HTTP status when available; defaults to 0 if the call never reached
 * the engine.
 */
class OstaxEngineUnreachableException extends OstaxEngineException
{
}
