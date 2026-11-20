# Changelog

All notable changes. The project follows [Semantic Versioning](https://semver.org/);
1.x keeps backward compatibility (see `docs/adr/0001-backward-compatibility.md`).

## [Unreleased]

### Added
- Typed query parameters with `ChcType`, `ChcParam::of()`, named `ChcParam` factories, list parameters,
  and validation for unused typed parameters.

### Fixed
- Typed `Array(UInt64)` parameters send in-range decimal string IDs without quotes or loss of precision.

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
