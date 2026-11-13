<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

/**
 * @internal
 */
final class ChcInsertBody
{
    private const CHUNK_SIZE = 262144;

    /**
     * JSONEachRow body in ~256 KB chunks, optionally gzip-compressed.
     *
     * @param iterable<array<mixed>> $rows
     * @param list<string>|null $columns
     * @return \Generator<int, string>
     */
    public static function jsonEachRow(iterable $rows, ?array $columns = null, bool $gzip = false): \Generator
    {
        $deflate = $gzip ? deflate_init(ZLIB_ENCODING_GZIP) : null;
        $buffer = '';
        foreach ($rows as $row) {
            if (!empty($columns) && array_is_list($row)) {
                if (count($row) !== count($columns)) {
                    throw new \InvalidArgumentException('Row has ' . count($row) . ' values, expected ' . count($columns));
                }
                $row = array_combine($columns, $row);
            }
            $buffer .= json_encode(array_map(self::value(...), $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
            if (strlen($buffer) >= self::CHUNK_SIZE) {
                $out = $deflate ? deflate_add($deflate, $buffer, ZLIB_NO_FLUSH) : $buffer;
                $buffer = '';
                if ('' !== $out) {
                    yield $out;
                }
            }
        }
        $out = $deflate ? deflate_add($deflate, $buffer, ZLIB_FINISH) : $buffer;
        if ('' !== $out) {
            yield $out;
        }
    }

    private static function value(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return '000000' === $value->format('u') ? $value->format('Y-m-d H:i:s') : $value->format('Y-m-d H:i:s.u');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }
        return $value;
    }
}
