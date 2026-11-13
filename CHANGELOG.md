# Changelog

All notable changes. The project follows [Semantic Versioning](https://semver.org/);
1.x keeps backward compatibility (see `docs/adr/0001-backward-compatibility.md`).

## [Unreleased]

### Added
- Server-side query parameters (`{name:Type}` + `ChcParamEncoder`) and `fetchAll()`, `fetchRow()`,
  `fetchOne()`, `fetchColumn()`, `write()` with per-query settings.
- `setCredentials()`, `setTimeout()`, opt-in `setReadOnlyOverPost()`.
- `setHttpClient()` (Symfony HttpClient for all requests: keep-alive, proxies, TLS), `setCompression()`,
  `setPoolConcurrency()`.
- Streaming `iterate()` (JSONEachRow) and `insert()` (streamed, optionally gzip'ed body).
- `getLastQueryId()`, `getLastQuerySummary()`, `ChcResponse::getQueryId()/getSummary()/getHeaderLines()`.
- `setSessionId()`, opt-in `setRetries()` for read-only queries, `isAlive()`.
- `ChcConnectionException` (extends `ChcException`), `ChcException::getServerCode()/getHttpStatus()`.
- PHPUnit, PHPStan, integration tests against ClickHouse 23.8 / 24.8 / latest, GitHub Actions CI.
- `docs/`: roadmap and ADRs.

### Changed
- `psr/log` declared explicitly; `symfony/http-client` `^6.2 || ^7.0`.
- Errors without an HTTP response are now `ChcConnectionException` (still a `ChcException`, same code and message).
- `getJsonPool()` fills `getLastQueryLatency()` and cancels pending requests on error.

### Fixed
- Mid-stream errors of ClickHouse 23.x (plain text right after JSON) and 25.x+
  (`__exception__` block with `X-ClickHouse-Exception-Tag`) are detected.
- `ExtendedSymfonyHttpResponse::getInfo()` implicit nullable parameter (PHP 8.4 deprecation).
