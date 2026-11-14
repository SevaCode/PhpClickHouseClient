<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

/**
 * The HTTP client timed out (withTimeout() or the injected client's timeout) before a complete
 * response arrived. Not retried. Server-side timeouts (code 159) are ChcTimeoutExceededException.
 */
class ChcClientTimeoutException extends ChcConnectionException
{
}
