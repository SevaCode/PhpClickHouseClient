# Changelog

All notable changes. The project follows [Semantic Versioning](https://semver.org/);
1.x keeps backward compatibility (see `docs/adr/0001-backward-compatibility.md`).

## [Unreleased]

### Added
- Typed query parameters with `ChcType`, `ChcParam::of()`, named `ChcParam` factories, list parameters,
  and validation for unused typed parameters.

### Changed
- Typed scalar factories and typed array elements reject incompatible PHP values without coercion.
  Wide integers and decimals accept exact strings; raw type strings and raw parameters are unchanged.
- `ChcParam::DateTime()` and `ChcParam::ArrayDateTime()` infer the timezone from date objects and
  preserve their Unix timestamps. Fixed offsets use equivalent ClickHouse zones where available.
  An explicit timezone is required for unsupported offsets or arrays that mix timezones or date
  objects and strings.

## [2.0.0] — 2026-11-14

### Removed
- `HttpClient`, `Mode`, and `Transports\ChcHttpTransport`.
- Mutable `ChcClient` setters and the `readOnly()` getter/setter.
- `setReadOnlyOverPost()`; read queries now use POST by default.

### Changed
- All requests use Symfony HttpClient with a shared default client.
- PHP >= 8.3; `symfony/http-client` `^6.4 || ^7.0`.
- Strict types and native parameter, return, and property types throughout PHP source and tests.
- `ChcFormat` is a backed enum; format arguments accept `ChcFormat|string|null`.
- `ChcClient` configuration uses immutable `withX()` methods; assign each returned client.
- Non-ping queries use POST with SQL in the body and `Content-Type: text/plain; charset=UTF-8`;
  the default read-only level is `2`, configurable with `withReadOnly(ChcReadOnly|int)`;
  `ChcReadOnly` enum: `Disabled` (0), `Strict` (1), `AllowSettings` (2).
- `ping()` returns `false` instead of throwing for client/server errors; `isAlive()` is deprecated.
- Exceptions distinguish ClickHouse server errors, connection failures/client timeouts, and HTTP
  errors without a ClickHouse code. `getCode()` returns the ClickHouse code or `0`; use
  `getHttpStatus()` for the HTTP status. `ChcTimeoutExceededException` represents server error 159.
- `pool(array $queries): array<key, ChcResult>` replaces callback-based `getJsonPool()`; `ChcResult`
  exposes `data`, `meta`, `rows`, `rowsBeforeLimitAtLeast`, `statistics`, `totals`, `extremes`,
  `startTime`, `queryId` and `summary`. `getJsonPool()` is deprecated (removal in 3.0).

## [1.4.0] — 2026-11-14

### Deprecated
- `HttpClient`, `Mode`, `Transports\ChcHttpTransport`, and `ChcClient::setReadOnlyOverPost()` are removed in 2.0; see ADR-0002.
- `ChcClient::readOnly()`, `setDatabase()`, `addSettings()`, `setCredentials()`, `setTimeout()`,
  `setHttpClient()`, `setCompression()`, `setPoolConcurrency()`, `setRetries()`, `setSessionId()`,
  `setUrl()`, and `setSqlLogger()`; use the corresponding `with*()` method (or `getReadOnly()`).
- `ChcClient::getJsonPool()`; use `pool()`. In 2.0 it stays as a deprecated wrapper, removed in 3.0.

### Added
- Immutable `ChcClient::with*()` configuration methods, constructor `(url, httpClient)`, and `getReadOnly()`.
- `ChcReadOnly` enum (`Disabled`, `Strict`, `AllowSettings`); `withReadOnly()` accepts it or an int 0–2.
- `ChcClient::pool()` returning a `ChcResult` per query (`data`, `meta`, `rows`, `rowsBeforeLimitAtLeast`,
  `statistics`, `totals`, `extremes`, `startTime`, `queryId`, `summary`).
- Server-side query parameters (`{name:Type}` + `ChcParamEncoder`) and `fetchAll()`, `fetchRow()`,
  `fetchOne()`, `fetchColumn()`, `write()` with per-query settings.
- `setCredentials()`, `setTimeout()`, opt-in `setReadOnlyOverPost()`.
- `setHttpClient()` (Symfony HttpClient for all requests: keep-alive, proxies, TLS), `setCompression()`,
  `setPoolConcurrency()`.
- Streaming `iterate()` (JSONEachRow) and `insert()` (streamed, optionally gzip'ed body).
- `getLastQueryId()`, `getLastQuerySummary()`, `ChcResponse::getQueryId()/getSummary()/getHeaderLines()`.
- `setSessionId()`, opt-in `setRetries()` for read-only queries, `isAlive()`.
- `ChcConnectionException` (extends `ChcException`), `ChcException::getClickHouseErrorCode()/getHttpStatus()`.
- PHPUnit, PHPStan, integration tests against ClickHouse 24.1 / 24.8 / latest, GitHub Actions CI.
- `docs/`: roadmap and ADRs.

### Changed
- `psr/log` declared explicitly; `symfony/http-client` `^6.2 || ^7.0`.
- Errors without an HTTP response are now `ChcConnectionException` (still a `ChcException`, same code and message).
- `getJsonPool()` fills `getLastQueryLatency()` and cancels pending requests on error.

### Fixed
- Mid-stream errors of ClickHouse 25.x+ (`__exception__` block with `X-ClickHouse-Exception-Tag`) are detected.
- `ExtendedSymfonyHttpResponse::getInfo()` implicit nullable parameter (PHP 8.4 deprecation).
