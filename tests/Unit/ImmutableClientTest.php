<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\ChcReadOnly;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImmutableClientTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private function client(array &$requests, array $responses): ChcClient
    {
        return new ChcClient('http://localhost:8123/', new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
                $requests[] = [$method, $url, $options];
                return array_shift($responses) ?? new MockResponse();
            },
            'http://localhost:8123/',
        ));
    }

    public function testWithMethodsCloneAndKeepOriginalSettings(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('ok'), new MockResponse('ok')]);
        $withDatabase = $client->withDatabase('analytics');
        $withSettings = $withDatabase->withSettings(['max_threads' => 3]);
        $configured = $withSettings->withReadOnly(ChcReadOnly::Strict);

        $this->assertNotSame($client, $withDatabase);
        $this->assertNotSame($withDatabase, $withSettings);
        $this->assertNotSame($withSettings, $configured);
        $this->assertNotSame($client, $configured);
        $this->assertSame(ChcReadOnly::AllowSettings, $client->getReadOnly());
        $this->assertSame(ChcReadOnly::Strict, $configured->getReadOnly());
        $this->assertSame(ChcReadOnly::Strict, $client->withReadOnly(1)->getReadOnly());
        $client->getRaw('SELECT 1');
        $configured->getRaw('SELECT 1');

        parse_str((string)parse_url($requests[0][1], PHP_URL_QUERY), $originalQuery);
        parse_str((string)parse_url($requests[1][1], PHP_URL_QUERY), $configuredQuery);
        $this->assertSame(['readonly' => '2'], $originalQuery);
        $this->assertSame([
            'database' => 'analytics',
            'max_threads' => '3',
            'readonly' => '1',
        ], $configuredQuery);
    }

    public function testDefaultReadsUsePostWithSqlInPlainTextBody(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('1')]);
        $client->getRaw('SELECT 1', ChcFormat::JSON);

        [$method, $url, $options] = $requests[0];
        $this->assertSame('POST', $method);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['readonly' => '2'], $query);
        $this->assertSame('SELECT 1' . PHP_EOL . 'FORMAT JSON', $options['body']);
        $headers = array_map('strtolower', $options['headers']);
        $this->assertContains('content-type: text/plain; charset=utf-8', $headers);
        $this->assertArrayNotHasKey('query', $query);
    }

    public function testReadOnlyLevelsAndExplicitReadonlySettings(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
        ]);

        $client->getRaw('SELECT 1');
        $client->withReadOnly(ChcReadOnly::Strict)->getRaw('SELECT 1');
        $client->withReadOnly(ChcReadOnly::Disabled)->getRaw('SELECT 1');
        $client->withSettings(['readonly' => 1])->getRaw('SELECT 1');
        $client->withReadOnly(ChcReadOnly::Strict)->withReadOnly(2)->getRaw('SELECT 1');
        $client->withReadOnly(1)->getRaw('SELECT 1');
        $client->withReadOnly(0)->getRaw('SELECT 1');

        $queries = [];
        foreach ($requests as [, $url]) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $queries[] = $query;
        }
        $this->assertSame([
            ['readonly' => '2'],
            ['readonly' => '1'],
            [],
            ['readonly' => '1'],
            ['readonly' => '2'],
            ['readonly' => '1'],
            [],
        ], $queries);
        foreach ($requests as [$method, , $options]) {
            $this->assertSame('POST', $method);
            $this->assertSame('SELECT 1', $options['body']);
        }
    }

    public function testInvalidReadOnlyLevelsAreRejected(): void
    {
        $client = new ChcClient();
        foreach ([3, -1] as $level) {
            try {
                $client->withReadOnly($level);
                $this->fail('Expected an invalid read-only level to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Read-only level must be 0, 1 or 2', $e->getMessage());
            }
        }
    }

    public function testCloneClearsLastQueryStateAndPingStaysGet(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('1', [
                'http_code' => 200,
                'response_headers' => ['X-ClickHouse-Query-Id: original-query'],
            ]),
            new MockResponse("Ok.\n"),
        ]);

        $client->getRaw('SELECT 1');
        $this->assertSame('original-query', $client->getLastQueryId());
        $this->assertNotNull($client->getLastQueryLatency());

        $clone = $client->withSettings([]);
        $this->assertNull($clone->getLastQueryId());
        $this->assertNull($clone->getLastQueryLatency());
        $this->assertTrue($clone->ping());

        [$method, $url] = $requests[1];
        $this->assertSame('GET', $method);
        $this->assertSame('/ping', parse_url($url, PHP_URL_PATH));
    }

    public function testInsertKeepsSqlInUrlAndRowsInBody(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];
            return new MockResponse('Ok.');
        }, 'http://localhost:8123/');
        $transport = new ChcSymfonyCurlTransport($client);
        $request = (new ChcRequest())
            ->setSettings(['database' => 'analytics'])
            ->setQuery('INSERT INTO t FORMAT JSONEachRow')
            ->setReturnFormat(null);

        $transport->response($transport->insert($request, ['{"x":1}' . "\n"]));

        [$method, $url, $options] = $captured;
        $this->assertSame('POST', $method);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('analytics', $query['database']);
        $this->assertSame('INSERT INTO t FORMAT JSONEachRow', $query['query']);
        $this->assertInstanceOf(\Closure::class, $options['body']);
        $headers = array_map('strtolower', $options['headers'] ?? []);
        $this->assertSame([], array_values(array_filter($headers, static fn(string $header): bool => str_starts_with($header, 'content-type:'))));
    }

    public function testClientPassesCredentialsTimeoutAndPoolOptions(): void
    {
        $captured = [];
        $client = $this->mockedClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = [$method, $url, $options];
            return new MockResponse('{"data":[],"meta":[],"rows":0}');
        });
        $client = $client
            ->withTimeout(3.0)
            ->withCredentials('u', 'p')
            ->withPoolConcurrency(2);
        $client->getJsonPool(['a' => 'SELECT 1'], static fn() => null);

        $this->assertSame('POST', $captured[0][0]);
        $this->assertSame(3.0, $captured[0][2]['timeout']);
        parse_str((string)parse_url($captured[0][1], PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('user', $query);
        $this->assertContains('X-ClickHouse-User: u', $captured[0][2]['headers']);
        $this->assertContains('X-ClickHouse-Key: p', $captured[0][2]['headers']);
    }
}
