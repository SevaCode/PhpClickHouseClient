<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcInsertBody;
use Symfony\Component\HttpClient\Response\MockResponse;

class StreamingTest extends TestCase
{
    /**
     * @param string[] $chunks
     * @return array{array<int, mixed>, ?ChcException}
     */
    private function iterate(array $chunks, array $headers = [], int $status = 200): array
    {
        $client = $this->mockedClient(new MockResponse((static fn() => yield from $chunks)(), [
            'http_code' => $status,
            'response_headers' => $headers,
        ]));
        $rows = [];
        try {
            foreach ($client->iterate('SELECT x') as $row) {
                $rows[] = $row;
            }
        } catch (ChcException $e) {
            return [$rows, $e];
        }
        return [$rows, null];
    }

    public function testRowsSplitAcrossChunks(): void
    {
        [$rows, $e] = $this->iterate(['{"x":1}' . "\n" . '{"x"', ':2}' . "\n", '{"x":3}']);
        $this->assertNull($e);
        $this->assertSame([['x' => 1], ['x' => 2], ['x' => 3]], $rows);

        [$rows, $e] = $this->iterate([]);
        $this->assertNull($e);
        $this->assertSame([], $rows);
    }

    public function testMidStreamErrors(): void
    {
        // 24.x: exception line
        [$rows, $e] = $this->iterate(['{"x":1}' . "\n", '{"exception": "Code: 395. DB::Exception: boom (FOO) (version 24.8)"}' . "\n"]);
        $this->assertSame([['x' => 1]], $rows);
        $this->assertSame(395, $e?->getCode());

        // 23.x: plain text
        [$rows, $e] = $this->iterate(['{"x":1}' . "\n" . 'Code: 241. DB::Exception: mem (MEMORY_LIMIT_EXCEEDED) (version 23.8.16.16 (official build))' . "\n"]);
        $this->assertSame([['x' => 1]], $rows);
        $this->assertSame(241, $e?->getCode());

        // 25.x+: tagged block
        $msg = 'Code: 395. DB::Exception: boom (FOO) (version 26.10.3.7 (official build))';
        [$rows, $e] = $this->iterate(
            ['{"x":1}' . "\n", "\r\n__exception__\r\nabcd\r\n$msg\n" . strlen($msg) . " abcd\r\n__exception__\r\n"],
            ['X-ClickHouse-Exception-Tag: abcd'],
        );
        $this->assertSame([['x' => 1]], $rows);
        $this->assertSame(395, $e?->getCode());
        $this->assertSame($msg, $e->getMessage());

        // HTTP error before data
        [$rows, $e] = $this->iterate(['Code: 60. DB::Exception: Unknown table'], [], 404);
        $this->assertSame([], $rows);
        $this->assertSame(60, $e?->getCode());

        // Garbage without a recognisable error
        [, $e] = $this->iterate(['{"x":1}' . "\n" . 'oops' . "\n"]);
        $this->assertStringStartsWith('Malformed JSONEachRow response', (string)$e?->getMessage());
    }

    public function testExceptionLikeDataRowIsNotAnError(): void
    {
        [$rows, $e] = $this->iterate(['{"exception":"Code: 1 is data"}' . "\n" . '{"exception":"x"}' . "\n"]);
        $this->assertNull($e);
        $this->assertSame([['exception' => 'Code: 1 is data'], ['exception' => 'x']], $rows);
    }

    public function testInsertBody(): void
    {
        $rows = [['a' => 1, 'd' => new \DateTimeImmutable('2024-01-02 03:04:05'), 's' => 'Привет'], [2, null, 'x']];
        $body = implode('', iterator_to_array(ChcInsertBody::jsonEachRow($rows, ['a', 'd', 's']), false));
        $this->assertSame('{"a":1,"d":"2024-01-02 03:04:05","s":"Привет"}' . "\n" . '{"a":2,"d":null,"s":"x"}' . "\n", $body);

        $gz = implode('', iterator_to_array(ChcInsertBody::jsonEachRow($rows, ['a', 'd', 's'], true), false));
        $this->assertSame($body, gzdecode($gz));

        $big = implode('', iterator_to_array(ChcInsertBody::jsonEachRow((static function () {
            for ($i = 0; $i < 50000; $i++) {
                yield ['i' => $i, 's' => str_repeat('x', 20)];
            }
        })()), false));
        $this->assertSame(50000, substr_count($big, "\n"));

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(ChcInsertBody::jsonEachRow([[1, 2]], ['a']));
    }

    public function testPoolConcurrencyIsBounded(): void
    {
        $inFlight = 0;
        $max = 0;
        $client = $this->mockedClient(function () use (&$inFlight, &$max) {
            $inFlight++;
            $max = max($max, $inFlight);
            return new MockResponse((function () use (&$inFlight) {
                yield '{"data":[],"meta":[],"rows":0}';
                $inFlight--;
            })(), ['http_code' => 200]);
        });
        $client->setPoolConcurrency(2);
        $result = $client->getJsonPool(['a' => 'SELECT 1', 'b' => 'SELECT 2', 'c' => 'SELECT 3', 'd' => 'SELECT 4'], fn($data, $meta, $rows) => $rows);
        $this->assertSame(['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0], $result);
        $this->assertLessThanOrEqual(2, $max);
        $this->assertGreaterThan(0, $client->getLastQueryLatency());
    }
}
