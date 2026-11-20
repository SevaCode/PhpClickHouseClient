<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcParam;
use SevaCode\ClickHouseClient\ChcParamEncoder;
use SevaCode\ClickHouseClient\ChcType;
use SevaCode\ClickHouseClient\Tests\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ChcParamTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function scalarFactories(): iterable
    {
        foreach ([
            'Int8', 'Int16', 'Int32', 'Int64', 'Int128', 'Int256',
            'UInt8', 'UInt16', 'UInt32', 'UInt64', 'UInt128', 'UInt256',
            'Float32', 'Float64', 'Bool', 'String', 'UUID', 'Date', 'Date32', 'IPv4', 'IPv6',
        ] as $factory) {
            yield $factory => [$factory, match ($factory) {
                'Bool' => false,
                'String' => 'hello',
                'UUID' => '00000000-0000-0000-0000-000000000001',
                'Date', 'Date32' => '2024-01-02',
                'IPv4' => '127.0.0.1',
                'IPv6' => '::1',
                default => 1,
            }];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function arrayFactories(): iterable
    {
        foreach ([
            'Int8', 'Int16', 'Int32', 'Int64', 'Int128', 'Int256',
            'UInt8', 'UInt16', 'UInt32', 'UInt64', 'UInt128', 'UInt256',
            'Float32', 'Float64', 'Bool', 'String', 'UUID', 'Date', 'Date32', 'DateTime', 'IPv4', 'IPv6',
        ] as $itemType) {
            $factory = 'Array' . $itemType;
            yield $factory => [$factory, $itemType];
        }
    }

    /**
     * @dataProvider scalarFactories
     */
    public function testScalarFactories(string $factory, mixed $value): void
    {
        $param = call_user_func([ChcParam::class, $factory], $value, 'n');
        $this->assertInstanceOf(ChcParam::class, $param);
        $this->assertSame('{n:' . $factory . '}', (string)$param);
        $this->assertSame($value, $param->value);

        $nullable = call_user_func([ChcParam::class, $factory], $value, 'n', true);
        $this->assertInstanceOf(ChcParam::class, $nullable);
        $this->assertSame('{n:Nullable(' . $factory . ')}', (string)$nullable);
    }

    /** @return iterable<string, array{ChcType, mixed, string}> */
    public static function invalidValues(): iterable
    {
        yield 'bool as string' => [ChcType::String(), false, 'for String: bool'];
        yield 'int as string' => [ChcType::String(), 42, 'for String: int'];
        yield 'nullable invalid non-null' => [ChcType::Nullable(ChcType::String()), 42, 'for String: int'];
        yield 'int as bool' => [ChcType::Bool(), 1, 'for Bool: int'];
        yield 'string as float' => [ChcType::Float64(), '1.25', 'for Float64: string'];
        yield 'float as decimal' => [ChcType::Decimal(18, 4), 12.34, 'for Decimal(18, 4): float'];
        yield 'exponent as decimal' => [ChcType::Decimal(18, 4), '1.2e1', 'for Decimal(18, 4): string'];
        yield 'date object' => [ChcType::Date(), new \DateTimeImmutable(), 'for Date: DateTimeImmutable'];
        yield 'timestamp as datetime' => [ChcType::DateTime(), 1, 'for DateTime: int'];
        yield 'fraction as integer' => [ChcType::Int64(), '1.0', 'for Int64: string'];
        yield 'padded integer' => [ChcType::Int64(), '001', 'for Int64: string'];
        yield 'int8 underflow' => [ChcType::Int8(), -129, 'for Int8: int'];
        yield 'int8 overflow' => [ChcType::Int8(), 128, 'for Int8: int'];
        yield 'uint64 negative' => [ChcType::UInt64(), -1, 'for UInt64: int'];
        yield 'uint64 overflow' => [ChcType::UInt64(), '18446744073709551616', 'for UInt64: string'];
        yield 'array uint64 overflow' => [
            ChcType::Array(ChcType::UInt64()), ['18446744073709551616'], 'value[0] for UInt64: string',
        ];
        yield 'int128 underflow' => [ChcType::Int128(), '-170141183460469231731687303715884105729', 'for Int128: string'];
        yield 'int256 overflow' => [
            ChcType::Int256(), '57896044618658097711785492504343953926634992332820282019728792003956564819968',
            'for Int256: string',
        ];
        yield 'array must be a list' => [ChcType::Array(ChcType::String()), ['k' => 'v'], 'for Array(String): array'];
        yield 'nested invalid element' => [
            ChcType::Array(ChcType::Array(ChcType::Nullable(ChcType::String()))),
            [[null, 1]],
            'value[0][1] for String: int',
        ];
    }

    /** @dataProvider invalidValues */
    public function testRejectsIncompatibleValuesAtConstruction(ChcType $type, mixed $value, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        ChcParam::of($value, $type);
    }

    public function testFactoryRejectsIncompatibleStringValueWithoutPhpCoercion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('for String: bool');

        // Reflection invokes methods without PHP scalar strictness.
        (new \ReflectionMethod(ChcParam::class, 'String'))->invoke(null, false);
    }

    public function testExactRepresentationsArePreserved(): void
    {
        $max = '18446744073709551615';
        $min = '-170141183460469231731687303715884105728';
        $decimal = '12.3400';
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'text';
            }
        };
        $params = [
            ChcParam::UInt64($max, 'uint'),
            ChcParam::Int128($min, 'int'),
            ChcParam::Decimal($decimal, 18, 4, 'amount'),
            ChcParam::ArrayUInt64([$max, 1], 'uints'),
            ChcParam::Array([$decimal, null], ChcType::Nullable(ChcType::Decimal(18, 4)), 'amounts'),
            ChcParam::String($stringable, 'text'),
        ];

        $this->assertSame($max, $params[0]->value);
        $this->assertSame($decimal, $params[2]->value);
        $this->assertSame($stringable, $params[5]->value);
        $this->assertSame([
            'param_uint' => $max,
            'param_int' => $min,
            'param_amount' => $decimal,
            'param_uints' => '[' . $max . ',1]',
            'param_amounts' => '[' . $decimal . ',NULL]',
            'param_text' => 'text',
        ], ChcParamEncoder::toHttpParams($params));
    }

    public function testExplicitUInt64ArrayRejectsOverflowAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('value[0] for UInt64: string');

        ChcParam::of(['18446744073709551616'], 'Array(UInt64)', 'ids');
    }

    public function testEncodingDoesNotRecheckIntegerRange(): void
    {
        $value = '18446744073709551615';
        $param = ChcParam::ArrayUInt64([&$value], 'ids');

        $value = '18446744073709551616';

        $this->assertSame(['param_ids' => '[' . $value . ']'], ChcParamEncoder::toHttpParams([$param]));
    }

    /** @return iterable<string, array{ChcType, string, string}> */
    public static function malformedNumericLiterals(): iterable
    {
        yield 'integer with fractional part' => [ChcType::UInt64(), '1', '1.0'];
        yield 'integer with trailing newline' => [ChcType::UInt64(), '1', "1\n"];
        yield 'nullable decimal with exponent' => [ChcType::Nullable(ChcType::Decimal(18, 4)), '1.0000', '1e0'];
    }

    /** @dataProvider malformedNumericLiterals */
    public function testEncodingRejectsMalformedNumericStrings(ChcType $type, string $value, string $malformed): void
    {
        $param = ChcParam::Array([&$value], $type);
        $value = $malformed;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid numeric parameter literal');

        ChcParamEncoder::toHttpParams([$param]);
    }

    public function testRawTypeStringsKeepCallerControlledEncoding(): void
    {
        $this->assertSame([
            'param_raw' => '0',
            'param_custom' => '0',
            'param_plain' => '0',
        ], ChcParamEncoder::toHttpParams([
            ChcParam::of(false, 'String', 'raw'),
            ChcParam::of(false, ChcType::of('String'), 'custom'),
            'plain' => false,
        ]));
    }

    public function testNullableAndParameterizedFactories(): void
    {
        try {
            ChcParam::String(null);
            $this->fail('Expected null with a non-nullable type to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'Query parameter value is null but the type is not Nullable; use nullable: true or ChcParam::Nullable()',
                $e->getMessage(),
            );
        }

        $string = ChcParam::String(null, nullable: true);
        $nullable = ChcParam::Nullable(null, ChcType::String());
        $this->assertSame('{' . $string->name . ':Nullable(String)}', (string)$string);
        $this->assertSame('{' . $nullable->name . ':Nullable(String)}', (string)$nullable);
        $this->assertSame('\N', ChcParamEncoder::toHttpParams([$string])['param_' . $string->name]);
        $this->assertSame('\N', ChcParamEncoder::toHttpParams([$nullable])['param_' . $nullable->name]);

        $dateTime = ChcParam::DateTime(new \DateTimeImmutable('2024-01-02 03:04:05'), 'UTC', 'at');
        $dateTime64 = ChcParam::DateTime64(new \DateTimeImmutable('2024-01-02 03:04:05.123'), 3, 'UTC', 'at64');
        $decimal = ChcParam::Decimal('12.3400', 18, 4, 'amount');
        $fixedString = ChcParam::FixedString('abc', 16, 'fixed');

        $this->assertSame("{at:DateTime('UTC')}", (string)$dateTime);
        $this->assertSame("{at64:DateTime64(3, 'UTC')}", (string)$dateTime64);
        $this->assertSame('{amount:Decimal(18, 4)}', (string)$decimal);
        $this->assertSame('{fixed:FixedString(16)}', (string)$fixedString);
    }

    public function testCompositeFactoriesRenderAndEncodeTheirTypes(): void
    {
        $array = ChcParam::Array(['click', 'view'], ChcType::String(), 'types');
        $lowCardinality = ChcParam::LowCardinality('click', ChcType::String(), 'kind');
        $map = ChcParam::Map(['a' => 1], ChcType::String(), ChcType::UInt64(), 'mapping');

        $this->assertSame('{types:Array(String)}', (string)$array);
        $this->assertSame('{kind:LowCardinality(String)}', (string)$lowCardinality);
        $this->assertSame('{mapping:Map(String, UInt64)}', (string)$map);
        $this->assertSame("['click','view']", ChcParamEncoder::toHttpParams([$array])['param_types']);
        $this->assertSame('click', ChcParamEncoder::toHttpParams([$lowCardinality])['param_kind']);
        $this->assertSame("{'a':1}", ChcParamEncoder::toHttpParams([$map])['param_mapping']);

        $auto = ChcParam::UInt64(42);
        $this->assertMatchesRegularExpression('/^\{chc_[1-9][0-9]*:UInt64}$/', (string)$auto);
    }

    /**
     * @dataProvider arrayFactories
     */
    public function testSimpleArrayFactoriesAcceptEmptyArraysAndGenerateNames(string $factory, string $itemType): void
    {
        $named = call_user_func([ChcParam::class, $factory], [], 'items');
        $this->assertInstanceOf(ChcParam::class, $named);
        $this->assertSame('{items:Array(' . $itemType . ')}', (string)$named);
        $this->assertSame(['param_items' => '[]'], ChcParamEncoder::toHttpParams([$named]));

        $auto = call_user_func([ChcParam::class, $factory], []);
        $this->assertInstanceOf(ChcParam::class, $auto);
        $this->assertSame('Array(' . $itemType . ')', (string)$auto->type);
        $this->assertMatchesRegularExpression('/^chc_[1-9][0-9]*$/', $auto->name);
        $this->assertSame(['param_' . $auto->name => '[]'], ChcParamEncoder::toHttpParams([$auto]));
    }

    public function testArrayStringAndUInt64FactoriesEncodeValues(): void
    {
        $strings = ChcParam::ArrayString(['click', 'view'], 'types');
        $ids = ChcParam::ArrayUInt64([1, 2], 'ids');

        $this->assertSame('{types:Array(String)}', (string)$strings);
        $this->assertSame('{ids:Array(UInt64)}', (string)$ids);
        $this->assertSame(
            ['param_types' => "['click','view']", 'param_ids' => '[1,2]'],
            ChcParamEncoder::toHttpParams([$strings, $ids]),
        );
    }

    public function testArrayDateTimeFactoryUsesTheDefaultTimezoneAndSupportsExplicitTypes(): void
    {
        $value = new \DateTimeImmutable('2024-01-02 03:04:05');
        $dateTimes = ChcParam::ArrayDateTime([$value], 'times');
        $dateTimesWithTimezone = ChcParam::Array([$value], ChcType::DateTime('UTC'), 'times_utc');

        $this->assertSame('{times:Array(DateTime)}', (string)$dateTimes);
        $this->assertSame("['2024-01-02 03:04:05']", ChcParamEncoder::toHttpParams([$dateTimes])['param_times']);
        $this->assertSame("{times_utc:Array(DateTime('UTC'))}", (string)$dateTimesWithTimezone);
    }

    public function testArrayFactoriesRejectInvalidNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChcParam::ArrayString([], 'invalid-name');
    }

    public function testNamedAndAutoNamedPlaceholders(): void
    {
        $named = ChcParam::of(['click', 'view'], ChcType::Array(ChcType::String()), 'types');
        $auto = ChcParam::of(42, ChcType::UInt64());
        $next = ChcParam::of(43, 'UInt64');

        $this->assertSame('{types:Array(String)}', (string)$named);
        $this->assertMatchesRegularExpression('/^\{chc_[1-9][0-9]*:UInt64}$/', (string)$auto);
        $this->assertMatchesRegularExpression('/^\{chc_[1-9][0-9]*:UInt64}$/', (string)$next);
        $this->assertNotSame($auto->name, $next->name);
        $this->assertSame('UInt64', (string)$next->type);
        $this->assertSame('UInt64', (string)ChcParam::of(1, 'UInt64', 'string_type')->type);
        $this->assertSame(['param_types' => "['click','view']"], ChcParamEncoder::toHttpParams([$named]));

        try {
            ChcParam::of(1, ChcType::UInt64(), 'not-valid');
            $this->fail('Expected invalid parameter name to be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTypedAndPlainParametersCanBeMixed(): void
    {
        $param = ChcParam::of(['click', 'view'], ChcType::Array(ChcType::String()), 'types');

        $this->assertSame(
            ['param_id' => '42', 'param_types' => "['click','view']"],
            ChcParamEncoder::toHttpParams(['id' => 42, $param]),
        );
        $this->assertSame(
            ['param_types' => "['click','view']"],
            ChcParamEncoder::toHttpParams(['types' => $param]),
        );
    }

    public function testRejectsMismatchedUnusedAndDuplicateParameters(): void
    {
        $param = ChcParam::of(1, ChcType::UInt64(), 'id');

        try {
            ChcParamEncoder::toHttpParams(['other' => $param]);
            $this->fail('Expected mismatched parameter key to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Query parameter key 'other' does not match ChcParam name 'id'", $e->getMessage());
        }

        try {
            ChcParamEncoder::toHttpParams([$param], 'SELECT 1');
            $this->fail('Expected unused typed parameter to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Query parameter {id:UInt64} is not used in the query', $e->getMessage());
        }

        $this->assertSame(['param_id' => '1'], ChcParamEncoder::toHttpParams([$param]));
        $this->assertSame(['param_id' => '1'], ChcParamEncoder::toHttpParams([$param], 'SELECT {id:UInt64}'));
        $this->assertSame(['param_id' => '1'], ChcParamEncoder::toHttpParams(['id' => 1], 'SELECT 1'));

        try {
            ChcParamEncoder::toHttpParams(['id' => 1, $param]);
            $this->fail('Expected duplicate parameter to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Duplicate query parameter: id', $e->getMessage());
        }
    }

    public function testTypedParametersCannotBeNestedAndNullableNullIsEncoded(): void
    {
        $nested = ChcParam::of(1, ChcType::UInt64(), 'inner');
        foreach ([
            static fn(): array => ['outer' => [$nested]],
            static fn(): array => ['outer' => ChcParam::of($nested, ChcType::String(), 'outer')],
            static fn(): array => ['outer' => ChcParam::of($nested, 'String', 'outer')],
            static fn(): array => ['outer' => ChcType::String()],
        ] as $params) {
            try {
                ChcParamEncoder::toHttpParams($params());
                $this->fail('Expected nested typed parameter to be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('ChcParam cannot be nested', $e->getMessage());
            }
        }

        $nullable = ChcParam::of(null, ChcType::Nullable(ChcType::String()), 'nullable');
        $this->assertSame(['param_nullable' => '\N'], ChcParamEncoder::toHttpParams([$nullable]));
    }

    public function testClientSendsUsedTypedParameterAndRejectsUnusedBeforeRequest(): void
    {
        $requests = [];
        $client = new ChcClient('http://localhost:8123/', new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url, $options];
                return new MockResponse('{"data":[],"meta":[],"rows":0}');
            },
            'http://localhost:8123/',
        ));
        $param = ChcParam::of(['click', 'view'], ChcType::Array(ChcType::String()), 'types');

        $client->fetchAll("SELECT * FROM events WHERE type IN $param", [$param]);

        $this->assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        $this->assertSame('POST', $method);
        $this->assertStringContainsString('type IN {types:Array(String)}', $options['body']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame("['click','view']", $query['param_types']);

        $requests = [];
        try {
            $client->fetchAll('SELECT 1', [$param]);
            $this->fail('Expected unused typed parameter to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('is not used in the query', $e->getMessage());
        }
        $this->assertCount(0, $requests);
    }
}
