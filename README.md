# PhpClickHouseClient

[![CI](https://github.com/SevaCode/PhpClickHouseClient/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/SevaCode/PhpClickHouseClient/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/seva-code/php-click-house-client)](https://packagist.org/packages/seva-code/php-click-house-client)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

PHP client for the [ClickHouse](https://github.com/ClickHouse/ClickHouse) HTTP interface.

Features:
- [Server-side query parameters](#named-parameters), including [typed `ChcParam` values](#typed-parameters).
- [Stream results](#streaming-results) and [insert rows in chunks](#inserting-rows).
- [Optional HTTP compression](#compression).
- [Parallel queries with `pool()`](#parallel-queries).
- [Opt-in retries for read-only requests](#retries).
- [Typed exceptions for ClickHouse, HTTP, connection, and timeout failures](#errors).
- [Read-only queries by default](#read-only-mode).
- [Immutable `with*()` configuration](#connection-and-configuration).

## Installation

Requires PHP 8.3+ and `symfony/http-client` `^6.4 || ^7.0`.

```bash
composer require seva-code/php-click-house-client:^2.0
```

For PHP 8.1 or 8.2, see [supported versions](#versions).

## Quick start

With ClickHouse running locally on its default HTTP port, this script prints `1`. It doesn't need
any tables or query parameters.

```php
<?php

use SevaCode\ClickHouseClient\ChcClient;

require __DIR__ . '/vendor/autoload.php';

$client = new ChcClient();
echo $client->fetchOne('SELECT 1');
```

If your server requires authentication or runs at a different URL, see
[connection and configuration](#connection-and-configuration). The following examples reuse `$client`.

### Reading rows and columns

Use `fetchAll()` to get rows as associative arrays:

```php
$rows = $client->fetchAll('SELECT number FROM numbers(3)');
```

Use `fetchRow()` for the first row, `fetchOne()` for the first value, or `fetchColumn()` for the first
column across all rows. `fetchRow()` and `fetchOne()` return `null` when no rows match.

## Query parameters

### Named parameters

Use a ClickHouse `{name:Type}` placeholder and pass the value under the matching key. The client
sends it separately from the SQL, so you don't have to quote or escape it in the query.

```php
$rows = $client->fetchAll(
    'SELECT number FROM numbers(5) WHERE number >= {min:UInt64}',
    ['min' => 3],
);
```

See [ClickHouse server-side query parameters](https://clickhouse.com/docs/en/interfaces/http#cli-queries-with-parameters).
PHP values map to: `null` → `NULL`, `bool` → `1`/`0` (`true`/`false` inside arrays),
`DateTimeInterface` → `Y-m-d H:i:s[.u]` (pass a `'Y-m-d'` string for `Date`), lists → `Array(...)`,
and string-keyed arrays → `Map(...)`.

### Typed parameters

The `ChcParam` examples use APIs added after 2.0.0. To try them from the development branch,
run `composer require seva-code/php-click-house-client:dev-master`.

To define a value and its ClickHouse type together, create a `ChcParam`. Interpolating the object
into the SQL inserts the placeholder, not the value. Pass the object in the parameters array:

```php
use SevaCode\ClickHouseClient\ChcParam;

$min = ChcParam::UInt64(3);
$rows = $client->fetchAll("SELECT number FROM numbers(5) WHERE number >= $min", [$min]);
```

The name is optional: `ChcParam::UInt64(3, 'min')` renders `{min:UInt64}`; omitting it generates
a name like `chc_N`. Pass typed parameters in a list or under matching string keys. An unused
typed parameter or a mismatched key throws before the request is sent.

### Array and nullable parameters

Use a shorthand factory such as `ArrayUInt64()` when a filter takes a list:

```php
$ids = ChcParam::ArrayUInt64([1, 3]);
$rows = $client->fetchAll("SELECT number FROM numbers(5) WHERE number IN $ids", [$ids]);
```

`Array<Type>` factories such as `ArrayString()` and `ArrayDateTime()` preserve the type even for
empty arrays. For timezone-specific `DateTime` values or composite types, use the generic factory,
for example `ChcParam::Array($dates, ChcType::DateTime('UTC'))`.

Use `nullable: true` for scalar factories, for example `ChcParam::String(null, nullable: true)`,
or use `ChcParam::Nullable($value, $type)`. For other types, use `ChcParam::of($value, $type)`
with `ChcType::of()`. `ChcType` is `Stringable`, so you can use it in expressions such as
`CAST(x AS $type)`.

## Connection and configuration

`ChcClient` defaults to `http://localhost:8123/`. Its constructor accepts an optional URL and a
`Symfony\Contracts\HttpClient\HttpClientInterface`; `withUrl()` changes the endpoint. The immutable
`with*()` methods return a configured clone:

```php
$client = (new ChcClient('http://localhost:8123/'))
    ->withCredentials('reader', 'secret')
    ->withDatabase('analytics')
    ->withTimeout(30)
    ->withSettings(['max_execution_time' => 25]);
```

`withCredentials()` sends `X-ClickHouse-User` and `X-ClickHouse-Key` headers, not credentials in the
URL. `withTimeout()` sets the HTTP read/idle timeout in seconds; ClickHouse's server-side limit is a
separate setting such as `max_execution_time`. `withSettings()` applies ClickHouse settings to all
queries from that client. The final `$settings` argument of `fetchAll()`, `fetchRow()`, `fetchOne()`,
`fetchColumn()`, `write()`, `iterate()`, and `insert()` applies settings to that query.

All requests use Symfony HttpClient. Supply a configured client with `withHttpClient()` for custom
TLS, proxies, or connection reuse. `withSqlLogger()` accepts a PSR logger. `ping(): bool` checks
availability and returns `false` rather than throwing on a client exception.

### Compression

`withCompression(true)` enables HTTP response compression and compresses `insert()` request bodies.

```php
$compressedClient = $client->withCompression(true);
$rows = $compressedClient->fetchAll('SELECT number FROM numbers(1000)');
```

## Read-only mode

Queries use HTTP POST with SQL in the request body and `readonly=2` by default. This avoids a query
URL length limit and keeps SQL out of proxy access logs. If your ClickHouse user has write
permissions, create a separate client with read-only mode disabled:

```php
use SevaCode\ClickHouseClient\ChcReadOnly;

$writeClient = $client->withReadOnly(ChcReadOnly::Disabled);
```

`withReadOnly()` accepts a `ChcReadOnly` case or integer 0–2:

| Level | `ChcReadOnly` | Meaning |
|---|---|---|
| 0 | `Disabled` | Writes and DDL allowed; no `readonly` parameter is sent |
| 1 | `Strict` | Reads only; settings cannot be changed. Use for users whose profile sets `readonly=1` |
| 2 | `AllowSettings` | Default; reads only, `SET`/`SETTINGS` allowed (the level ClickHouse applies to HTTP GET) |

An explicit `readonly` in `withSettings()` or per-query settings takes precedence. For a user profile
that sets `readonly=1`, use `$client->withReadOnly(ChcReadOnly::Strict)` or
`$client->withSettings(['readonly' => 1])`.
See [ClickHouse query permissions](https://clickhouse.com/docs/operations/settings/permissions-for-queries).

## Streaming results

`iterate()` yields `FORMAT JSONEachRow` results one row at a time without buffering the full result:

```php
foreach ($client->iterate('SELECT number FROM numbers(1000)') as $row) {
    echo $row['number'], PHP_EOL;
}
```

If ClickHouse reports an error after streaming has begun, rows received before the error may already
have been yielded before the exception is thrown.

## Inserting rows

Use the [write-enabled client](#read-only-mode) with an existing table, here `events` with columns
`id` and `type`:

```php
$writeClient->insert('events', [
    ['id' => 1, 'type' => 'click'],
    ['id' => 2, 'type' => 'view'],
]);
```

For large batches, pass a generator so that `insert()` can send chunks without loading all rows
into memory:

```php
$rows = (static function (): Generator {
    for ($id = 1; $id <= 1000; ++$id) {
        yield ['id' => $id, 'type' => 'click'];
    }
})();
$writeClient->insert('events', $rows);
```

You can also pass list rows with an explicit column order:
`$writeClient->insert('events', [[1, 'click']], ['id', 'type'])`.
The table name is written directly into SQL, so do not pass user input.

## Parallel queries

`pool()` sends JSON queries in parallel and returns a `ChcResult` per input key:

```php
$results = $client->pool(['a' => 'SELECT 1 AS n', 'b' => 'SELECT 2 AS n']);
$results['a']->data[0]['n']; // 1
$results['b']->rows;         // also meta, totals, extremes, statistics, queryId, summary
```

Results are keyed and ordered like the input array. `withPoolConcurrency()` can limit simultaneous
requests. `getJsonPool($queries, $callback)` is deprecated since 2.0 and will be removed in 3.0.

## Retries

`withRetries()` opts in to retries for eligible read-only requests on connection failures and
HTTP 502/503/504. Client timeouts, writes, and ClickHouse errors are not retried.

```php
$retryingClient = $client->withRetries(3, 0.5);
echo $retryingClient->fetchOne('SELECT 1');
```

## Errors

Catch `ChcException` to handle client exceptions in one place:

```php
use SevaCode\ClickHouseClient\ChcException;

try {
    echo $client->fetchOne('SELECT 1');
} catch (ChcException $e) {
    error_log($e->getMessage());
}
```

For different handling by failure type, catch the specific exception from
`SevaCode\ClickHouseClient\Exceptions` before `ChcException`:

| Exception | When it is thrown |
|---|---|
| `ChcConnectionException` | Connection failure without a complete HTTP response |
| `ChcClientTimeoutException` | HTTP client timeout; subclass of `ChcConnectionException` |
| `ChcHttpException` | HTTP error status without a ClickHouse error code |
| `ChcServerException` | ClickHouse error, including `ChcTimeoutExceededException` (159) and `ChcMemoryLimitExceededException` (241) |

`getClickHouseErrorCode()` and `getHttpStatus()` return `null` when not available. `getCode()` returns
the ClickHouse error code or `0`, never the HTTP status. `ChcException` also covers errors such as
malformed successful responses. `isAlive()` is a deprecated alias for non-throwing `ping()` and will
be removed in 3.0.

## Query metadata and sessions

```php
$client->fetchOne('SELECT 1', settings: ['query_id' => 'report-42']);
$client->getLastQueryId();      // X-ClickHouse-Query-Id from the last successful query
$client->getLastQuerySummary(); // X-ClickHouse-Summary; values are strings
```

If you omit `query_id`, ClickHouse generates it. `getLastQueryLatency()` returns the last query
latency in seconds, or `null` before a query.

Use `$client->withSession('import-' . bin2hex(random_bytes(8)))` for queries that share temporary
tables or session settings. `withSession()` accepts an optional timeout as its second argument.
Queries sharing a session must not run concurrently; ClickHouse may return `SESSION_IS_LOCKED`.

## Low-level API

`execute()` returns a `ChcResponse`; `getRaw()`, `getJson()`, and `getJsonData()` provide lower-level
response access. `getRaw()` accepts a `ChcFormat` case or format string, for example:

```php
$body = $client->getRaw('SELECT 1', \SevaCode\ClickHouseClient\ChcFormat::JSONEachRow);
```

`getJsonData()` returns the `data` field from a JSON response.

## Notes

- With `FORMAT JSON`, ClickHouse returns `UInt64`/`Int64` as strings by default
  (`output_format_json_quote_64bit_integers`; newer servers may differ). Set it explicitly if you
  rely on the type: `$client->withSettings(['output_format_json_quote_64bit_integers' => 0])`.
- An error after ClickHouse starts streaming a result can arrive in the body with HTTP 200. The
  client detects the appended error and throws an exception. With `wait_end_of_query=1`, the server
  buffers the result and reports such errors with an HTTP error status; the query summary is
  complete, at the cost of server memory and latency.

## Versions

| Release | PHP | Branch | Maintenance | ClickHouse |
|---|---|---|---|---|
| 2.x | 8.3+ | `master` | Active | 24.1+ |
| 1.4 | 8.1+ | [`1.4`](https://github.com/SevaCode/PhpClickHouseClient/tree/1.4#installation) | Bug fixes | 24.1+ |

See the [2.0 upgrade guide](UPGRADE-2.0.md) and [changelog](CHANGELOG.md).

## Development

Contributor documentation lives in [`docs/`](docs/README.md), including the
[roadmap](docs/roadmap/ROADMAP.md) and [architecture decision records](docs/adr/).

```bash
composer install
composer test       # unit tests
composer phpstan    # static analysis
```

Integration tests run against a real ClickHouse server:

```bash
docker run -d -p 8123:8123 -e CLICKHOUSE_SKIP_USER_SETUP=1 clickhouse/clickhouse-server
CLICKHOUSE_URL=http://localhost:8123/ composer test-integration
```

Optional `CLICKHOUSE_USER` and `CLICKHOUSE_PASSWORD` environment variables are passed to the client.
