<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcReadOnly;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcServerException;

class ChcClientTest extends IntegrationTestCase
{
    public function testPing(): void
    {
        $this->assertTrue($this->client()->ping());
    }

    public function testSelectFormats(): void
    {
        $client = $this->client();
        $this->assertSame([['n' => 1, 's' => 'a']], $client->getJsonData("SELECT toUInt8(1) AS n, 'a' AS s"));
        $this->assertSame("1\ta\n", $client->getRaw("SELECT 1, 'a'", ChcFormat::TabSeparated));
        $this->assertSame('{"x":1}' . "\n", $client->getRaw('SELECT toUInt8(1) AS x', ChcFormat::JSONEachRow));
        $this->assertGreaterThan(0, $client->getLastQueryLatency());
    }

    public function testSettingsAreForwarded(): void
    {
        $client = $this->client()->withSettings(['max_threads' => 3]);
        $this->assertSame([['v' => '3']], $client->getJsonData("SELECT toString(getSetting('max_threads')) AS v"));
    }

    public function testWriteRequiresReadWriteMode(): void
    {
        $client = $this->client();
        $table = 'chc_it_' . bin2hex(random_bytes(4));

        $e = $this->caughtException(fn() => $client->execute("CREATE TABLE $table (x UInt8) ENGINE = Memory"), ChcServerException::class);
        $this->assertSame(164, $e->getCode()); // READONLY

        $writeClient = $client->withReadOnly(ChcReadOnly::Disabled);
        try {
            $writeClient->execute("CREATE TABLE $table (x UInt8) ENGINE = Memory");
            $e = $this->caughtException(fn() => $client->execute("INSERT INTO $table VALUES (1)"), ChcServerException::class);
            $this->assertSame(164, $e->getCode()); // READONLY
            $writeClient->execute("INSERT INTO $table VALUES (1), (2)");
            $this->assertSame([['c' => 2]], $client->getJsonData("SELECT toUInt8(count()) AS c FROM $table"));
        } finally {
            $writeClient->execute("DROP TABLE IF EXISTS $table");
        }
    }

    public function testUnknownTableError(): void
    {
        $e = $this->caughtException(fn() => $this->client()->getJson('SELECT * FROM chc_no_such_table'), ChcServerException::class);
        $this->assertSame(60, $e->getCode()); // UNKNOWN_TABLE
        $this->assertStringContainsString('chc_no_such_table', (string)$e->getQuery());
    }

    public function testMemoryLimitError(): void
    {
        $client = $this->client()->withSettings(['max_memory_usage' => 10000000]);
        $e = $this->caughtException(fn() => $client->getJson('SELECT groupArray(number) FROM numbers(100000000)'), ChcMemoryLimitExceededException::class);
        $this->assertSame(241, $e->getCode());
    }

    public function testExceptionAfterHttp200IsDetected(): void
    {
        $client = $this->client()->withSettings(['max_block_size' => 1000]);
        $sql = "SELECT number, throwIf(number = 300000, 'boom') FROM numbers(400000)";

        $e = $this->caughtException(fn() => $client->getJson($sql), ChcServerException::class);
        $this->assertSame(395, $e->getCode()); // FUNCTION_THROW_IF_VALUE_IS_NON_ZERO

        $e = $this->caughtException(fn() => $client->getRaw($sql, ChcFormat::TabSeparated), ChcServerException::class);
        $this->assertSame(395, $e->getCode());
    }

    public function testPool(): void
    {
        $results = $this->client()->pool(['one' => 'SELECT toUInt8(1) AS n', 'two' => 'SELECT toUInt8(2) AS n']);

        $this->assertSame(['one', 'two'], array_keys($results));
        $this->assertSame([['n' => 1]], $results['one']->data);
        $this->assertSame([['name' => 'n', 'type' => 'UInt8']], $results['one']->meta);
        $this->assertSame(1, $results['two']->rows);
        $this->assertSame(2, $results['two']->data[0]['n']);
        $this->assertNotEmpty($results['one']->queryId);
        $this->assertNotSame($results['one']->queryId, $results['two']->queryId);
        $this->assertArrayHasKey('read_rows', $results['one']->summary ?? []);
        $this->assertArrayHasKey('elapsed', $results['one']->statistics ?? []);
    }

    public function testPoolError(): void
    {
        $e = $this->caughtException(
            fn() => $this->client()->pool(['ok' => 'SELECT 1', 'bad' => 'SELECT * FROM chc_no_such_table']),
            ChcServerException::class,
        );
        $this->assertSame(60, $e->getCode());
    }

    public function testJsonPool(): void
    {
        $result = $this->client()->getJsonPool(
            ['one' => 'SELECT toUInt8(1) AS n', 'two' => 'SELECT toUInt8(2) AS n'],
            fn(array $data, array $meta, int $rows) => [$data[0]['n'], $meta[0]['type'], $rows],
        );
        $this->assertSame(['one' => [1, 'UInt8', 1], 'two' => [2, 'UInt8', 1]], $result);
    }

    public function testJsonPoolError(): void
    {
        $e = $this->caughtException(
            fn() => $this->client()->getJsonPool(['SELECT * FROM chc_no_such_table'], fn() => null),
            ChcServerException::class,
        );
        $this->assertSame(60, $e->getCode());
    }
}
