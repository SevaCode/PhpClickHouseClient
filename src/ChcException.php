<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Exceptions\ChcClientTimeoutException;
use SevaCode\ClickHouseClient\Exceptions\ChcHttpException;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcServerException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Base class of every exception thrown by the client; `catch (ChcException)` catches them all.
 *
 *     ChcException                          other failures, e.g. a malformed 200 response
 *     ├── ChcServerException                ClickHouse returned an error (Code: N)
 *     │   ├── ChcMemoryLimitExceededException   241
 *     │   └── ChcTimeoutExceededException       159, server-side timeout
 *     ├── ChcConnectionException            no complete HTTP response (network, TLS, broken connection)
 *     │   └── ChcClientTimeoutException     HTTP client timeout
 *     └── ChcHttpException                  HTTP status >= 300 without a ClickHouse error code
 *
 * getCode() is the ClickHouse error code or 0; getHttpStatus() is the HTTP status.
 */
class ChcException extends \Exception
{
    private ?string $query = null;
    private ?int $httpStatus = null;
    private ?int $clickHouseErrorCode = null;

    /**
     * Builds the most specific exception: client timeout, then ClickHouse error code
     * (from `Code: N` or the error name), then transport failure, then HTTP status >= 300.
     *
     * @param int|string $code HTTP status of the response, 0 if there was none
     * @param ?\Throwable $previous the Symfony HttpClient exception, if any
     * @param ?string $query SQL that failed, available through getQuery()
     */
    public static function create(string $message = '', int|string $code = 0, ?\Throwable $previous = null, ?string $query = null): self
    {
        $httpStatus = is_numeric($code) && $code >= 100 && $code <= 599 ? (int)$code : null;
        $clickHouseErrorCode = null;

        // Code: N in the response body is more specific than the HTTP status.
        // Формат подтверждён getExceptionMessageAndPattern() в ClickHouse (src/Common/Exception.cpp):
        // https://github.com/ClickHouse/ClickHouse/blob/master/src/Common/Exception.cpp
        // Code example: stream << "Code: " << e.code() << ". " << text;
        // Это best-effort поиск: Code: N должен быть в начале сообщения или строки (допустим отступ);
        // сама регулярка не доказывает, что найденная строка является ошибкой ClickHouse.
        if (preg_match('/^[\t ]*Code:[\t ]*(\d+)\b/m', $message, $match)) {
            $code = $clickHouseErrorCode = (int)$match[1];
        } elseif (!($previous instanceof TimeoutExceptionInterface)) {
            // Запасной поиск по названию: только без Code: N и НЕ для клиентского Symfony-таймаута.
            // Для клиентского Symfony-таймаута сохраняем переданный код:
            // таймаут HTTP-клиента не означает серверную ошибку ClickHouse с кодом 159.
            if (str_contains($message, 'MEMORY_LIMIT_EXCEEDED')) {
                $code = $clickHouseErrorCode = 241;
            } elseif (str_contains($message, 'TIMEOUT_EXCEEDED')) {
                $code = $clickHouseErrorCode = 159;
            }
        }

        $exceptionCode = $clickHouseErrorCode ?? 0;
        if ($previous instanceof TimeoutExceptionInterface) {
            $e = new ChcClientTimeoutException($message, $exceptionCode, $previous);
        } elseif (null !== $clickHouseErrorCode) {
            if (159 === $clickHouseErrorCode) {
                $e = new ChcTimeoutExceededException($message, $exceptionCode, $previous);
            } elseif (241 === $clickHouseErrorCode) {
                $e = new ChcMemoryLimitExceededException($message, $exceptionCode, $previous);
            } else {
                $e = new ChcServerException($message, $exceptionCode, $previous);
            }
        } elseif ($previous instanceof TransportExceptionInterface) {
            $e = new ChcConnectionException($message, $exceptionCode, $previous);
        } elseif (null !== $httpStatus && $httpStatus >= 300) {
            $e = new ChcHttpException($message, $exceptionCode, $previous);
        } else {
            $e = new self($message, $exceptionCode, $previous);
        }

        if ($query !== null) {
            $e->setQuery($query);
        }
        $e->httpStatus = $httpStatus;
        $e->clickHouseErrorCode = $clickHouseErrorCode;

        return $e;
    }

    /**
     * HTTP status of the response (200 for errors sent mid-stream), null — no response.
     * getCode() returns a ClickHouse error code or 0, never this status.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * ClickHouse error code ('Code: N.' in the message), null — not a ClickHouse error.
     * getCode() returns this value or 0; use getHttpStatus() for the HTTP status.
     */
    public function getClickHouseErrorCode(): ?int
    {
        return $this->clickHouseErrorCode;
    }

    /**
     * SQL of the failed request, null when unknown.
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(string $query): void
    {
        $this->query = $query;
    }
}