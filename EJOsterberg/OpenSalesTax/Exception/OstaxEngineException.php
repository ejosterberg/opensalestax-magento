<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Exception;

use RuntimeException;

/**
 * Base exception for all errors flowing out of the OST engine HTTP layer.
 *
 * Callers can catch this to apply the fail-soft policy uniformly; the more
 * specific subclasses exist so we can discriminate when needed (e.g. log
 * malformed-response separately from unreachable).
 */
class OstaxEngineException extends RuntimeException
{
}
