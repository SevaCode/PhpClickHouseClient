<?php

namespace SevaCode\ClickHouseClient;

/**
 * A ClickHouse query parameter type.
 *
 *     $type = ChcType::Array(ChcType::String());
 *     $param = ChcParam::of(['click', 'view'], $type, 'types');
 *     $client->fetchAll("SELECT * FROM events WHERE type IN $param", [$param]);
 */
final class ChcType implements \Stringable
{
    private string $type;

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public function __toString(): string
    {
        return $this->type;
    }

    public static function Int8(): self
    {
        return new self('Int8');
    }

    public static function Int16(): self
    {
        return new self('Int16');
    }

    public static function Int32(): self
    {
        return new self('Int32');
    }

    public static function Int64(): self
    {
        return new self('Int64');
    }

    public static function Int128(): self
    {
        return new self('Int128');
    }

    public static function Int256(): self
    {
        return new self('Int256');
    }

    public static function UInt8(): self
    {
        return new self('UInt8');
    }

    public static function UInt16(): self
    {
        return new self('UInt16');
    }

    public static function UInt32(): self
    {
        return new self('UInt32');
    }

    public static function UInt64(): self
    {
        return new self('UInt64');
    }

    public static function UInt128(): self
    {
        return new self('UInt128');
    }

    public static function UInt256(): self
    {
        return new self('UInt256');
    }

    public static function Float32(): self
    {
        return new self('Float32');
    }

    public static function Float64(): self
    {
        return new self('Float64');
    }

    public static function Bool(): self
    {
        return new self('Bool');
    }

    public static function String(): self
    {
        return new self('String');
    }

    public static function UUID(): self
    {
        return new self('UUID');
    }

    public static function Date(): self
    {
        return new self('Date');
    }

    public static function Date32(): self
    {
        return new self('Date32');
    }

    public static function IPv4(): self
    {
        return new self('IPv4');
    }

    public static function IPv6(): self
    {
        return new self('IPv6');
    }

    public static function DateTime(?string $timezone = null): self
    {
        return new self(null === $timezone ? 'DateTime' : "DateTime('" . self::escapeTimezone($timezone) . "')");
    }

    public static function DateTime64(int $precision, ?string $timezone = null): self
    {
        $type = 'DateTime64(' . $precision;
        if (null !== $timezone) {
            $type .= ", '" . self::escapeTimezone($timezone) . "'";
        }

        return new self($type . ')');
    }

    public static function Decimal(int $precision, int $scale): self
    {
        return new self('Decimal(' . $precision . ', ' . $scale . ')');
    }

    public static function FixedString(int $length): self
    {
        return new self('FixedString(' . $length . ')');
    }

    public static function Array(self $item): self
    {
        return new self('Array(' . $item . ')');
    }

    public static function Nullable(self $type): self
    {
        return new self('Nullable(' . $type . ')');
    }

    public static function LowCardinality(self $type): self
    {
        return new self('LowCardinality(' . $type . ')');
    }

    public static function Map(self $key, self $value): self
    {
        return new self('Map(' . $key . ', ' . $value . ')');
    }

    public static function Tuple(self ...$types): self
    {
        if ([] === $types) {
            throw new \InvalidArgumentException('Tuple requires at least one type');
        }

        return new self('Tuple(' . implode(', ', $types) . ')');
    }

    public static function of(string $type): self
    {
        if ('' === trim($type)) {
            throw new \InvalidArgumentException('ClickHouse type must not be empty');
        }

        $depth = 0;
        foreach (str_split($type) as $character) {
            if ('(' === $character) {
                ++$depth;
            } elseif (')' === $character && --$depth < 0) {
                throw new \InvalidArgumentException('Unbalanced parentheses in ClickHouse type: ' . $type);
            }
        }

        if (0 !== $depth) {
            throw new \InvalidArgumentException('Unbalanced parentheses in ClickHouse type: ' . $type);
        }

        return new self($type);
    }

    private static function escapeTimezone(string $timezone): string
    {
        return strtr($timezone, [
            '\\' => '\\\\',
            "'" => "\\'",
        ]);
    }
}
