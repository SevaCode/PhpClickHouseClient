<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcResult;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

class PoolTest extends TestCase
{
    private static function sentQuery(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return (string)$query['query'];
    }

    public function testResultExposesJsonFieldsAndHeaders(): void
    {
        $body = '{"meta":[{"name":"n","type":"UInt8"}],"data":[{"n":1}],"totals":{"n":1},"extremes":{"min":{"n":1}},'
            . '"rows":1,"rows_before_limit_at_least":5,"statistics":{"elapsed":0.001,"rows_read":1,"bytes_read":1}}';
        $client = $this->mockedClient(new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => [
                'X-ClickHouse-Format: JSON',
                'X-ClickHouse-Query-Id: qid-1',
                'X-ClickHouse-Summary: {"read_rows":"1","read_bytes":"1"}',
            ],
        ]));

        $result = $client->pool(['one' => 'SELECT 1 AS n'])['one'];

        $this->assertInstanceOf(ChcResult::class, $result);
        $this->assertSame([['n' => 1]], $result->data);
        $this->assertSame([['name' => 'n', 'type' => 'UInt8']], $result->meta);
        $this->assertSame(1, $result->rows);
        $this->assertSame(5, $result->rowsBeforeLimitAtLeast);
        $this->assertSame(['elapsed' => 0.001, 'rows_read' => 1, 'bytes_read' => 1], $result->statistics);
        $this->assertSame(['n' => 1], $result->totals);
        $this->assertSame(['min' => ['n' => 1]], $result->extremes);
        $this->assertGreaterThan(0, $result->startTime);
        $this->assertSame('qid-1', $result->queryId);
        $this->assertSame(['read_rows' => '1', 'read_bytes' => '1'], $result->summary);
    }

    public function testOptionalFieldsAreNullWhenAbsent(): void
    {
        $client = $this->mockedClient(new MockResponse('{"meta":[],"data":[],"rows":0}', ['http_code' => 200]));

        $result = $client->pool(['SELECT 1'])[0];

        $this->assertSame(0, $result->rows);
        $this->assertNull($result->rowsBeforeLimitAtLeast);
        $this->assertNull($result->statistics);
        $this->assertNull($result->totals);
        $this->assertNull($result->extremes);
        $this->assertNull($result->queryId);
        $this->assertNull($result->summary);
    }

    public function testPreservesKeysOrderAndSendsJsonFormat(): void
    {
        $bodies = [];
        $client = $this->mockedClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = self::sentQuery($url);
            $n = (int)substr(self::sentQuery($url), strlen('SELECT '));
            return new MockResponse('{"data":[{"n":' . $n . '}],"meta":[],"rows":1}', ['http_code' => 200]);
        });

        $results = $client->pool(['b' => 'SELECT 2', 'a' => 'SELECT 1']);

        $this->assertSame(['b', 'a'], array_keys($results));
        $this->assertSame(2, $results['b']->data[0]['n']);
        $this->assertSame(1, $results['a']->data[0]['n']);
        $this->assertSame(["SELECT 2\nFORMAT JSON", "SELECT 1\nFORMAT JSON"], $bodies);
        $this->assertGreaterThan(0, $client->getLastQueryLatency());
    }

    public function testEmptyQueriesReturnEmptyArray(): void
    {
        $client = $this->mockedClient(function () {
            throw new \LogicException('No request expected');
        });

        $this->assertSame([], $client->pool([]));
    }

    public function testServerErrorThrows(): void
    {
        $client = $this->mockedClient(function ($method, $url, $options) {
            if (str_starts_with(self::sentQuery($url), 'SELECT bad')) {
                return new MockResponse("Code: 60. DB::Exception: Unknown table\n", ['http_code' => 404]);
            }
            return new MockResponse('{"data":[],"meta":[],"rows":0}', ['http_code' => 200]);
        });

        $e = $this->caughtException(
            fn() => $client->pool(['ok' => 'SELECT 1', 'bad' => 'SELECT bad']),
            ChcException::class,
        );

        $this->assertSame(60, $e->getCode());
        $this->assertSame(404, $e->getHttpStatus());
        $this->assertSame("SELECT bad\nFORMAT JSON", $e->getQuery());
    }

    public function testDeprecatedGetJsonPoolMatchesPool(): void
    {
        $client = $this->mockedClient(fn() => new MockResponse(
            '{"data":[{"n":1}],"meta":[{"name":"n","type":"UInt8"}],"rows":1,"rows_before_limit_at_least":3}',
            ['http_code' => 200],
        ));
        $queries = ['x' => 'SELECT 1 AS n'];

        $result = $client->pool($queries)['x'];
        $legacy = $client->getJsonPool($queries, fn(...$args) => $args)['x'];

        $this->assertSame(
            [$result->data, $result->meta, $result->rows, $result->rowsBeforeLimitAtLeast, $result->statistics, $result->totals, $result->extremes],
            array_slice($legacy, 0, 7),
        );
        $this->assertIsFloat($legacy[7]);
    }
}
