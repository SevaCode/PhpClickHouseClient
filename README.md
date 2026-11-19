# PhpClickHouseClient

[![CI](https://github.com/SevaCode/PhpClickHouseClient/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/SevaCode/PhpClickHouseClient/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/seva-code/php-click-house-client)](https://packagist.org/packages/seva-code/php-click-house-client)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

PHP client for the [ClickHouse](https://github.com/ClickHouse/ClickHouse) HTTP interface.

Features:
- Safe server-side query parameters, including typed `ChcParam` values.
- Stream results with `iterate()` and send inserts without loading all rows into memory.
- Optional HTTP compression.
- Parallel queries with `pool()`.
- Opt-in retries for eligible read-only requests.
- Typed exception hierarchy for ClickHouse, HTTP, connection, and client-timeout failures.
- Read-only queries by default.
- Immutable `with*()` configuration methods.

## Installation

Requires PHP 8.3+ and `symfony/http-client` `^6.4 || ^7.0`.

```bash
composer require seva-code/php-click-house-client:^2.0
```

## Versions

| Release | PHP | Branch | Maintenance | ClickHouse |
|---|---|---|---|---|
| 2.x | 8.3+ | `master` | Active | 24.1+ |
| 1.4 | 8.1+ | `1.4` | Bug fixes | 24.1+ |

See the [2.0 upgrade guide](UPGRADE-2.0.md) and [changelog](CHANGELOG.md).

## Quick start

```php
use SevaCode\ClickHouseClient\{ChcClient, ChcParam, ChcType};

$client = (new ChcClient('http://localhost:8123/'))->withCredentials('default', 'secret');
$id = ChcParam::UInt64(42);
$types = ChcParam::Array(['click', 'view'], ChcType::String());
$rows = $client->fetchAll("SELECT * FROM events WHERE user_id = $id AND type IN $types", [$id, $types]);

foreach ($client->iterate('SELECT * FROM events', []) as $row) {
    // Process one row at a time.
}
```

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
TLS, proxies, or connection reuse. `withCompression(true)` enables response compression and
compression of `insert()` bodies. `withSqlLogger()` accepts a PSR logger. `ping(): bool` checks
availability and returns `false` rather than throwing on a client exception.

### Low-level API

`execute()` returns a `ChcResponse`; `getRaw()`, `getJson()`, and `getJsonData()` provide lower-level
response access. `getRaw()` accepts a `ChcFormat` case or format string, for example:

```php
$body = $client->getRaw('SELECT 1', \SevaCode\ClickHouseClient\ChcFormat::JSONEachRow);
```

`getJsonData()` returns the `data` field from a JSON response. `getLastQueryLatency()` returns the
last query latency in seconds, or `null` before a query.

## Query parameters

### Typed parameters

Named `ChcParam` factories render their own `{name:Type}` placeholder; the value is sent separately
as `param_<name>`:

```php
$ids = ChcParam::ArrayUInt64([42, 43], 'ids');
$types = ChcParam::ArrayString(['click', 'view'], 'types');
$times = ChcParam::ArrayDateTime([new DateTimeImmutable('2024-01-02 03:04:05')], 'times');
$name = ChcParam::String(null, nullable: true);
$rows = $client->fetchAll(
    "SELECT * FROM events WHERE user_id IN $ids AND type IN $types AND ts IN $times AND isNull($name)",
    [$ids, $types, $times, $name],
);
```

`Array<Type>` factories are available for the simple scalar types. The type remains explicit even for
an empty array. `ArrayDateTime()` uses `DateTime` without an explicit timezone; use
`ChcParam::Array($value, ChcType::DateTime('UTC'), $name)` for a timezone-specific array or the generic
factory for other parameterized or composite types.

The name can be omitted; auto-generated names have the form `chc_N`. Pass a `ChcParam` in a list
or under a matching string key. An unused typed parameter, or a string key that does not match its
name, throws before the request is sent. Use `nullable: true` for scalar factories or
`ChcParam::Nullable($value, $type)` for an explicitly nullable type. `ChcParam::of($value, $type)`
and `ChcType::of()` support other types. `ChcType` is `Stringable`, so it can also be used in
expressions such as `CAST(x AS $type)`.

On 2.x, scalar factories and arrays built from `ChcType` factories check values at construction,
without PHP scalar coercion. A mismatch throws `InvalidArgumentException`.

| ClickHouse type | Accepted PHP value (apart from explicit nullable types) |
|---|---|
| `Bool` | `bool` |
| `String`, `FixedString` | `string` or `Stringable` |
| `Int*`, `UInt*` | `int` or an in-range base-10 integer string (no leading zeroes except zero itself) |
| `Float32`, `Float64` | `int` or `float` |
| `Decimal` | `int` or a decimal string such as `'12.3400'`; not `float` or scientific notation |
| `Date`, `Date32`, `UUID`, `IPv4`, `IPv6` | `string` |
| `DateTime`, `DateTime64` | `string` or `DateTimeInterface` |
| `Array(T)` | A PHP list, checked element by element against `T` |

For example, use `ChcParam::String('0')` instead of `ChcParam::String(false)`. ClickHouse still
checks such things as date formats, decimal precision and scale, and map contents. Raw type strings
(`ChcParam::of($value, 'SomeType')` or `ChcType::of('SomeType')`) and raw server-side parameters
do not get the new value checks.

### Raw server-side parameters

Values can also be supplied separately from SQL using ClickHouse
[server-side query parameters](https://clickhouse.com/docs/en/interfaces/http#cli-queries-with-parameters):

```php
$rows = $client->fetchAll(
    'SELECT * FROM events WHERE user_id = {id:UInt64} AND ts >= {from:DateTime}',
    ['id' => 42, 'from' => new DateTimeImmutable('-1 day')],
);
$row = $client->fetchRow('SELECT * FROM users WHERE id = {id:UInt64}', ['id' => 42]);
$count = $client->fetchOne('SELECT count() FROM users WHERE name = {n:String}', ['n' => "O'Reilly"]);
$ids = $client->fetchColumn('SELECT id FROM users LIMIT {n:UInt8}', ['n' => 10]);
```

`fetchRow()` returns the first row or `null`; `fetchOne()` returns the first column of the first row
or `null`; `fetchColumn()` returns the first column of every row.

PHP values map to: `null` → `NULL`, `bool` → `1`/`0` (`true`/`false` inside arrays),
`DateTimeInterface` → `Y-m-d H:i:s[.u]` (pass a `'Y-m-d'` string for `Date`), lists → `Array(...)`,
and string-keyed arrays → `Map(...)`.

## Read-only mode

Queries use HTTP POST with SQL in the request body and `readonly=2` by default. This avoids a query
URL length limit and keeps SQL out of proxy access logs. `withReadOnly()` accepts a `ChcReadOnly`
case or integer 0–2:

| Level | `ChcReadOnly` | Meaning |
|---|---|---|
| 0 | `Disabled` | Writes and DDL allowed; no `readonly` parameter is sent |
| 1 | `Strict` | Reads only; settings cannot be changed. Use for users whose profile sets `readonly=1` |
| 2 | `AllowSettings` | Default; reads only, `SET`/`SETTINGS` allowed (the level ClickHouse applies to HTTP GET) |

An explicit `readonly` in `withSettings()` or per-query settings takes precedence. For a user profile
that sets `readonly=1`, use `$client->withReadOnly(ChcReadOnly::Strict)` or
`$client->withSettings(['readonly' => 1])`.
See [ClickHouse query permissions](https://clickhouse.com/docs/operations/settings/permissions-for-queries).

## Streaming and inserts

`iterate()` yields `FORMAT JSONEachRow` results one row at a time, so memory use does not depend on
the result size:

```php
foreach ($client->iterate('SELECT * FROM hits WHERE dt = {dt:Date}', ['dt' => '2024-01-01']) as $row) {
    // Process each row.
}
```

`insert()` consumes an array or iterable such as a generator and sends rows in chunks. Rows may be
associative arrays; if `$columns` is provided, list rows are matched to those columns by position.
The table name is written into SQL as an identifier, so do not pass user input:

```php
$writeClient = $client->withReadOnly(ChcReadOnly::Disabled);
$writeClient->insert('db.events', $rowsGenerator);
$writeClient->insert('db.events', [[1, 'click'], [2, 'view']], ['id', 'type']);
```

If ClickHouse reports an error after streaming has begun, rows received before the error may already
have been yielded before the exception is thrown.

## Parallel queries

`pool()` sends JSON queries in parallel and returns a `ChcResult` per input key:

```php
$results = $client->pool(['a' => 'SELECT 1 AS n', 'b' => 'SELECT 2 AS n']);
$results['a']->data[0]['n']; // 1
$results['b']->rows;         // also meta, totals, extremes, statistics, queryId, summary
```

Results are keyed and ordered like the input array. `withPoolConcurrency()` can limit simultaneous
requests. `getJsonPool($queries, $callback)` is deprecated since 2.0 and will be removed in 3.0.

## Query id, summary, sessions, retries

```php
$client->fetchAll($sql, $params, ['query_id' => 'report-42']); // or let ClickHouse generate one
$client->getLastQueryId();      // X-ClickHouse-Query-Id from the last successful query
$client->getLastQuerySummary(); // X-ClickHouse-Summary; values are strings

$client = $client->withSession('import-' . bin2hex(random_bytes(8))); // temporary tables and SET
$client = $client->withRetries(3, 0.5);
```

`withSession()` accepts an optional session timeout as its second argument. Queries sharing a
session must not run concurrently; ClickHouse may return `SESSION_IS_LOCKED`. `withRetries()` retries
eligible read-only requests on connection failures and HTTP 502/503/504. Client timeouts, writes,
and ClickHouse errors are not retried.

## Errors

Catch the specific exception class when handling a particular failure, or catch `ChcException` for
all client exceptions:

```php
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\{
    ChcClientTimeoutException,
    ChcConnectionException,
    ChcHttpException,
    ChcServerException
};

try {
    $client->fetchAll('SELECT * FROM missing');
} catch (ChcClientTimeoutException $e) {
    // HTTP client timeout; also a ChcConnectionException.
} catch (ChcConnectionException $e) {
    // No complete HTTP response (connect failure, broken connection).
} catch (ChcServerException $e) {
    // ClickHouse error; memory-limit and server-timeout subclasses are included.
    $e->getCode(); // ClickHouse error code, e.g. 60.
} catch (ChcHttpException $e) {
    // HTTP status without a ClickHouse error code.
    $e->getHttpStatus();
} catch (ChcException $e) {
    $e->getClickHouseErrorCode(); // null when this is not a ClickHouse error.
    $e->getHttpStatus();          // null when there was no HTTP response.
    $e->getCode();                // ClickHouse code or 0, never the HTTP status.
}
```

`ChcTimeoutExceededException` represents server-side ClickHouse error 159; HTTP client timeouts use
`ChcClientTimeoutException`. `ChcMemoryLimitExceededException` represents server error 241.
`ChcException` also covers errors such as malformed successful responses. `isAlive()` is a deprecated
alias for non-throwing `ping()` and will be removed in 3.0.

## Notes

- With `FORMAT JSON`, ClickHouse returns `UInt64`/`Int64` as strings by default
  (`output_format_json_quote_64bit_integers`; newer servers may differ). Set it explicitly if you
  rely on the type: `$client->withSettings(['output_format_json_quote_64bit_integers' => 0])`.
- An error after ClickHouse starts streaming a result can arrive in the body with HTTP 200. The
  client detects the appended error and throws an exception. With `wait_end_of_query=1`, the server
  buffers the result and reports such errors with an HTTP error status; the query summary is
  complete, at the cost of server memory and latency.

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
