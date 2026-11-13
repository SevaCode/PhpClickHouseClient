<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcHttpTransport;

class ChcHttpTransportTest extends TestCase
{
    #[WithoutErrorHandler]
    public function testStreamFailurePreservesStreamErrorContext(): void
    {
        // Port 1 is closed: file_get_contents() fails with a real stream warning.
        $transport = new ChcHttpTransport('http://127.0.0.1:1');
        $e = $this->caughtException(fn() => @$transport->run($this->request()), ChcException::class);
        $this->assertSame(0, $e->getCode());
        $this->assertSame('query-context', $e->getQuery());
        $this->assertNotNull(error_get_last());
        $this->assertSame(error_get_last()['message'], $e->getMessage());
    }

    public function testMissingHeadersUseFallbackRatherThanStaleLastError(): void
    {
        // data: wrapper returns a body without $http_response_header.
        trigger_error_silently();
        $transport = new ChcHttpTransport('data:text/plain,Ok.');
        $e = $this->caughtException(fn() => $transport->run($this->request()), ChcException::class);
        $this->assertSame('Request failed', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertSame('query-context', $e->getQuery());
    }
}

function trigger_error_silently(): void
{
    // Leaves a stale error_get_last() that must not leak into the exception message.
    @file_get_contents(__FILE__ . '/missing');
}
