<?php

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use Symfony\Component\HttpClient\HttpClient;

class TransportFeaturesTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function transports(): iterable
    {
        yield 'native' => [false];
        yield 'symfony' => [true];
    }

    private function make(bool $symfony): ChcClient
    {
        $client = $this->client();
        if ($symfony) {
            $client->setHttpClient(HttpClient::create());
        }
        return $client;
    }

    /**
     * @dataProvider transports
     */
    public function testQueriesAndCompression(bool $symfony): void
    {
        $client = $this->make($symfony);
        $this->assertTrue($client->ping());
        $this->assertSame([['n' => 1]], $client->getJsonData('SELECT toUInt8(1) AS n'));

        $client->setCompression(true);
        $sql = 'SELECT toUInt32(number) AS n, repeat(\'x\', 100) AS s FROM numbers(20000)';
        $this->assertCount(20000, $client->fetchAll($sql));
        $this->assertSame(19999, $client->fetchColumn($sql)[19999]);
        $this->assertSame(20000, iterator_count($client->iterate($sql)));
        $this->assertGreaterThan(0, $client->getLastQueryLatency());

        $e = $this->caughtException(fn() => $client->fetchAll('SELECT * FROM chc_no_such_table'), ChcException::class);
        $this->assertSame(60, $e->getCode());
    }

    public function testIterate(): void
    {
        $client = $this->client();
        $n = 0;
        foreach ($client->iterate('SELECT toUInt32(number) AS n FROM numbers({c:UInt32})', ['c' => 300000]) as $row) {
            $this->assertSame($n++, $row['n']);
        }
        $this->assertSame(300000, $n);
        $this->assertSame([], iterator_to_array($client->iterate('SELECT 1 WHERE 0')));
    }

    public function testIterateMidStreamError(): void
    {
        $client = $this->client();
        $rows = 0;
        $e = $this->caughtException(function () use ($client, &$rows) {
            foreach ($client->iterate("SELECT number, throwIf(number = 300000, 'boom') FROM numbers(400000)", [], ['max_block_size' => 1000]) as $row) {
                $rows++;
            }
        }, ChcException::class);
        $this->assertSame(395, $e->getCode());
        $this->assertLessThanOrEqual(300000, $rows);
    }

    /**
     * @dataProvider transports
     */
    public function testInsert(bool $compression): void
    {
        $client = $this->client();
        $client->setCompression($compression);
        $table = 'chc_it_' . bin2hex(random_bytes(4));

        $client->readOnly(false);
        $client->write("CREATE TABLE $table (id UInt32, s String, d DateTime, a Array(String)) ENGINE = Memory");
        try {
            $client->readOnly(true);
            $e = $this->caughtException(fn() => $client->insert($table, [['id' => 1, 's' => 'x', 'd' => '2024-01-01 00:00:00', 'a' => []]]), ChcException::class);
            $this->assertSame(164, $e->getCode()); // READONLY

            $client->readOnly(false);
            $client->insert($table, (static function () {
                for ($i = 0; $i < 100000; $i++) {
                    yield ['id' => $i, 's' => "it's \"$i\"", 'd' => new \DateTimeImmutable('2024-01-02 03:04:05'), 'a' => ['a', 'b']];
                }
            })());
            $client->insert($table, [[100000, 'list', '2024-01-01 00:00:00', []]], ['id', 's', 'd', 'a']);

            $this->assertSame(100001, $client->fetchOne("SELECT toUInt32(count()) FROM $table"));
            $this->assertSame(
                ['s' => "it's \"5\"", 'd' => '2024-01-02 03:04:05', 'a' => ['a', 'b']],
                $client->fetchRow("SELECT s, d, a FROM $table WHERE id = 5"),
            );
            $this->assertSame('list', $client->fetchOne("SELECT s FROM $table WHERE id = 100000"));

            $e = $this->caughtException(fn() => $client->insert($table, [['id' => 'not a number']]), ChcException::class);
            $this->assertNotSame(0, $e->getCode());
        } finally {
            $client->readOnly(false);
            $client->write("DROP TABLE IF EXISTS $table");
        }
    }

    public function testPoolWithInjectedClientAndConcurrency(): void
    {
        $client = $this->client();
        $client->setHttpClient(HttpClient::create());
        $client->setPoolConcurrency(2);
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries["q$i"] = "SELECT toUInt8($i) AS n";
        }
        $this->assertSame(
            ['q0' => 0, 'q1' => 1, 'q2' => 2, 'q3' => 3, 'q4' => 4],
            $client->getJsonPool($queries, fn(array $data) => $data[0]['n']),
        );
    }
}
