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
