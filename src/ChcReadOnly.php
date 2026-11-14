<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * Levels of the ClickHouse `readonly` setting.
 *
 * With any level above Disabled, writes to permanent tables (INSERT, ALTER, DELETE…) and DDL are rejected.
 *
 * @see https://clickhouse.com/docs/operations/settings/permissions-for-queries
 */
enum ChcReadOnly: int
{
    /** No restrictions: writes and DDL are allowed. The client sends no `readonly` parameter. */
    case Disabled = 0;

    /**
     * Reads only; settings cannot be changed (SET, SETTINGS in a query, `readonly` itself).
     * Use it for users whose profile already sets readonly = 1.
     */
    case Strict = 1;

    /**
     * Reads only, plus SET, SETTINGS and temporary tables. Client default;
     * ClickHouse applies this level to HTTP GET requests. In 1.4 Strict and AllowSettings both
     * use GET, and the server keeps the stricter profile level.
     */
    case AllowSettings = 2;
}
