<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

use SevaCode\ClickHouseClient\ChcException;

/**
 * A complete HTTP response with status >= 300 and no ClickHouse error code in the body, usually
 * from something in front of ClickHouse: 502/503 from a proxy, 401/403 from proxy auth, 404 for a
 * wrong URL path. getHttpStatus() is always set, getCode() is 0. Read-only requests with 502, 503
 * or 504 are retried by withRetries().
 */
class ChcHttpException extends ChcException
{
}
