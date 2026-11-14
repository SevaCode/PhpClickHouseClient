<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcType;
use SevaCode\ClickHouseClient\Tests\TestCase;

class ChcTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ChcType}>
     */
    public static function scalarTypes(): iterable
    {
        yield 'Int8' => ['Int8', ChcType::Int8()];
        yield 'Int16' => ['Int16', ChcType::Int16()];
        yield 'Int32' => ['Int32', ChcType::Int32()];
        yield 'Int64' => ['Int64', ChcType::Int64()];
        yield 'Int128' => ['Int128', ChcType::Int128()];
        yield 'Int256' => ['Int256', ChcType::Int256()];
        yield 'UInt8' => ['UInt8', ChcType::UInt8()];
        yield 'UInt16' => ['UInt16', ChcType::UInt16()];
        yield 'UInt32' => ['UInt32', ChcType::UInt32()];
        yield 'UInt64' => ['UInt64', ChcType::UInt64()];
        yield 'UInt128' => ['UInt128', ChcType::UInt128()];
        yield 'UInt256' => ['UInt256', ChcType::UInt256()];
        yield 'Float32' => ['Float32', ChcType::Float32()];
        yield 'Float64' => ['Float64', ChcType::Float64()];
        yield 'Bool' => ['Bool', ChcType::Bool()];
        yield 'String' => ['String', ChcType::String()];
        yield 'UUID' => ['UUID', ChcType::UUID()];
        yield 'Date' => ['Date', ChcType::Date()];
        yield 'Date32' => ['Date32', ChcType::Date32()];
        yield 'IPv4' => ['IPv4', ChcType::IPv4()];
        yield 'IPv6' => ['IPv6', ChcType::IPv6()];
    }

    /**
     * @dataProvider scalarTypes
     */
    public function testScalarFactories(string $expected, ChcType $type): void
    {
        $this->assertSame($expected, (string)$type);
    }

    public function testCompositeAndParameterizedFactories(): void
    {
        $this->assertSame('Array(Nullable(String))', (string)ChcType::Array(ChcType::Nullable(ChcType::String())));
        $this->assertSame('Map(String, UInt64)', (string)ChcType::Map(ChcType::String(), ChcType::UInt64()));
        $this->assertSame('Tuple(UInt8, String)', (string)ChcType::Tuple(ChcType::UInt8(), ChcType::String()));
        $this->assertSame('LowCardinality(String)', (string)ChcType::LowCardinality(ChcType::String()));
        $this->assertSame('DateTime', (string)ChcType::DateTime());
        $this->assertSame("DateTime('Europe/Moscow')", (string)ChcType::DateTime('Europe/Moscow'));
        $this->assertSame('DateTime64(3)', (string)ChcType::DateTime64(3));
        $this->assertSame("DateTime64(3, 'UTC')", (string)ChcType::DateTime64(3, 'UTC'));
        $this->assertSame("DateTime('Europe\\'s\\\\zone')", (string)ChcType::DateTime("Europe's\\zone"));
        $this->assertSame('Decimal(18, 4)', (string)ChcType::Decimal(18, 4));
        $this->assertSame('FixedString(16)', (string)ChcType::FixedString(16));
    }

    public function testOfValidatesParenthesesAndAllowsOtherTypes(): void
    {
        $this->assertSame('LowCardinality(Nullable(String))', (string)ChcType::of('LowCardinality(Nullable(String))'));

        foreach (['', '   ', 'Array(String', 'String)', 'Array())'] as $type) {
            try {
                ChcType::of($type);
                $this->fail('Expected invalid ClickHouse type: ' . $type);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            ChcType::Tuple();
            $this->fail('Expected Tuple() to be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
