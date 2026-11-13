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
   - `getCode()` as HTTP status → `getHttpStatus()`, as server code → `getServerCode()`.
3. Upgrade to 2.0 and apply the changes below.

## Breaking changes

_To be filled in per accepted ADR-0002 item._
