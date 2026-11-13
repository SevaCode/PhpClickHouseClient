<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;

class ChcExceptionTest extends TestCase
{
    public function testServerCodesSymbolicFallbacksAndHttpFallback(): void
    {
        $query = 'query-context';
        $previous = new \RuntimeException('original');
        $cases = [
            ['Code: 60. DB::Exception: Unknown table', 500, ChcException::class, 60],
            ["Code: 60. DB::Exception: Unknown table\nTIMEOUT_EXCEEDED", 500, ChcException::class, 60],
            ['Code: 241. DB::Exception: Memory limit exceeded', 500, ChcMemoryLimitExceededException::class, 241],
            ["partial JSON\n  Code: 159. DB::Exception: Timeout exceeded", 200, ChcTimeoutExceededException::class, 159],
            ['MEMORY_LIMIT_EXCEEDED', 500, ChcMemoryLimitExceededException::class, 241],
            ['TIMEOUT_EXCEEDED', 500, ChcTimeoutExceededException::class, 159],
            ['Service unavailable', 503, ChcException::class, 503],
            ['', 241, ChcMemoryLimitExceededException::class, 241],
            ['', 159, ChcTimeoutExceededException::class, 159],
        ];
        foreach ($cases as [$message, $inputCode, $class, $code]) {
            $e = $this->caughtException(
                function () use ($message, $inputCode, $previous, $query) {
                    throw ChcException::create($message, $inputCode, $previous, $query);
                },
                $class,
            );
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($code, $e->getCode());
            $this->assertSame($query, $e->getQuery());
            $this->assertSame($previous, $e->getPrevious());
        }
    }

    public function testClientTimeoutPreservesContextAndDoesNotFabricateServerCode(): void
    {
        $previous = new TimeoutException('TIMEOUT_EXCEEDED');
        $message = $previous->getMessage();
        $e = $this->caughtException(
            function () use ($message, $previous) {
                throw ChcException::create($message, 0, $previous, 'query-context');
            },
            ChcTimeoutExceededException::class,
        );
        $this->assertSame($message, $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertSame('query-context', $e->getQuery());
        $this->assertSame($previous, $e->getPrevious());
    }
}
