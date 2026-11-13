<?php

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcException;

class QueryParamsTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function values(): iterable
    {
        $tricky = "O'Reilly \\ \"q\" \t tab \n nl {x:String} ; DROP TABLE t; --";
        yield 'string' => ['String', $tricky, $tricky];
        yield 'string null byte' => ['String', "a\0b", "a\0b"];
        yield 'empty string' => ['String', '', ''];
        yield 'unicode' => ['String', 'Привет 🌍', 'Привет 🌍'];
        yield 'uint32' => ['UInt32', 5, 5];
        yield 'int' => ['Int32', -7, -7];
        yield 'float' => ['Float64', 1.25, 1.25];
        yield 'bool true' => ['Bool', true, true];
        yield 'bool false' => ['Bool', false, false];
        yield 'bool as uint8' => ['UInt8', true, 1];
        yield 'null' => ['Nullable(String)', null, null];
        yield 'date' => ['Date', '2024-01-02', '2024-01-02'];
        yield 'datetime' => ['DateTime', new \DateTimeImmutable('2024-01-02 03:04:05'), '2024-01-02 03:04:05'];
        yield 'datetime64' => ['DateTime64(6)', new \DateTimeImmutable('2024-01-02 03:04:05.123456'), '2024-01-02 03:04:05.123456'];
        yield 'array string' => ['Array(String)', ['a', $tricky], ['a', $tricky]];
        yield 'array int' => ['Array(UInt8)', [1, 2], [1, 2]];
        yield 'array empty' => ['Array(UInt8)', [], []];
        yield 'array bool' => ['Array(Bool)', [true, false], [true, false]];
        yield 'array nullable' => ['Array(Nullable(UInt8))', [1, null], [1, null]];
        yield 'nested array' => ['Array(Array(String))', [['a'], ['b', 'c']], [['a'], ['b', 'c']]];
        yield 'array datetime' => ['Array(DateTime)', [new \DateTime('2024-01-02 03:04:05')], ['2024-01-02 03:04:05']];
        yield 'map' => ['Map(String, UInt8)', ['a' => 1, "k'" => 2], ['a' => 1, "k'" => 2]];
    }

    /**
     * @dataProvider values
     */
    public function testRoundTrip(string $type, mixed $value, mixed $expected): void
    {
        $actual = $this->client()->fetchOne("SELECT {v:$type} AS v", ['v' => $value]);
        $this->assertSame($expected, $actual);
    }

    public function testFetchHelpers(): void
    {
        $client = $this->client();
        $sql = 'SELECT toUInt8(number) AS n, toString(number) AS s FROM numbers({limit:UInt8}) WHERE n IN {in:Array(UInt8)}';
        $params = ['limit' => 5, 'in' => [1, 3]];

        $this->assertSame([['n' => 1, 's' => '1'], ['n' => 3, 's' => '3']], $client->fetchAll($sql, $params));
        $this->assertSame(['n' => 1, 's' => '1'], $client->fetchRow($sql, $params));
        $this->assertSame(1, $client->fetchOne($sql, $params));
        $this->assertSame([1, 3], $client->fetchColumn($sql, $params));
        $this->assertNull($client->fetchRow($sql, ['limit' => 0, 'in' => []]));
        $this->assertNull($client->fetchOne($sql, ['limit' => 0, 'in' => []]));
        $this->assertSame([], $client->fetchColumn($sql, ['limit' => 0, 'in' => []]));
    }

    public function testPerQuerySettings(): void
    {
        $client = $this->client();
        $this->assertSame('7', $client->fetchOne("SELECT getSetting('max_threads') AS v", [], ['max_threads' => 7]) . '');
        $this->assertNotSame('7', $client->fetchOne("SELECT getSetting('max_threads') AS v") . '');
    }

    public function testWrite(): void
    {
        $client = $this->client();
        $table = 'chc_it_' . bin2hex(random_bytes(4));

        $e = $this->caughtException(fn() => $client->write("CREATE TABLE $table (x UInt8) ENGINE = Memory"), ChcException::class);
        $this->assertSame(164, $e->getCode()); // READONLY

        $client->readOnly(false);
        try {
            $client->write("CREATE TABLE $table (x UInt8, s String) ENGINE = Memory");
            $client->write("INSERT INTO $table VALUES ({x:UInt8}, {s:String})", ['x' => 1, 's' => "it's"]);
            $client->readOnly(true);
            $this->assertSame([['x' => 1, 's' => "it's"]], $client->fetchAll("SELECT * FROM $table WHERE s = {s:String}", ['s' => "it's"]));
        } finally {
            $client->readOnly(false);
            $client->write("DROP TABLE IF EXISTS $table");
        }
    }

    public function testMissingParameterIsServerError(): void
    {
        $e = $this->caughtException(fn() => $this->client()->fetchOne('SELECT {v:UInt8}'), ChcException::class);
        $this->assertSame(456, $e->getCode()); // UNKNOWN_QUERY_PARAMETER
    }

    public function testReadOnlyOverPost(): void
    {
        $client = $this->client();
        $client->setReadOnlyOverPost(true);
        $long = str_repeat('x', 100000);
        $this->assertSame(100000, $client->fetchOne('SELECT toUInt32(length({s:String})) AS v', ['s' => $long]));
        $this->assertSame(100000, $client->fetchOne("SELECT toUInt32(length('$long')) AS v"));

        $e = $this->caughtException(fn() => $client->execute('CREATE TABLE chc_it_ro (x UInt8) ENGINE = Memory'), ChcException::class);
        $this->assertSame(164, $e->getCode()); // READONLY
    }

    public function testCredentialsAndTimeout(): void
    {
        $client = $this->client();
        $client->setCredentials(getenv('CLICKHOUSE_USER') ?: 'default', getenv('CLICKHOUSE_PASSWORD') ?: '');
        $client->setTimeout(5.0);
        $this->assertSame(1, $client->fetchOne('SELECT toUInt8(1) AS v'));

        $client->setCredentials('chc_no_such_user', 'x');
        $e = $this->caughtException(fn() => $client->fetchOne('SELECT 1'), ChcException::class);
        $this->assertContains($e->getCode(), [192, 516]); // UNKNOWN_USER / AUTHENTICATION_FAILED
    }
}
