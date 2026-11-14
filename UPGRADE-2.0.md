# Upgrade from 1.x to 2.0

> Draft. The list of breaking changes is decided in
> [ADR-0002](docs/adr/0002-version-2.md); this file is completed as items are accepted and implemented.

## Recommended path

1. Upgrade to 1.4 (no breaking changes).
2. Replace deprecated APIs with the 1.4 equivalents while still on 1.x:
   - `HttpClient` / `Mode` → `ChcClient`;
   - string-concatenated values in SQL → query parameters (`fetchAll($sql, ['id' => 5])`);
   - `addSettings(['user' => …, 'password' => …])` → `setCredentials()`;
   - `ping()` no longer throws in 2.0; `isAlive()` remains as a deprecated alias;
   - use `getHttpStatus()` for HTTP status; `getCode()` is now the ClickHouse code or `0`.
3. Upgrade to 2.0 and apply the changes below.

## Breaking changes

### HttpClient and Mode removed (ADR-0002 #1)

Use `ChcClient` instead of the `HttpClient` wrapper. `Mode::READONLY` and `Mode::WRITE` are replaced
by read-only levels:

```php
$client = (new ChcClient())->withReadOnly(ChcReadOnly::Disabled); // replaces Mode::WRITE
```

- `getMode()` → `$client->getReadOnly()`; `setMode(Mode::READONLY|Mode::WRITE)` →
  `$client = $client->withReadOnly(ChcReadOnly::AllowSettings|ChcReadOnly::Disabled)`.
- `getData($query)` → `$client->getJsonData($query)`.
- `query($query)` → `$client->getRaw($query, $format)` for reads or
  `$client->execute($query, $format)->getBody()` for writes.
- `setFormat()` / `getFormat()` → pass the format to `getRaw()` or `execute()` for each query.
- `getUrl()` has no direct replacement; keep the URL in application configuration if needed.

### Native transport removed (ADR-0002 #2)

All requests go through Symfony HttpClient, which is already a dependency. The default is a shared
`HttpClient::create()` client with keep-alive. Inject a configured client with `withHttpClient()`.
Code that instantiated `Transports\ChcHttpTransport` should use `ChcClient` instead.

### PHP 8.3+ and symfony/http-client ^6.4 || ^7.0 (ADR-0002 #10)

PHP 8.3 or newer is required, with `symfony/http-client` version `^6.4 || ^7.0`.

### Strict types (ADR-0002 #3)

Every PHP file declares `strict_types=1`, and public methods use native parameter and return types.
Passing a value of the wrong type (for example, an integer where a string is expected) now throws
`TypeError`. `getLastQueryLatency()` returns `?float`: it is `null` before the first query.

### ChcFormat is an enum (ADR-0002 #4)

`ChcFormat::JSON` and the other format names are enum cases. Format arguments accept
`ChcFormat|string|null`: `null` means no `FORMAT` clause, and `''` remains accepted as a no-format
value. Code that used a format constant as a string, such as `'FORMAT ' . ChcFormat::JSON`, must
use its backed value: `'FORMAT ' . ChcFormat::JSON->value`.

### Immutable client (ADR-0002 #5)

`ChcClient` configuration is immutable: each `withX()` method returns a clone. Assign the result;
otherwise the original client remains unchanged:

```php
$client = $client->withDatabase('analytics')->withReadOnly(ChcReadOnly::Disabled);
```

