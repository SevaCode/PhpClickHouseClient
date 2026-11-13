<?php

namespace SevaCode\ClickHouseClient\Exceptions;

use SevaCode\ClickHouseClient\ChcException;

/**
 * No HTTP response from ClickHouse: connection refused, DNS, TLS, broken connection.
 * Client-side timeouts are ChcTimeoutExceededException.
 */
class ChcConnectionException extends ChcException
{
    public static function fromMessage(string $message, ?string $query = null, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        if (null !== $query) {
            $e->setQuery($query);
        }
        return $e;
    }
}
