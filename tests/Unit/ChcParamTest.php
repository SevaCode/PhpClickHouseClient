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
     * @return iterable<string, array{string, string}>
     */
    public static function scalarFactories(): iterable
    {
        foreach ([
            'Int8', 'Int16', 'Int32', 'Int64', 'Int128', 'Int256',
            'UInt8', 'UInt16', 'UInt32', 'UInt64', 'UInt128', 'UInt256',
            'Float32', 'Float64', 'Bool', 'String', 'UUID', 'Date', 'Date32', 'IPv4', 'IPv6',
        ] as $factory) {
            yield $factory => [$factory, $factory];
        }
    }

    /**
     * @dataProvider scalarFactories
     */
    public function testScalarFactories(string $factory, string $type): void
    {
        $param = call_user_func([ChcParam::class, $factory], 1, 'n');
        $this->assertInstanceOf(ChcParam::class, $param);
        $this->assertSame('{n:' . $type . '}', (string)$param);

        $nullable = call_user_func([ChcParam::class, $factory], 1, 'n', true);
        $this->assertInstanceOf(ChcParam::class, $nullable);
        $this->assertSame('{n:Nullable(' . $type . ')}', (string)$nullable);
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
            ['outer' => [$nested]],
            ['outer' => ChcParam::of($nested, ChcType::String(), 'outer')],
            ['outer' => ChcType::String()],
        ] as $params) {
            try {
                ChcParamEncoder::toHttpParams($params);
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