| Removed method | Replacement |
| --- | --- |
| `setUrl($url)` | `$client = $client->withUrl($url)` |
| `setDatabase($database)` | `$client = $client->withDatabase($database)` |
| `addSettings($settings)` | `$client = $client->withSettings($settings)` |
| `setCredentials($user, $password)` | `$client = $client->withCredentials($user, $password)` |
| `setTimeout($seconds)` | `$client = $client->withTimeout($seconds)` |
| `readOnly($value)` | `$client = $client->withReadOnly(ChcReadOnly::AllowSettings)` / `withReadOnly(ChcReadOnly::Disabled)`; `getReadOnly()` returns `ChcReadOnly` |
| `setCompression($enabled)` | `$client = $client->withCompression($enabled)` |
| `setHttpClient($httpClient)` | `$client = $client->withHttpClient($httpClient)` |
| `setPoolConcurrency($concurrency)` | `$client = $client->withPoolConcurrency($concurrency)` |
| `setRetries($count, $delaySeconds)` | `$client = $client->withRetries($count, $delaySeconds)` |
| `setSessionId($id, $timeoutSeconds)` | `$client = $client->withSession($id, $timeoutSeconds)` |
| `setSqlLogger($logger)` | `$client = $client->withSqlLogger($logger)` |
| `setReadOnlyOverPost($enabled)` | Removed; reads use POST by default (see below) |

`withReadOnly()` accepts the `ChcReadOnly` enum or its integer value 0–2; other integers throw
`InvalidArgumentException`. `ChcReadOnly::Disabled` (0) allows writes and sends no `readonly` parameter;
`Strict` (1) and `AllowSettings` (2) send the corresponding ClickHouse setting. The default is `AllowSettings`.

### Reads over POST with readonly=2 (ADR-0002 #6)

Non-ping queries now use POST with SQL in the body and
`Content-Type: text/plain; charset=UTF-8`; SQL is no longer sent in the URL. Ping remains GET `/ping`.
`setReadOnlyOverPost()` is removed because POST is the default. Read-only level `2` is the default.

If your user profile sets `readonly=1`, the default request can fail with Code 164,
`Cannot modify 'readonly' setting in readonly mode`. Use `$client = $client->withReadOnly(ChcReadOnly::Strict);`.
An explicit setting passed with `$client = $client->withSettings(['readonly' => 1])` is also preserved.
See [ClickHouse query permissions](https://clickhouse.com/docs/operations/settings/permissions-for-queries).

### ping() never throws (ADR-0002 #7)

`ping(): bool` returns `false` when the server is unavailable or returns an error, so remove
`try/catch` around `ping()`. `isAlive()` remains as a deprecated alias for `ping()` and will be
removed in 3.0.

### Exception hierarchy (ADR-0002 #8)

```text
ChcException                       getCode() = ClickHouse code or 0; getHttpStatus()
├── ChcServerException              ClickHouse returned an error code
│   ├── ChcMemoryLimitExceededException  code 241
│   └── ChcTimeoutExceededException     server-side timeout, code 159
├── ChcConnectionException          no HTTP response
│   └── ChcClientTimeoutException   HTTP client timeout
└── ChcHttpException                HTTP status >= 300 without a ClickHouse error code
```

`getCode()` now returns the ClickHouse error code or `0`, never the HTTP status. Use `getHttpStatus()`
for the response status and `getClickHouseErrorCode()` when you need a nullable ClickHouse error code.
Client-side timeouts are `ChcClientTimeoutException`, not `ChcTimeoutExceededException`; the latter
represents server-side error 159. A `catch (ChcException)` still catches every client exception.

### pool() returns result objects (ADR-0002 #9)

`pool(array $queries): array<key, ChcResult>` replaces the callback with eight positional arguments:

```php
// 1.x
$counts = $client->getJsonPool($queries, fn($data, $meta, $rows) => $rows);
// 2.0
$counts = array_map(fn(ChcResult $r) => $r->rows, $client->pool($queries));
```

`ChcResult` has `data`, `meta`, `rows`, `rowsBeforeLimitAtLeast`, `statistics`, `totals`, `extremes`,
`startTime`, plus `queryId` and `summary` from the response headers. Keys, order, concurrency
(`withPoolConcurrency()`) and errors are the same as in `getJsonPool()`. `pool()` keeps every result
in memory until it returns; `getJsonPool()` still runs the callback per response, is deprecated and
will be removed in 3.0.
