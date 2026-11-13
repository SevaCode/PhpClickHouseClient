<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

/**
 * Reads ClickHouse response headers from raw header lines.
 *
 * @internal
 */
final class ChcHeaders
{
    /**
     * @param string[] $headerLines
     */
    public static function get(array $headerLines, string $name): ?string
    {
        $prefix = strtolower($name) . ':';
        $value = null;
        foreach ($headerLines as $line) {
            if (str_starts_with(strtolower($line), $prefix)) {
                $value = trim(substr($line, strlen($prefix)));
            }
        }
        return $value;
    }

    /**
     * @param string[] $headerLines
     */
    public static function queryId(array $headerLines): ?string
    {
        return self::get($headerLines, 'X-ClickHouse-Query-Id');
    }

    /**
     * X-ClickHouse-Summary: {"read_rows":"1","read_bytes":"1",...}; values are strings as sent by the server.
     *
     * @param string[] $headerLines
     * @return array<string, string>|null
     */
    public static function summary(array $headerLines): ?array
    {
        $summary = self::get($headerLines, 'X-ClickHouse-Summary');
        if (null === $summary) {
            return null;
        }
        $decoded = json_decode($summary, true);
        return is_array($decoded) ? $decoded : null;
    }
}
