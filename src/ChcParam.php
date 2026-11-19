<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * A typed query parameter whose name and type render a ClickHouse placeholder.
 *
 * Only the name and type are included in SQL; the value is sent separately as
 * the `param_<name>` HTTP parameter.
 *
 *     $types = ChcParam::Array(['click', 'view'], ChcType::String(), 'types');
 *     $client->fetchAll("SELECT * FROM events WHERE type IN $types", [$types]);
 */
final class ChcParam implements \Stringable
{
    private static int $counter = 0;

    public readonly mixed $value;
    public readonly ChcType $type;
    public readonly string $name;

    public static function Int8(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int8(), $name, $nullable);
    }

    public static function Int16(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int16(), $name, $nullable);
    }

    public static function Int32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int32(), $name, $nullable);
    }

    public static function Int64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int64(), $name, $nullable);
    }

    public static function Int128(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int128(), $name, $nullable);
    }

    public static function Int256(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int256(), $name, $nullable);
    }

    public static function UInt8(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt8(), $name, $nullable);
    }

    public static function UInt16(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt16(), $name, $nullable);
    }

    public static function UInt32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt32(), $name, $nullable);
    }

    public static function UInt64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt64(), $name, $nullable);
    }

    public static function UInt128(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt128(), $name, $nullable);
    }

    public static function UInt256(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt256(), $name, $nullable);
    }

    public static function Float32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Float32(), $name, $nullable);
    }

    public static function Float64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Float64(), $name, $nullable);
    }

    public static function Bool(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Bool(), $name, $nullable);
    }

    public static function String(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::String(), $name, $nullable);
    }

    public static function UUID(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UUID(), $name, $nullable);
    }

    public static function Date(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Date(), $name, $nullable);
    }

    public static function Date32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Date32(), $name, $nullable);
    }

    public static function IPv4(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::IPv4(), $name, $nullable);
    }

    public static function IPv6(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::IPv6(), $name, $nullable);
    }

    public static function DateTime(
        mixed $value,
        ?string $timezone = null,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::DateTime($timezone), $name, $nullable);
    }

    public static function DateTime64(
        mixed $value,
        int $precision,
        ?string $timezone = null,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::DateTime64($precision, $timezone), $name, $nullable);
    }

    public static function Decimal(
        mixed $value,
        int $precision,
        int $scale,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::Decimal($precision, $scale), $name, $nullable);
    }

    public static function FixedString(
        mixed $value,
        int $length,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::FixedString($length), $name, $nullable);
    }

    public static function Array(array $value, ChcType $item, ?string $name = null): self
    {
        return new self($value, ChcType::Array($item), $name);
    }

    public static function ArrayInt8(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int8(), $name);
    }

    public static function ArrayInt16(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int16(), $name);
    }

    public static function ArrayInt32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int32(), $name);
    }

    public static function ArrayInt64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int64(), $name);
    }

    public static function ArrayInt128(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int128(), $name);
    }

    public static function ArrayInt256(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int256(), $name);
    }

    public static function ArrayUInt8(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt8(), $name);
    }

    public static function ArrayUInt16(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt16(), $name);
    }

    public static function ArrayUInt32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt32(), $name);
    }

    public static function ArrayUInt64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt64(), $name);
    }

    public static function ArrayUInt128(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt128(), $name);
    }

    public static function ArrayUInt256(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt256(), $name);
    }

    public static function ArrayFloat32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Float32(), $name);
    }

    public static function ArrayFloat64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Float64(), $name);
    }

    public static function ArrayBool(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Bool(), $name);
    }

    public static function ArrayString(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::String(), $name);
    }

    public static function ArrayUUID(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UUID(), $name);
    }

    public static function ArrayDate(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Date(), $name);
    }

    public static function ArrayDateTime(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::DateTime(), $name);
    }

    public static function ArrayDate32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Date32(), $name);
    }

    public static function ArrayIPv4(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::IPv4(), $name);
    }

    public static function ArrayIPv6(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::IPv6(), $name);
    }

    public static function Nullable(mixed $value, ChcType $type, ?string $name = null): self
    {
        return new self($value, ChcType::Nullable($type), $name);
    }

    public static function LowCardinality(mixed $value, ChcType $type, ?string $name = null): self
    {
        return new self($value, ChcType::LowCardinality($type), $name);
    }

    public static function Map(array $value, ChcType $key, ChcType $valueType, ?string $name = null): self
    {
        return new self($value, ChcType::Map($key, $valueType), $name);
    }

    public function __construct(mixed $value, ChcType|string $type, ?string $name = null)
    {
        $this->type = is_string($type) ? ChcType::of($type) : $type;
        $this->type->assertParameterValue($value);
        $this->value = $value;
        $this->name = $name ?? 'chc_' . ++self::$counter;

        if (!ChcParamEncoder::isValidParameterName($this->name)) {
            throw new \InvalidArgumentException('Invalid query parameter name: ' . var_export($this->name, true));
        }
    }

    public static function of(mixed $value, ChcType|string $type, ?string $name = null): self
    {
        return new self($value, $type, $name);
    }

    public function __toString(): string
    {
        return '{' . $this->name . ':' . $this->type . '}';
    }

    private static function typed(mixed $value, ChcType $type, ?string $name, bool $nullable): self
    {
        if (null === $value && !$nullable) {
            throw new \InvalidArgumentException(
                'Query parameter value is null but the type is not Nullable; use nullable: true or ChcParam::Nullable()',
            );
        }

        return new self($value, $nullable ? ChcType::Nullable($type) : $type, $name);
    }
}
