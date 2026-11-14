# Changelog

All notable changes. The project follows [Semantic Versioning](https://semver.org/);
1.x keeps backward compatibility (see `docs/adr/0001-backward-compatibility.md`).

## [Unreleased] — 2.0

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

## [1.4]

### Added
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
