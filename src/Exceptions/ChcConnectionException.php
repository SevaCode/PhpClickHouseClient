<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Exceptions;

use SevaCode\ClickHouseClient\ChcException;

/**
 * No complete HTTP response: connection refused, DNS or TLS failure, connection broken while
 * the response was being read. It is unknown whether the server received the request.
 * getHttpStatus() is null, or the status of a partly received response.
 *
 * Read-only requests are retried by withRetries(), except ChcClientTimeoutException.
 * An HTTP error response is ChcHttpException, not this class.
 */
class ChcConnectionException extends ChcException
{
    /**
     * Connection failure without an underlying Symfony exception.
     */
    public static function fromMessage(string $message, ?string $query = null, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        if (null !== $query) {
            $e->setQuery($query);
        }
        return $e;
    }
}
