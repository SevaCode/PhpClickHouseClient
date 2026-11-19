<?php

declare(strict_types=1);

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
    private const INTEGER_LIMITS = [
        'Int8' => ['127', '128'],
        'Int16' => ['32767', '32768'],
        'Int32' => ['2147483647', '2147483648'],
        'Int64' => ['9223372036854775807', '9223372036854775808'],
        'Int128' => ['170141183460469231731687303715884105727', '170141183460469231731687303715884105728'],
        'Int256' => [
            '57896044618658097711785492504343953926634992332820282019728792003956564819967',
            '57896044618658097711785492504343953926634992332820282019728792003956564819968',
        ],
        'UInt8' => ['255', null],
        'UInt16' => ['65535', null],
        'UInt32' => ['4294967295', null],
        'UInt64' => ['18446744073709551615', null],
        'UInt128' => ['340282366920938463463374607431768211455', null],
        'UInt256' => ['115792089237316195423570985008687907853269984665640564039457584007913129639935', null],
    ];

    private string $type;

    private function __construct(
        private ?string $kind,
        ?string $type = null,
        private ?self $innerType = null,
    ) {
        $this->type = $type ?? (string)$kind;
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
        return null === $timezone
            ? new self('DateTime')
            : new self('DateTime', "DateTime('" . self::escapeTimezone($timezone) . "')");
    }

    public static function DateTime64(int $precision, ?string $timezone = null): self
    {
        $type = 'DateTime64(' . $precision;
        if (null !== $timezone) {
            $type .= ", '" . self::escapeTimezone($timezone) . "'";
        }

        return new self('DateTime64', $type . ')');
    }

    public static function Decimal(int $precision, int $scale): self
    {
        return new self('Decimal', 'Decimal(' . $precision . ', ' . $scale . ')');
    }

    public static function FixedString(int $length): self
    {
        return new self('FixedString', 'FixedString(' . $length . ')');
    }

    public static function Array(self $item): self
    {
        return new self('Array', 'Array(' . $item . ')', $item);
    }

    public static function Nullable(self $type): self
    {
        return new self('Nullable', 'Nullable(' . $type . ')', $type);
    }

    public static function LowCardinality(self $type): self
    {
        return new self('LowCardinality', 'LowCardinality(' . $type . ')', $type);
    }

    public static function Map(self $key, self $value): self
    {
        return new self('Map', 'Map(' . $key . ', ' . $value . ')');
    }

    public static function Tuple(self ...$types): self
    {
        if ([] === $types) {
            throw new \InvalidArgumentException('Tuple requires at least one type');
        }

        return new self(null, 'Tuple(' . implode(', ', $types) . ')');
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

        return new self(null, $type);
    }

    /** @internal */
    public function assertParameterValue(mixed $value, string $path = ''): void
    {
        if (null === $this->kind || ('Nullable' === $this->kind && null === $value)) {
            return;
        }
        if ($value instanceof ChcParam || $value instanceof self) {
            throw new \InvalidArgumentException('ChcParam cannot be nested in query parameter values');
        }
        if ('Array' === $this->kind && is_array($value) && array_is_list($value)) {
            foreach ($value as $index => $element) {
                $this->innerType?->assertParameterValue($element, $path . '[' . $index . ']');
            }
            return;
        }
        if (null !== $this->innerType && 'Array' !== $this->kind) {
            $this->innerType->assertParameterValue($value, $path);
            return;
        }

        $valid = match ($this->kind) {
            'Bool' => is_bool($value),
            'Float32', 'Float64' => is_int($value) || is_float($value),
            'String', 'FixedString' => is_string($value) || $value instanceof \Stringable,
            'UUID', 'Date', 'Date32', 'IPv4', 'IPv6' => is_string($value),
            'DateTime', 'DateTime64' => is_string($value) || $value instanceof \DateTimeInterface,
            'Decimal' => is_int($value) || (is_string($value) && 1 === preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $value)),
            'Map' => is_array($value),
            'Array' => false,
            default => self::isValidInteger($value, self::INTEGER_LIMITS[$this->kind]),
        };
        if (!$valid) {
            throw new \InvalidArgumentException(
                'Invalid query parameter value' . $path . ' for ' . $this->type . ': ' . get_debug_type($value),
            );
        }
    }

    /** @internal */
    public function arrayParameterItemType(): ?self
    {
        return 'Array' === $this->kind ? $this->innerType : $this->innerType?->arrayParameterItemType();
    }

    /** @internal */
    public function usesNumericParameterLiteral(): bool
    {
        if (null === $this->kind || 'Array' === $this->kind) {
            return false;
        }

        return $this->innerType?->usesNumericParameterLiteral()
            ?? ('Decimal' === $this->kind || isset(self::INTEGER_LIMITS[$this->kind]));
    }

    /** @param array{string, ?string} $limits */
    private static function isValidInteger(mixed $value, array $limits): bool
    {
        if (!is_int($value) && (!is_string($value) || 1 !== preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value))) {
            return false;
        }

        $digits = (string)$value;
        $negative = str_starts_with($digits, '-');
        $limit = $limits[$negative ? 1 : 0];
        if (null === $limit) {
            return false;
        }
        $digits = $negative ? substr($digits, 1) : $digits;
        return strlen($digits) < strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
    }

    private static function escapeTimezone(string $timezone): string
    {
        return strtr($timezone, [
            '\\' => '\\\\',
            "'" => "\\'",
        ]);
    }
}
