<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcReadOnly;
use SevaCode\ClickHouseClient\Exceptions\ChcServerException;
use SevaCode\ClickHouseClient\ChcParam;
use SevaCode\ClickHouseClient\ChcType;

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

    public function testTypedParametersInFetchAndIterate(): void
    {
        $types = ChcParam::of(['click', 'view'], ChcType::Array(ChcType::String()), 'types');
        $id = ChcParam::of(42, ChcType::UInt64());
        $at = ChcParam::of(new \DateTimeImmutable('2024-01-02 03:04:05'), ChcType::DateTime(), 'at');
        $nullable = ChcParam::of(null, ChcType::Nullable(ChcType::String()), 'nullable');

        $sql = "SELECT has($types, {kind:String}) AS has_type, toString({$id}) AS id, {$at} AS at, isNull({$nullable}) AS is_null "
            . "WHERE {kind:String} IN $types";
        $this->assertSame([[
            'has_type' => 1,
            'id' => '42',
            'at' => '2024-01-02 03:04:05',
            'is_null' => 1,
        ]], $this->client()->fetchAll($sql, [$types, $id, $at, $nullable, 'kind' => 'click']));

        $iterated = iterator_to_array($this->client()->iterate("SELECT toString({$id}) AS id", [$id]));
        $this->assertSame([['id' => '42']], $iterated);
    }

    public function testNamedFactoriesAgainstClickHouse(): void
    {
        $id = ChcParam::UInt64(42);
        $nullable = ChcParam::String(null, nullable: true);
        $types = ChcParam::Array(['click', 'view'], ChcType::String());
        $map = ChcParam::Map(['a' => 1], ChcType::String(), ChcType::UInt64());
        $at = ChcParam::DateTime64(new \DateTimeImmutable('2024-01-02 03:04:05.123'), 3, 'UTC');
        $amount = ChcParam::Decimal('12.3400', 18, 4);

        $sql = "SELECT toString($id) AS id, isNull($nullable) AS is_null, has($types, {kind:String}) AS has_click, "
            . "toString({$map}['a']) AS mapped, toString($at) AS at, "
            . "toDecimal64($amount, 4) = toDecimal64(12.34, 4) AS amount_matches "
            . "WHERE {kind:String} IN $types";

        $this->assertSame([[
            'id' => '42',
            'is_null' => 1,
            'has_click' => 1,
            'mapped' => '1',
            'at' => '2024-01-02 03:04:05.123',
            'amount_matches' => 1,
        ]], $this->client()->fetchAll($sql, [$id, $nullable, $types, $map, $at, $amount, 'kind' => 'click']));
    }

    public function testDateTimeFactoriesPreserveBothInstantsInARepeatedLocalHour(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $first = (new \DateTimeImmutable('2024-10-27T00:30:00Z'))->setTimezone($berlin);
        $second = (new \DateTimeImmutable('2024-10-27T01:30:00Z'))->setTimezone($berlin);
        $inferred = ChcParam::DateTime($second);
        $override = ChcParam::DateTime($second, timezone: 'UTC');
        $array = ChcParam::ArrayDateTime([$first, $second]);

        $this->assertSame([
            'inferred' => '2024-10-27 02:30:00',
            'inferred_epoch' => $second->getTimestamp(),
            'override' => '2024-10-27 01:30:00',
            'override_epoch' => $second->getTimestamp(),
            'timestamps' => [$first->getTimestamp(), $second->getTimestamp()],
        ], $this->client()->fetchRow(
            "SELECT toString($inferred) AS inferred, toUnixTimestamp($inferred) AS inferred_epoch, "
            . "toString($override) AS override, toUnixTimestamp($override) AS override_epoch, "
            . "arrayMap(x -> toUnixTimestamp(x), $array) AS timestamps",
            [$inferred, $override, $array],
        ));
    }

    public function testDateTimeFactoriesHandleFixedOffsetsAndMixedValuesWithAnExplicitTimezone(): void
    {
        $date = new \DateTimeImmutable('2024-01-02T01:00:00+03:00');
        $epoch = new \DateTimeImmutable('1970-01-01T03:00:00+03:00');
        $next = new \DateTimeImmutable('1970-01-01T03:00:42+03:00');
        $inferred = ChcParam::DateTime($date);
        $early = ChcParam::DateTime($epoch);
        $array = ChcParam::ArrayDateTime([$epoch, $next, $date]);
        $mixed = ChcParam::ArrayDateTime(
            [$epoch, '2024-01-02 03:04:05', new \DateTimeImmutable('2024-01-02T05:34:05+05:30')],
            timezone: 'Europe/Moscow',
        );

        $this->assertSame([
            'inferred' => $date->getTimestamp(),
            'inferred_zone' => 'Etc/GMT-3',
            'local_time' => '2024-01-02 01:00:00',
            'local_date' => '2024-01-02',
            'local_day_start' => '2024-01-02 00:00:00',
            'early' => 0,
            'timestamps' => [0, 42, $date->getTimestamp()],
            'array_dates' => ['1970-01-01', '1970-01-01', '2024-01-02'],
            'mixed' => ['1970-01-01 03:00:00', '2024-01-02 03:04:05', '2024-01-02 03:04:05'],
        ], $this->client()->fetchRow(
            "SELECT toUnixTimestamp($inferred) AS inferred, timezoneOf($inferred) AS inferred_zone, "
            . "toString($inferred) AS local_time, toDate($inferred) AS local_date, "
            . "toString(toStartOfDay($inferred)) AS local_day_start, toUnixTimestamp($early) AS early, "
            . "arrayMap(x -> toUnixTimestamp(x), $array) AS timestamps, arrayMap(x -> toDate(x), $array) AS array_dates, "
            . "arrayMap(x -> toString(x), $mixed) AS mixed",
            [$inferred, $early, $array, $mixed],
        ));
    }

    public function testPerQuerySettings(): void
    {
        $client = $this->client();
        $this->assertSame('7', $client->fetchOne("SELECT getSetting('max_threads') AS v", [], ['max_threads' => 7]) . '');
        $this->assertNotSame('7', $client->fetchOne("SELECT getSetting('max_threads') AS v") . '');
    }

    public function testExactNumericRepresentationsAgainstClickHouse(): void
    {
        $max = '18446744073709551615';
        $id = ChcParam::UInt64($max);
        $ids = ChcParam::ArrayUInt64([$max]);
        $amounts = ChcParam::Array(['12.3400', null], ChcType::Nullable(ChcType::Decimal(18, 4)));

        $this->assertSame([
            'id' => $max,
            'first_id' => $max,
            'amount_matches' => 1,
            'is_null' => 1,
        ], $this->client()->fetchRow(
            "SELECT toString($id) AS id, toString({$ids}[1]) AS first_id, "
            . "{$amounts}[1] = toDecimal64(12.34, 4) AS amount_matches, isNull({$amounts}[2]) AS is_null",
            [$id, $ids, $amounts],
        ));
    }

    public function testWrite(): void
    {
        $client = $this->client();
        $table = 'chc_it_' . bin2hex(random_bytes(4));

        $e = $this->caughtException(fn() => $client->write("CREATE TABLE $table (x UInt8) ENGINE = Memory"), ChcServerException::class);
        $this->assertSame(164, $e->getCode()); // READONLY

        $writeClient = $client->withReadOnly(ChcReadOnly::Disabled);
        try {
            $writeClient->write("CREATE TABLE $table (x UInt8, s String) ENGINE = Memory");
            $writeClient->write("INSERT INTO $table VALUES ({x:UInt8}, {s:String})", ['x' => 1, 's' => "it's"]);
            $this->assertSame([['x' => 1, 's' => "it's"]], $client->fetchAll("SELECT * FROM $table WHERE s = {s:String}", ['s' => "it's"]));
        } finally {
            $writeClient->write("DROP TABLE IF EXISTS $table");
        }
    }

    public function testMissingParameterIsServerError(): void
    {
        $e = $this->caughtException(fn() => $this->client()->fetchOne('SELECT {v:UInt8}'), ChcServerException::class);
        $this->assertSame(456, $e->getCode()); // UNKNOWN_QUERY_PARAMETER
    }

    public function testReadOnlyQueriesUsePost(): void
    {
        $client = $this->client();
        $long = str_repeat('x', 100000);
        $this->assertSame(100000, $client->fetchOne('SELECT toUInt32(length({s:String})) AS v', ['s' => $long]));
        $this->assertSame(100000, $client->fetchOne("SELECT toUInt32(length('$long')) AS v"));

        $e = $this->caughtException(fn() => $client->execute('CREATE TABLE chc_it_ro (x UInt8) ENGINE = Memory'), ChcServerException::class);
        $this->assertSame(164, $e->getCode()); // READONLY
    }

    public function testCredentialsAndTimeout(): void
    {
        $client = $this->client();
        $client = $client->withCredentials(getenv('CLICKHOUSE_USER') ?: 'default', getenv('CLICKHOUSE_PASSWORD') ?: '')
            ->withTimeout(5.0);
        $this->assertSame(1, $client->fetchOne('SELECT toUInt8(1) AS v'));

        $client = $client->withCredentials('chc_no_such_user', 'x');
        $e = $this->caughtException(fn() => $client->fetchOne('SELECT 1'), ChcServerException::class);
        $this->assertContains($e->getCode(), [192, 516]); // UNKNOWN_USER / AUTHENTICATION_FAILED
    }
}
