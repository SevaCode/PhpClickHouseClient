<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcParam;
use SevaCode\ClickHouseClient\ChcParamEncoder;
use SevaCode\ClickHouseClient\ChcType;
use SevaCode\ClickHouseClient\Tests\TestCase;

class ChcParamEncoderTest extends TestCase
{
    public function testEncodesScalars(): void
    {
        $this->assertSame('\N', ChcParamEncoder::encode(null));
        $this->assertSame('1', ChcParamEncoder::encode(true));
        $this->assertSame('0', ChcParamEncoder::encode(false));
        $this->assertSame('-42', ChcParamEncoder::encode(-42));
        $this->assertSame('1.5', ChcParamEncoder::encode(1.5));
        $this->assertSame('1.0', ChcParamEncoder::encode(1.0));
        $this->assertSame('inf', ChcParamEncoder::encode(INF));
        $this->assertSame('nan', ChcParamEncoder::encode(NAN));
        $this->assertSame("O'Reilly", ChcParamEncoder::encode("O'Reilly"));
        $this->assertSame('a\\\\b\\tc\\nd\\0', ChcParamEncoder::encode("a\\b\tc\nd\0"));
        $this->assertSame('2024-01-02 03:04:05', ChcParamEncoder::encode(new \DateTimeImmutable('2024-01-02 03:04:05')));
        $this->assertSame('2024-01-02 03:04:05.123456', ChcParamEncoder::encode(new \DateTimeImmutable('2024-01-02 03:04:05.123456')));
    }

    public function testEncodesArraysAndMaps(): void
    {
        $this->assertSame('[]', ChcParamEncoder::encode([]));
        $this->assertSame('[1,2,3]', ChcParamEncoder::encode([1, 2, 3]));
        $this->assertSame("['a','O\\'R','x\\\\y']", ChcParamEncoder::encode(['a', "O'R", 'x\\y']));
        $this->assertSame('[true,false,NULL]', ChcParamEncoder::encode([true, false, null]));
        $this->assertSame('[[1,2],[3]]', ChcParamEncoder::encode([[1, 2], [3]]));
        $this->assertSame("{'a':1,'b':2}", ChcParamEncoder::encode(['a' => 1, 'b' => 2]));
        $this->assertSame("['2024-01-02 03:04:05']", ChcParamEncoder::encode([new \DateTime('2024-01-02 03:04:05')]));
    }

    public function testBuildsHttpParams(): void
    {
        $this->assertSame(
            ['param_id' => '5', 'param_tags' => "['a','b']"],
            ChcParamEncoder::toHttpParams(['id' => 5, 'tags' => ['a', 'b']]),
        );
    }

    public function testEncodesStringIdsOnlyForTypedUInt64Arrays(): void
    {
        $ids = [42, '0', '9223372036854775808', '18446744073709551615'];
        $literal = '[42,0,9223372036854775808,18446744073709551615]';
        $strings = ['00042', '18446744073709551615'];

        $this->assertSame([
            'param_factory' => $literal,
            'param_generic' => $literal,
            'param_explicit' => $literal,
            'param_strings' => "['00042','18446744073709551615']",
            'param_raw' => "['00042','18446744073709551615']",
        ], ChcParamEncoder::toHttpParams([
            ChcParam::ArrayUInt64($ids, 'factory'),
            ChcParam::Array($ids, ChcType::UInt64(), 'generic'),
            ChcParam::of($ids, 'Array( UInt64 )', 'explicit'),
            ChcParam::ArrayString($strings, 'strings'),
            'raw' => $strings,
        ]));
    }

    public function testKeepsInvalidUInt64StringsQuoted(): void
    {
        $ids = ChcParam::ArrayUInt64(['18446744073709551616', '-1', '1,2', "1\n"], 'ids');

        $this->assertSame(
            ['param_ids' => "['18446744073709551616','-1','1,2','1\\n']"],
            ChcParamEncoder::toHttpParams([$ids]),
        );
    }

    public function testRejectsInvalidNamesAndValues(): void
    {
        foreach ([[0 => 1], ['a-b' => 1], ['1a' => 1], ['a b' => 1]] as $params) {
            try {
                ChcParamEncoder::toHttpParams($params);
                $this->fail('Expected InvalidArgumentException for ' . json_encode($params));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        ChcParamEncoder::encode(new \stdClass());
    }
}
