<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

/**
 * ClickHouse error 159 TIMEOUT_EXCEEDED: the query ran longer than max_execution_time on the server.
 * HTTP client timeouts are ChcClientTimeoutException.
 */
class ChcTimeoutExceededException extends ChcServerException
{
}
