<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

/**
 * ClickHouse error 241 MEMORY_LIMIT_EXCEEDED: the query exceeded max_memory_usage or a server limit.
 */
class ChcMemoryLimitExceededException extends ChcServerException
{
}
