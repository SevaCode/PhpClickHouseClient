<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcClientTimeoutException;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Exceptions\ChcHttpException;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcServerException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;

class ChcExceptionTest extends TestCase
{
    #[DataProvider('createExceptionCases')]
    public function testCreateRoutesByClickHouseCodeAndHttpStatus(
        string $message,
        int $httpStatus,
        string $class,
        int $expectedCode,
        ?int $expectedHttpStatus,
        ?int $expectedClickHouseErrorCode,
    ): void
    {
        $e = $this->caughtException(
            static function () use ($message, $httpStatus) {
                throw ChcException::create($message, $httpStatus, query: 'query-context');
            },
            $class,
        );

        $this->assertSame($message, $e->getMessage());
        $this->assertSame($expectedCode, $e->getCode());
        $this->assertSame($expectedHttpStatus, $e->getHttpStatus());
        $this->assertSame($expectedClickHouseErrorCode, $e->getClickHouseErrorCode());
        $this->assertSame('query-context', $e->getQuery());
        if ($e instanceof ChcMemoryLimitExceededException || $e instanceof ChcTimeoutExceededException) {
            $this->assertInstanceOf(ChcServerException::class, $e);
        }
    }

    /**
     * @return iterable<string, array{string, int, class-string<ChcException>, int, ?int, ?int}>
     */
    public static function createExceptionCases(): iterable
    {
        yield 'server error with HTTP status' => [
            'Code: 395. DB::Exception: query failed',
            500,
            ChcServerException::class,
            395,
            500,
            395,
        ];
        yield 'memory limit' => ['Code: 241. DB::Exception: Memory limit exceeded', 500, ChcMemoryLimitExceededException::class, 241, 500, 241];
        yield 'server timeout' => ['Code: 159. DB::Exception: Timeout exceeded', 500, ChcTimeoutExceededException::class, 159, 500, 159];
        yield 'memory name fallback' => ['MEMORY_LIMIT_EXCEEDED', 500, ChcMemoryLimitExceededException::class, 241, 500, 241];
        yield 'timeout name fallback' => ['TIMEOUT_EXCEEDED', 500, ChcTimeoutExceededException::class, 159, 500, 159];
        yield 'HTTP error without ClickHouse code' => ['Bad gateway', 502, ChcHttpException::class, 0, 502, null];
        yield 'malformed successful response' => ['Malformed response', 200, ChcException::class, 0, 200, null];
        yield 'mid-stream server error' => [
            "partial output\nCode: 395. DB::Exception: query failed",
            200,
            ChcServerException::class,
            395,
            200,
            395,
        ];
    }

    public function testClientTimeoutIsAConnectionTimeoutNotAServerTimeout(): void
    {
        $previous = new TimeoutException('Request timed out');
        $e = $this->caughtException(
            static function () use ($previous) {
                throw ChcException::create($previous->getMessage(), previous: $previous, query: 'query-context');
            },
            ChcClientTimeoutException::class,
        );
        $this->assertInstanceOf(ChcConnectionException::class, $e);
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getHttpStatus());
        $this->assertNull($e->getClickHouseErrorCode());
        $this->assertSame('query-context', $e->getQuery());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testTransportExceptionHasNoClickHouseCodeOrHttpStatus(): void
    {
        $previous = new TransportException('Connection refused');
        $e = $this->caughtException(
            static function () use ($previous) {
                throw ChcException::create($previous->getMessage(), previous: $previous);
            },
            ChcConnectionException::class,
        );

        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getHttpStatus());
        $this->assertNull($e->getClickHouseErrorCode());
        $this->assertSame($previous, $e->getPrevious());
    }
}
