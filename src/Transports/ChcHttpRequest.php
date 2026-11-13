<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

/**
 * Transport-independent HTTP request to ClickHouse, built by ChcHttpRequestBuilder.
 *
 * @internal
 */
final class ChcHttpRequest
{
    /**
     * @param string $path '' for queries, 'ping' for ping
     * @param array<string, mixed> $query URL query parameters (ClickHouse settings, param_*, query for GET)
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly ?string $cafile,
    ) {
    }
}
