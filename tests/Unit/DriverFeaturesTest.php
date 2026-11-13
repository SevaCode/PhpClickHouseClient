<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DriverFeaturesTest extends TestCase
{
    /**
     * @param list<MockResponse|\Throwable> $responses
     */
    private function client(array &$requests, array $responses): ChcClient
    {
        $client = new ChcClient();
        $client->setHttpClient(new MockHttpClient(function (string $method, string $url) use (&$requests, &$responses) {
            $requests[] = $url;
            $next = array_shift($responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        }));
        return $client;
    }

    public function testExceptionCodes(): void
    {
        $e = ChcException::create('Code: 60. DB::Exception: Unknown table', 404);
        $this->assertSame(60, $e->getCode());
        $this->assertSame(60, $e->getClickHouseErrorCode());
        $this->assertSame(404, $e->getHttpStatus());

        $e = ChcException::create('Bad gateway', 502);
        $this->assertSame(502, $e->getCode());
        $this->assertNull($e->getClickHouseErrorCode());
        $this->assertSame(502, $e->getHttpStatus());

        $e = ChcException::create('Couldn\'t connect', previous: new TransportException('x'));
        $this->assertInstanceOf(ChcConnectionException::class, $e);
        $this->assertNull($e->getHttpStatus());
        $this->assertNull($e->getClickHouseErrorCode());

        $e = ChcException::create('Idle timeout', previous: new TimeoutException('x'));
        $this->assertInstanceOf(ChcTimeoutExceededException::class, $e);
    }

    public function testQueryIdAndSummary(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('{"data":[{"x":1}],"meta":[],"rows":1}', [
            'http_code' => 200,
            'response_headers' => [
                'X-ClickHouse-Query-Id: 1b2c',
                'X-ClickHouse-Format: JSON',
                'X-ClickHouse-Summary: {"read_rows":"1","read_bytes":"8"}',
            ],
        ])]);
        $this->assertNull($client->getLastQueryId());
        $this->assertSame([['x' => 1]], $client->fetchAll('SELECT 1 AS x'));
        $this->assertSame('1b2c', $client->getLastQueryId());
        $this->assertSame(['read_rows' => '1', 'read_bytes' => '8'], $client->getLastQuerySummary());
    }

    public function testSession(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('1'), new MockResponse('1')]);
        $client->setSessionId('s1', 60);
        $client->getRaw('SELECT 1');
        $client->setSessionId(null);
        $client->getRaw('SELECT 1');
        $this->assertStringContainsString('session_id=s1', $requests[0]);
        $this->assertStringContainsString('session_timeout=60', $requests[0]);
        $this->assertStringNotContainsString('session', $requests[1]);
    }

    public function testRetriesReadOnlyOnly(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('', ['http_code' => 503]),
            new TransportException('Connection refused'),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client->setRetries(2, 0);
        $this->assertSame('ok', $client->getRaw('SELECT 1'));
        $this->assertCount(3, $requests);

        // Not retried: retries exhausted
        $requests = [];
        $client = $this->client($requests, [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503])]);
        $client->setRetries(1, 0);
        $e = $this->caughtException(fn() => $client->getRaw('SELECT 1'), ChcException::class);
        $this->assertSame(503, $e->getHttpStatus());
        $this->assertCount(2, $requests);

        // Not retried: write
        $requests = [];
        $client = $this->client($requests, [new MockResponse('', ['http_code' => 503]), new MockResponse('ok')]);
        $client->setRetries(3, 0);
        $client->readOnly(false);
        $this->caughtException(fn() => $client->execute('INSERT INTO t VALUES (1)'), ChcException::class);
        $this->assertCount(1, $requests);

        // Not retried: ClickHouse error
        $requests = [];
        $client = $this->client($requests, [new MockResponse('Code: 60. DB::Exception: Unknown table', ['http_code' => 404]), new MockResponse('ok')]);
        $client->setRetries(3, 0);
        $this->caughtException(fn() => $client->getRaw('SELECT 1'), ChcException::class);
        $this->assertCount(1, $requests);

        // Default: no retries
        $requests = [];
        $client = $this->client($requests, [new TransportException('Connection refused'), new MockResponse('ok')]);
        $this->caughtException(fn() => $client->getRaw('SELECT 1'), ChcConnectionException::class);
        $this->assertCount(1, $requests);
    }

    public function testIsAlive(): void
    {
        $requests = [];
        $this->assertTrue($this->client($requests, [new MockResponse("Ok.\n")])->isAlive());
        $this->assertFalse($this->client($requests, [new TransportException('Connection refused')])->isAlive());
        $this->assertFalse($this->client($requests, [new MockResponse('', ['http_code' => 503])])->isAlive());

        $client = new ChcClient();
        $client->setUrl('http://127.0.0.1:1/');
        $this->assertFalse(@$client->isAlive());
    }
}
