<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * Result of one JSON query returned by ChcClient::pool().
 */
final class ChcResult
{
    /**
     * @param list<array<string, mixed>> $data
     * @param list<array{name: string, type: string}> $meta
     * @param array<string, mixed>|null $statistics
     * @param array<string, mixed>|null $totals
     * @param array<string, string>|null $summary X-ClickHouse-Summary; values are strings as sent by the server
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
        public readonly int $rows,
        public readonly ?int $rowsBeforeLimitAtLeast,
        public readonly ?array $statistics,
        public readonly ?array $totals,
        public readonly mixed $extremes,
        public readonly float $startTime,
        public readonly ?string $queryId,
        public readonly ?array $summary,
    ) {
    }
}
