<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * Encodes values for ClickHouse server-side query parameters:
 * SQL `SELECT {id:UInt64}` + HTTP `param_id=...`.
 *
 * The value is parsed by the server as text of the declared type, so it is never
 * interpreted as SQL. Top-level strings use the escaped (TSV) text format; array,
 * map and nested values use literal syntax.
 *
 * @link https://clickhouse.com/docs/en/interfaces/cli#cli-queries-with-parameters
 */
final class ChcParamEncoder
{
    private const NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
    private const INTEGER_LITERAL_PATTERN = '/\A-?(?:0|[1-9][0-9]*)\z/';
    private const DECIMAL_LITERAL_PATTERN = '/\A-?([0-9]+)(?:\.([0-9]+))?\z/';

    /** Bounds as decimal strings: [max, abs(min)], with null for unsigned types. */
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

    /**
     * @param array<array-key, mixed> $params ['id' => 5, 'names' => ['a', 'b']]
     * @return array<string, string> ['param_id' => '5', 'param_names' => "['a','b']"]
     * @throws \InvalidArgumentException on an invalid name, unused typed parameter, duplicate name, or unsupported value
     */
    public static function toHttpParams(array $params, ?string $sql = null): array
    {
        $result = [];
        $names = [];
        foreach ($params as $key => $value) {
            if ($value instanceof ChcParam) {
                $name = $value->name;
                if (is_string($key) && $key !== $name) {
                    throw new \InvalidArgumentException("Query parameter key '$key' does not match ChcParam name '$name'");
                }
                if (null !== $sql && !str_contains($sql, (string)$value)) {
                    throw new \InvalidArgumentException('Query parameter ' . $value . ' is not used in the query');
                }
            } else {
                if (!is_string($key) || !self::isValidParameterName($key)) {
                    throw new \InvalidArgumentException('Invalid query parameter name: ' . var_export($key, true));
                }
                $name = $key;
            }

            if (isset($names[$name])) {
                throw new \InvalidArgumentException('Duplicate query parameter: ' . $name);
            }
            $names[$name] = true;
            $result['param_' . $name] = $value instanceof ChcParam
                ? self::encodeTyped($value)
                : self::encode($value);
        }
        return $result;
    }

    public static function isValidParameterName(string $name): bool
    {
        return 1 === preg_match(self::NAME_PATTERN, $name);
    }

    /**
     * @throws \InvalidArgumentException on unsupported value
     */
    public static function encode(mixed $value): string
    {
        self::rejectTypedParameterValue($value);

        return match (true) {
            null === $value => '\N',
            is_bool($value) => $value ? '1' : '0',
            is_string($value) => self::escape($value),
            $value instanceof \DateTimeInterface => self::dateTime($value),
            $value instanceof \Stringable => self::escape((string)$value),
            $value instanceof \BackedEnum => self::encode($value->value),
            default => self::literal($value),
        };
    }

    private static function encodeTyped(ChcParam $param): string
    {
        $value = $param->value;
        return is_array($value) ? self::literal($value, trim((string)$param->type)) : self::encode($value);
    }

    private static function innerType(?string $type, string $wrapper): ?string
    {
        return null !== $type && 1 === preg_match('/\A' . $wrapper . '\s*\((.*)\)\z/s', $type, $matches)
            ? trim($matches[1])
            : null;
    }

    private static function fitsNumericType(string $value, string $type): bool
    {
        $type = self::innerType($type, 'Nullable') ?? $type;

        // Unquoting must not let ClickHouse wrap integers or truncate decimals.
        if (isset(self::INTEGER_LIMITS[$type])) {
            if (1 !== preg_match(self::INTEGER_LITERAL_PATTERN, $value)) {
                return false;
            }
            $negative = str_starts_with($value, '-');
            $limit = self::INTEGER_LIMITS[$type][$negative ? 1 : 0];
            $digits = $negative ? substr($value, 1) : $value;

            return null !== $limit && (strlen($digits) < strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0));
        }

        if (1 !== preg_match('/\ADecimal\s*\(\s*([0-9]{1,2})\s*,\s*([0-9]{1,2})\s*\)\z/', $type, $parts)
            || 1 !== preg_match(self::DECIMAL_LITERAL_PATTERN, $value, $digits)
        ) {
            return false;
        }

        $precision = (int)$parts[1];
        $scale = (int)$parts[2];
        return $precision >= 1 && $precision <= 76 && $scale <= $precision
            && strlen(ltrim($digits[1], '0')) <= $precision - $scale
            && strlen($digits[2] ?? '') <= $scale;
    }

    /**
     * Value as a literal inside an array, map or tuple.
     */
    private static function literal(mixed $value, ?string $type = null): string
    {
        self::rejectTypedParameterValue($value);

        if (null === $value) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if (is_string($value)) {
            return null !== $type && self::fitsNumericType($value, $type) ? $value : self::quote($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return self::quote(self::dateTime($value));
        }
        if ($value instanceof \BackedEnum) {
            return self::literal($value->value);
        }
        if ($value instanceof \Stringable) {
            return self::quote((string)$value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $itemType = self::innerType($type, 'Array');
                $items = null === $itemType
                    ? array_map(self::literal(...), $value)
                    : array_map(static fn(mixed $item): string => self::literal($item, $itemType), $value);
                return '[' . implode(',', $items) . ']';
            }
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = self::literal($key) . ':' . self::literal($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }

        throw new \InvalidArgumentException('Unsupported query parameter type: ' . get_debug_type($value));
    }

    private static function rejectTypedParameterValue(mixed $value): void
    {
        if ($value instanceof ChcParam || $value instanceof ChcType) {
            throw new \InvalidArgumentException('ChcParam cannot be nested in query parameter values');
        }
    }

    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        return var_export($value, true);
    }

    private static function dateTime(\DateTimeInterface $value): string
    {
        return '000000' === $value->format('u')
            ? $value->format('Y-m-d H:i:s')
            : $value->format('Y-m-d H:i:s.u');
    }

    /**
     * Escaped (TSV) text format for top-level string parameters.
     */
    private static function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
            "\0" => '\\0',
        ]);
    }

    private static function quote(string $value): string
    {
        return "'" . strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
            "\0" => '\\0',
        ]) . "'";
    }
}
