# Upgrade from 1.x to 2.0

> Draft. The list of breaking changes is decided in
> [ADR-0002](docs/adr/0002-version-2.md); this file is completed as items are accepted and implemented.

## Recommended path

1. Upgrade to 1.4 (no breaking changes).
2. Replace deprecated APIs with the 1.4 equivalents while still on 1.x:
   - `HttpClient` / `Mode` → `ChcClient`;
   - string-concatenated values in SQL → query parameters (`fetchAll($sql, ['id' => 5])`);
   - `addSettings(['user' => …, 'password' => …])` → `setCredentials()`;
   - `try { $client->ping() } catch (ChcException)` → `isAlive()`;
   - `getCode()` as HTTP status → `getHttpStatus()`, as ClickHouse error code → `getClickHouseErrorCode()`.
3. Upgrade to 2.0 and apply the changes below.

## Breaking changes

### HttpClient and Mode removed (ADR-0002 #1)

Use `ChcClient` instead of the `HttpClient` wrapper. `Mode::READONLY` and `Mode::WRITE` are replaced
by the boolean `readOnly()` API:

```php
$client = new ChcClient();
$client->readOnly(false); // replaces setMode(Mode::WRITE)
```

- `getMode()` → `$client->readOnly()`; `setMode(Mode::READONLY|Mode::WRITE)` → `$client->readOnly(true|false)`.
- `getData($query)` → `$client->getJsonData($query)`.
- `query($query)` → `$client->getRaw($query, $format)` for reads or
  `$client->execute($query, $format)->getBody()` for writes.
- `setFormat()` / `getFormat()` → pass the format to `getRaw()` or `execute()` for each query.
- `getUrl()` has no direct replacement; keep the URL in application configuration if needed.

### Native transport removed (ADR-0002 #2)

All requests go through Symfony HttpClient, which is already a dependency. The default is a shared
`HttpClient::create()` client with keep-alive. Inject a configured client with `setHttpClient()`.
Code that instantiated `Transports\ChcHttpTransport` should use `ChcClient` instead.

### PHP 8.3+ and symfony/http-client ^6.4 || ^7.0 (ADR-0002 #10)

PHP 8.3 or newer is required, with `symfony/http-client` version `^6.4 || ^7.0`.

_Other accepted ADR-0002 items are added as they are implemented._
