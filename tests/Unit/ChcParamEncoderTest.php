<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcParamEncoder;
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
