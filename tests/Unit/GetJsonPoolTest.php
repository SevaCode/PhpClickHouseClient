<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

class GetJsonPoolTest extends TestCase
{
    /**
     * @param class-string<ChcException> $class
     */
    private function poolFailure(callable $respond, string $class): ChcException
    {
        $sentQuery = null;
        $client = $this->mockedClient(function ($method, $url) use (&$sentQuery, $respond) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
            $sentQuery = $params['query'] ?? null;
            return $respond();
        });
        $e = $this->caughtException(
            fn() => $client->getJsonPool(['query-context'], function () {
                throw new \LogicException('Callback invoked on failed request');
            }),
            $class,
        );
        $this->assertNotNull($sentQuery);
        $this->assertSame($sentQuery, $e->getQuery());
        return $e;
    }

    public function testValidJsonReachesCallback(): void
    {
        $client = $this->mockedClient(new MockResponse('{"data":[{"x":1}],"meta":[],"rows":1}', ['http_code' => 200]));
        $result = $client->getJsonPool(['query-context'], fn($data, $meta, $rows) => [$data, $meta, $rows]);
        $this->assertSame([[[['x' => 1]], [], 1]], $result);
    }

    public function testPreservesQueryKeys(): void
    {
        $client = $this->mockedClient(function ($method, $url) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
            $n = (int)$params['query'];
            return new MockResponse('{"data":[{"n":' . $n . '}],"meta":[],"rows":1}', ['http_code' => 200]);
        });
        $result = $client->getJsonPool(['b' => '2', 'a' => '1'], fn($data) => $data[0]['n']);
        $this->assertSame(['b' => 2, 'a' => 1], $result);
    }

    public function testMidStreamErrorNeverReachesCallback(): void
    {
        $body = '{"data":[' . "\nCode: 241. DB::Exception: Memory limit exceeded";
        $e = $this->poolFailure(fn() => new MockResponse($body, ['http_code' => 200]), ChcMemoryLimitExceededException::class);
        $this->assertSame($body, $e->getMessage());
        $this->assertSame(241, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testRequestTimeTimeoutRetainsOriginalExceptionAndQuery(): void
    {
        $previous = new TimeoutException('Request timed out');
        $e = $this->poolFailure(fn() => throw $previous, ChcTimeoutExceededException::class);
        $this->assertSame($previous->getMessage(), $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testResponseTimeTimeoutRetainsOriginalExceptionAndHttpStatus(): void
    {
        $e = $this->poolFailure(fn() => new MockResponse((function () {
            yield 'partial body';
            yield ''; // MockResponse represents an idle timeout with an empty chunk.
        })(), ['http_code' => 200]), ChcTimeoutExceededException::class);
        $this->assertSame(200, $e->getCode());
        $this->assertInstanceOf(TimeoutExceptionInterface::class, $e->getPrevious());
        $this->assertSame($e->getPrevious()->getMessage(), $e->getMessage());
    }
}
