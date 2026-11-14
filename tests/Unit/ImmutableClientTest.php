<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcReadOnly;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImmutableClientTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private function client(array &$requests, array $responses = []): ChcClient
    {
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
                $requests[] = [$method, $url, $options];
                return array_shift($responses) ?: new MockResponse('1');
            },
            'http://localhost:8123/',
        );

        return new ChcClient('http://localhost:8123/', $httpClient);
    }

    public function testEveryWithMethodReturnsAClone(): void
    {
        $client = new ChcClient();
        $httpClient = new MockHttpClient(new MockResponse('1'), 'http://localhost:8123/');

        $clones = [
            $client->withUrl('http://localhost:8124/'),
            $client->withDatabase('analytics'),
            $client->withSettings(['max_threads' => 2]),
            $client->withCredentials('reader', 'secret'),
            $client->withTimeout(3.0),
            $client->withReadOnly(1),
            $client->withCompression(true),
            $client->withHttpClient($httpClient),
            $client->withPoolConcurrency(2),
            $client->withRetries(1, 0.0),
            $client->withSession('session'),
            $client->withSqlLogger(null),
        ];

        foreach ($clones as $clone) {
            $this->assertNotSame($client, $clone);
        }
    }

    public function testWithUrlChangesOnlyTheClone(): void
    {
        $client = new class extends ChcClient {
            public function configuredUrl()
            {
                return $this->url;
            }
        };
        $clone = $client->withUrl('http://localhost:8124/');

        $this->assertNotSame($client, $clone);
        $this->assertSame('http://localhost:8123/', $client->configuredUrl());
        $this->assertSame('http://localhost:8124/', $clone->configuredUrl());
    }

    public function testWithDatabaseSettingsAndCredentialsDoNotChangeOriginalRequests(): void
    {
        $requests = [];
        $client = $this->client($requests);
        $configured = $client
            ->withDatabase('analytics')
            ->withSettings(['max_threads' => 3])
            ->withCredentials('reader', 'secret');

        $client->getRaw('SELECT 1');
        $configured->getRaw('SELECT 1');

        [, $originalUrl, $originalOptions] = $requests[0];
        [, $configuredUrl, $configuredOptions] = $requests[1];
        parse_str((string)parse_url($originalUrl, PHP_URL_QUERY), $originalQuery);
        parse_str((string)parse_url($configuredUrl, PHP_URL_QUERY), $configuredQuery);

        $this->assertSame(['query' => 'SELECT 1'], $originalQuery);
        $this->assertSame([
            'database' => 'analytics',
            'max_threads' => '3',
            'query' => 'SELECT 1',
        ], $configuredQuery);
        $this->assertNotContains('X-ClickHouse-User: reader', $originalOptions['headers'] ?? []);
        $this->assertNotContains('X-ClickHouse-Key: secret', $originalOptions['headers'] ?? []);
        $this->assertContains('X-ClickHouse-User: reader', $configuredOptions['headers']);
        $this->assertContains('X-ClickHouse-Key: secret', $configuredOptions['headers']);
    }

    public function testWithMethodsClearOnlyCloneQueryState(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('1', [
                'http_code' => 200,
                'response_headers' => ['X-ClickHouse-Query-Id: original-query'],
            ]),
        ]);

        $client->getRaw('SELECT 1');
        $originalLatency = $client->getLastQueryLatency();
        $clone = $client->withSettings(['max_threads' => 2]);

        $this->assertSame('original-query', $client->getLastQueryId());
        $this->assertNotNull($originalLatency);
        $this->assertNull($clone->getLastQueryId());
        $this->assertNull($clone->getLastQueryLatency());
        $this->assertSame('original-query', $client->getLastQueryId());
        $this->assertSame($originalLatency, $client->getLastQueryLatency());
    }

    public function testReadOnlyLevelsPreserveTheLegacyRequestModes(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            new MockResponse('1'),
            new MockResponse('1'),
            new MockResponse('1'),
        ]);

        $this->assertSame(ChcReadOnly::AllowSettings, $client->getReadOnly());
        $levelOne = $client->withReadOnly(ChcReadOnly::Strict);
        $levelTwo = $client->withReadOnly(2);
        $writeClient = $client->withReadOnly(ChcReadOnly::Disabled);
        $this->assertSame(ChcReadOnly::Strict, $levelOne->getReadOnly());
        $this->assertSame(ChcReadOnly::Strict, $client->withReadOnly(1)->getReadOnly());
        $this->assertSame(ChcReadOnly::AllowSettings, $levelTwo->getReadOnly());
        $this->assertSame(ChcReadOnly::Disabled, $writeClient->getReadOnly());
        $this->assertSame(ChcReadOnly::Disabled, $client->withReadOnly(0)->getReadOnly());
        $levelOne->getRaw('SELECT 1');
        $levelTwo->getRaw('SELECT 1');
        $writeClient->getRaw('SELECT 1');

        [$levelOneMethod, $levelOneUrl] = $requests[0];
        [$levelTwoMethod, $levelTwoUrl] = $requests[1];
        [$writeMethod, $writeUrl, $writeOptions] = $requests[2];
        $this->assertSame('GET', $levelOneMethod);
        $this->assertSame($levelOneMethod, $levelTwoMethod);
        $this->assertSame($levelOneUrl, $levelTwoUrl);
        $this->assertSame($requests[0], $requests[1]);
        parse_str((string)parse_url($levelOneUrl, PHP_URL_QUERY), $readQuery);
        $this->assertArrayNotHasKey('readonly', $readQuery);
        $this->assertSame(ChcReadOnly::AllowSettings, $client->getReadOnly());
        $this->assertSame('POST', $writeMethod);
        parse_str((string)parse_url($writeUrl, PHP_URL_QUERY), $writeQuery);
        $this->assertArrayNotHasKey('readonly', $writeQuery);
        $this->assertArrayNotHasKey('query', $writeQuery);
        $this->assertSame('SELECT 1', $writeOptions['body']);

        foreach ([3, -1] as $level) {
            try {
                $client->withReadOnly($level);
                $this->fail('Expected an invalid read-only level to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Read-only level must be 0, 1 or 2', $exception->getMessage());
            }
        }

        $this->assertTrue($client->readOnly(false));
        $this->assertSame(ChcReadOnly::Disabled, $client->getReadOnly());
        $this->assertFalse($client->readOnly(true));
        $this->assertSame(ChcReadOnly::AllowSettings, $client->getReadOnly());
    }

    public function testConstructorSetsUrlAndUsesInjectedHttpClient(): void
    {
        $requests = [];
        $url = 'http://localhost:8124/';
        $httpClient = new MockHttpClient(
            static function (string $method, string $requestUrl, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $requestUrl, $options];
                return new MockResponse('1');
            },
            $url,
        );

        $client = new ChcClient($url, $httpClient);
        $client->getRaw('SELECT 1');

        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0][0]);
        $this->assertSame('localhost', parse_url($requests[0][1], PHP_URL_HOST));
        $this->assertSame(8124, parse_url($requests[0][1], PHP_URL_PORT));
        parse_str((string)parse_url($requests[0][1], PHP_URL_QUERY), $query);
        $this->assertSame('SELECT 1', $query['query']);
    }
}
