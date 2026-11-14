<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcReadOnly;
use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DriverFeaturesTest extends TestCase
{
    /**
     * @param list<MockResponse|\Throwable> $responses
     */
    private function client(array &$requests, array $responses): ChcClient
    {
        return new ChcClient('http://localhost:8123/', new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses) {
            $requests[] = [$method, $url, $options];
            $next = array_shift($responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        }));
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

    public function testFormatArgumentsNormalizeEnumStringsAndNoFormat(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('ok'),
            new MockResponse('ok'),
            new MockResponse('ok'),
            new MockResponse('ok'),
            new MockResponse('ok'),
        ]);

        $client->getRaw('SELECT 1', ChcFormat::JSON);
        $client->getRaw('SELECT 1', 'JSON');
        $client->getRaw('SELECT 1');
        $client->getRaw('SELECT 1', '');
        $client->getRaw('SELECT 1', null);

        $queries = array_map(static fn(array $request): string => (string)$request[2]['body'], $requests);

        $this->assertSame([
            'SELECT 1' . PHP_EOL . 'FORMAT JSON',
            'SELECT 1' . PHP_EOL . 'FORMAT JSON',
            'SELECT 1',
            'SELECT 1',
            'SELECT 1',
        ], $queries);
    }

    public function testGetJsonReturnsDecodedJsonResponsePayload(): void
    {
        $client = $this->mockedClient(new MockResponse('{"data":[{"x":1}],"meta":[],"rows":1}'));

        $this->assertSame([
            'data' => [['x' => 1]],
            'meta' => [],
            'rows' => 1,
        ], $client->getJson('SELECT 1 AS x'));
    }

    public function testSession(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('1'), new MockResponse('1')]);
        $client = $client->withSession('s1', 60);
        $client->getRaw('SELECT 1');
        $client = $client->withSession(null);
        $client->getRaw('SELECT 1');
        $this->assertStringContainsString('session_id=s1', $requests[0][1]);
        $this->assertStringContainsString('session_timeout=60', $requests[0][1]);
        $this->assertStringNotContainsString('session', $requests[1][1]);
    }

    public function testRetriesReadOnlyOnly(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('', ['http_code' => 503]),
            new TransportException('Connection refused'),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $client = $client->withRetries(2, 0);
        $this->assertSame('ok', $client->getRaw('SELECT 1'));
        $this->assertCount(3, $requests);

        // Not retried: retries exhausted
        $requests = [];
        $client = $this->client($requests, [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503])]);
        $client = $client->withRetries(1, 0);
        $e = $this->caughtException(fn() => $client->getRaw('SELECT 1'), ChcException::class);
        $this->assertSame(503, $e->getHttpStatus());
        $this->assertCount(2, $requests);

        // Not retried: write
        $requests = [];
        $client = $this->client($requests, [new MockResponse('', ['http_code' => 503]), new MockResponse('ok')]);
        $client = $client->withRetries(3, 0)->withReadOnly(ChcReadOnly::Disabled);
        $this->caughtException(fn() => $client->execute('INSERT INTO t VALUES (1)'), ChcException::class);
        $this->assertCount(1, $requests);

        // Not retried: ClickHouse error
        $requests = [];
        $client = $this->client($requests, [new MockResponse('Code: 60. DB::Exception: Unknown table', ['http_code' => 404]), new MockResponse('ok')]);
        $client = $client->withRetries(3, 0);
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
    }

    public function testDefaultHttpClientIsAliveReturnsFalseWhenConnectionFails(): void
    {
        $client = new ChcClient('http://127.0.0.1:1/');
        $this->assertFalse(@$client->isAlive());
    }

    public function testDefaultHttpClientConnectionFailureThrowsChcConnectionException(): void
    {
        $client = new ChcClient('http://127.0.0.1:1/');
        $this->caughtException(fn() => $client->getRaw('SELECT 1'), ChcConnectionException::class);
    }

    public function testDefaultHttpClientIsReusedAndResetWhenUrlChanges(): void
    {
        $client = new class extends ChcClient {
            public int $clientCreations = 0;

            protected function createHttpClient(): HttpClientInterface
            {
                $this->clientCreations++;
                return new MockHttpClient(static fn() => new MockResponse('1'), $this->url);
            }
        };

        $client->getRaw('SELECT 1');
        $client->getRaw('SELECT 1');
        $this->assertSame(1, $client->clientCreations);

        $original = $client;
        $client = $client->withSettings(['max_threads' => 2]);
        $client->getRaw('SELECT 1');
        $this->assertSame(1, $client->clientCreations);
        $this->assertSame(1, $original->clientCreations);

        $client = $client->withUrl('http://localhost:8124/');
        $client->getRaw('SELECT 1');
        $this->assertSame(2, $client->clientCreations);
        $this->assertSame(1, $original->clientCreations);
    }
}
