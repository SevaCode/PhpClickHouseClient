<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

use SevaCode\ClickHouseClient\ChcException;

/**
 * ClickHouse returned an error (`Code: N. DB::Exception: …`), with HTTP 4xx/5xx or mid-stream
 * after HTTP 200. getCode() and getClickHouseErrorCode() return N, e.g. 60 for UNKNOWN_TABLE.
 *
 * @see https://github.com/ClickHouse/ClickHouse/blob/master/src/Common/ErrorCodes.cpp
 */
class ChcServerException extends ChcException
{
}
