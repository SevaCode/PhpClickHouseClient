<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * Encodes values for ClickHouse server-side query parameters:
 * SQL `SELECT {id:UInt64}` + HTTP `param_id=...`.
 *
 * The value is parsed by the server as text of the declared type, so it is never
 * interpreted as SQL. Top-level strings use the escaped (TSV) text format; array,
 * map and nested values use quoted SQL literals.
 *
 * @link https://clickhouse.com/docs/en/interfaces/cli#cli-queries-with-parameters
 */
final class ChcParamEncoder
{
    private const NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @param array<string, mixed> $params ['id' => 5, 'names' => ['a', 'b']]
     * @return array<string, string> ['param_id' => '5', 'param_names' => "['a','b']"]
     * @throws \InvalidArgumentException on invalid parameter name or unsupported value
     */
    public static function toHttpParams(array $params): array
    {
        $result = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
                throw new \InvalidArgumentException('Invalid query parameter name: ' . var_export($name, true));
            }
            $result['param_' . $name] = self::encode($value);
        }
        return $result;
    }

    /**
     * @throws \InvalidArgumentException on unsupported value
     */
    public static function encode(mixed $value): string
    {
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

    /**
     * Value as a quoted SQL literal (used inside arrays, maps and tuples).
     */
    private static function literal(mixed $value): string
    {
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
            return self::quote($value);
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
                return '[' . implode(',', array_map(self::literal(...), $value)) . ']';
            }
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = self::literal($key) . ':' . self::literal($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }

        throw new \InvalidArgumentException('Unsupported query parameter type: ' . get_debug_type($value));
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
