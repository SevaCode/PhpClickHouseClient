<?php

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;

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
        $client = $this->client();
        $client->addSettings(['max_threads' => 3]);
        $this->assertSame([['v' => '3']], $client->getJsonData("SELECT toString(getSetting('max_threads')) AS v"));
    }

    public function testWriteRequiresReadWriteMode(): void
    {
        $client = $this->client();
        $table = 'chc_it_' . bin2hex(random_bytes(4));

        $e = $this->caughtException(fn() => $client->execute("CREATE TABLE $table (x UInt8) ENGINE = Memory"), ChcException::class);
        $this->assertSame(164, $e->getCode()); // READONLY

        $this->assertTrue($client->readOnly(false));
        try {
            $client->execute("CREATE TABLE $table (x UInt8) ENGINE = Memory");
            $client->execute("INSERT INTO $table VALUES (1), (2)");
            $client->readOnly(true);
            $this->assertSame([['c' => 2]], $client->getJsonData("SELECT toUInt8(count()) AS c FROM $table"));
        } finally {
            $client->readOnly(false);
            $client->execute("DROP TABLE IF EXISTS $table");
        }
    }

    public function testUnknownTableError(): void
    {
        $e = $this->caughtException(fn() => $this->client()->getJson('SELECT * FROM chc_no_such_table'), ChcException::class);
        $this->assertSame(60, $e->getCode()); // UNKNOWN_TABLE
        $this->assertStringContainsString('chc_no_such_table', (string)$e->getQuery());
    }

    public function testMemoryLimitError(): void
    {
        $client = $this->client();
        $client->addSettings(['max_memory_usage' => 10000000]);
        $e = $this->caughtException(fn() => $client->getJson('SELECT groupArray(number) FROM numbers(100000000)'), ChcMemoryLimitExceededException::class);
        $this->assertSame(241, $e->getCode());
    }

    public function testExceptionAfterHttp200IsDetected(): void
    {
        $client = $this->client();
        $client->addSettings(['max_block_size' => 1000]);
        $sql = "SELECT number, throwIf(number = 300000, 'boom') FROM numbers(400000)";

        $e = $this->caughtException(fn() => $client->getJson($sql), ChcException::class);
        $this->assertSame(395, $e->getCode()); // FUNCTION_THROW_IF_VALUE_IS_NON_ZERO

        $e = $this->caughtException(fn() => $client->getRaw($sql, ChcFormat::TabSeparated), ChcException::class);
        $this->assertSame(395, $e->getCode());
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
            ChcException::class,
        );
        $this->assertSame(60, $e->getCode());
    }
}
