<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * A typed query parameter whose name and type render a ClickHouse placeholder.
 *
 * Only the name and type are included in SQL; the value is sent separately as
 * the `param_<name>` HTTP parameter.
 *
 * Scalar factories accept null only with `nullable: true`.
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

    /** @param int|numeric-string|null $value */
    public static function Int8(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int8(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Int16(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int16(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Int32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int32(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Int64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int64(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Int128(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int128(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Int256(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Int256(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt8(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt8(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt16(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt16(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt32(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt64(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt128(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt128(), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function UInt256(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UInt256(), $name, $nullable);
    }

    /** @param int|float|null $value */
    public static function Float32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Float32(), $name, $nullable);
    }

    /** @param int|float|null $value */
    public static function Float64(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Float64(), $name, $nullable);
    }

    /** @param bool|null $value */
    public static function Bool(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Bool(), $name, $nullable);
    }

    /** @param string|\Stringable|null $value */
    public static function String(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::String(), $name, $nullable);
    }

    /** @param string|null $value */
    public static function UUID(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::UUID(), $name, $nullable);
    }

    /** @param string|null $value */
    public static function Date(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Date(), $name, $nullable);
    }

    /** @param string|null $value */
    public static function Date32(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::Date32(), $name, $nullable);
    }

    /** @param string|null $value */
    public static function IPv4(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::IPv4(), $name, $nullable);
    }

    /** @param string|null $value */
    public static function IPv6(mixed $value, ?string $name = null, bool $nullable = false): self
    {
        return self::typed($value, ChcType::IPv6(), $name, $nullable);
    }

    /** @param string|\DateTimeInterface|null $value */
    public static function DateTime(
        mixed $value,
        ?string $timezone = null,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        if (null === $timezone && $value instanceof \DateTimeInterface) {
            $timezone = self::clickHouseTimezone(self::dateTimeTimezone($value));
        }

        return self::typed($value, ChcType::DateTime($timezone), $name, $nullable);
    }

    /** @param string|\DateTimeInterface|null $value */
    public static function DateTime64(
        mixed $value,
        int $precision,
        ?string $timezone = null,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::DateTime64($precision, $timezone), $name, $nullable);
    }

    /** @param int|numeric-string|null $value */
    public static function Decimal(
        mixed $value,
        int $precision,
        int $scale,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::Decimal($precision, $scale), $name, $nullable);
    }

    /** @param string|\Stringable|null $value */
    public static function FixedString(
        mixed $value,
        int $length,
        ?string $name = null,
        bool $nullable = false,
    ): self {
        return self::typed($value, ChcType::FixedString($length), $name, $nullable);
    }

    /** @param list<mixed> $value */
    public static function Array(array $value, ChcType $item, ?string $name = null): self
    {
        return new self($value, ChcType::Array($item), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt8(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int8(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt16(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int16(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int32(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int64(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt128(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int128(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayInt256(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Int256(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt8(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt8(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt16(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt16(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt32(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt64(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt128(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt128(), $name);
    }

    /** @param list<int|numeric-string> $value */
    public static function ArrayUInt256(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UInt256(), $name);
    }

    /** @param list<int|float> $value */
    public static function ArrayFloat32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Float32(), $name);
    }

    /** @param list<int|float> $value */
    public static function ArrayFloat64(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Float64(), $name);
    }

    /** @param list<bool> $value */
    public static function ArrayBool(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Bool(), $name);
    }

    /** @param list<string|\Stringable> $value */
    public static function ArrayString(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::String(), $name);
    }

    /** @param list<string> $value */
    public static function ArrayUUID(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::UUID(), $name);
    }

    /** @param list<string> $value */
    public static function ArrayDate(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Date(), $name);
    }

    /** @param list<string|\DateTimeInterface> $value */
    public static function ArrayDateTime(
        array $value,
        ?string $name = null,
        ?string $timezone = null,
    ): self {
        if (null === $timezone && array_is_list($value)) {
            $timezone = self::inferArrayDateTimeTimezone($value);
        }

        return self::Array($value, ChcType::DateTime($timezone), $name);
    }

    /** @param list<string> $value */
    public static function ArrayDate32(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::Date32(), $name);
    }

    /** @param list<string> $value */
    public static function ArrayIPv4(array $value, ?string $name = null): self
    {
        return self::Array($value, ChcType::IPv4(), $name);
    }

    /** @param list<string> $value */
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

    /** @param list<string|\DateTimeInterface> $value */
    private static function inferArrayDateTimeTimezone(array $value): ?string
    {
        $first = $value[0] ?? null;
        $timezone = $first instanceof \DateTimeInterface ? self::dateTimeTimezone($first) : null;
        $name = $timezone?->getName();
        foreach ($value as $item) {
            $itemName = $item instanceof \DateTimeInterface ? self::dateTimeTimezone($item)->getName() : null;
            if ($name !== $itemName) {
                throw new \InvalidArgumentException(
                    'ArrayDateTime requires an explicit timezone when mixing timezones or date objects and strings',
                );
            }
        }

        return null === $timezone ? null : self::clickHouseTimezone($timezone);
    }

    private static function dateTimeTimezone(\DateTimeInterface $value): \DateTimeZone
    {
        $timezone = $value->getTimezone();
        if (false === $timezone) {
            throw new \InvalidArgumentException('Cannot infer the timezone of the DateTime value');
        }

        return $timezone;
    }

    private static function clickHouseTimezone(\DateTimeZone $timezone): string
    {
        // PHP returns false for fixed offsets and abbreviations.
        return false === $timezone->getTransitions(0, 0) ? 'UTC' : $timezone->getName();
    }
}
