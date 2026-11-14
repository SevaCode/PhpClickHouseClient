# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/ClickHouse/ClickHouse) HTTP interface

# Use examples
````php
$data = (new ChcClient())->getJsonData($query);
````

## Immutable configuration (recommended, 2.0-compatible)

Each `with*()` method returns a configured clone, so assign the returned client. The constructor
also accepts the URL and an optional Symfony HTTP client:

````php
$client = (new ChcClient('http://localhost:8123/'))
    ->withCredentials('reader', 'secret')
    ->withDatabase('analytics')
    ->withSettings(['max_execution_time' => 5])
    ->withTimeout(10.0);

$writeClient = $client->withReadOnly(ChcReadOnly::Disabled); // or withReadOnly(0)
$writeClient->write('INSERT INTO events VALUES (1)');
````

`withReadOnly()` takes a `ChcReadOnly` case or its integer value; `getReadOnly()` returns `ChcReadOnly`:

| Level | `ChcReadOnly` | Meaning |
|---|---|---|
| 0 | `Disabled` | writes and DDL allowed |
| 1 | `Strict` | reads only, settings cannot be changed; for users whose profile sets `readonly=1` |
| 2 | `AllowSettings` | default: reads only, `SET`/`SETTINGS` allowed |

In 1.4 `Strict` and `AllowSettings` both use the existing GET reads (ClickHouse keeps the stricter
profile level); in 2.0 they send `readonly=1` / `readonly=2` over POST.
See [ClickHouse query permissions](https://clickhouse.com/docs/operations/settings/permissions-for-queries).

````php
$client = new ChcClient();

if (!$client->isAlive()) { // ping() throws ChcException when the server is unreachable
    throw new Exception('ClickHouse is down.');
}

try {
    $client->readOnly(true); // Deprecated in 1.4; use $client = $client->withReadOnly(ChcReadOnly::AllowSettings).
    $result = $client->getRaw($query, ChcFormat::JSONEachRow);
}
catch (ChcException $e) {
    $error = $e->getMessage();
}
$latency = $client->getLastQueryLatency();
````

## Query parameters

Values are sent separately from SQL as [server-side query parameters](https://clickhouse.com/docs/en/interfaces/http#cli-queries-with-parameters)
(`{name:Type}` placeholders), so they are never interpreted as SQL:

````php
$client = new ChcClient();
$client->setCredentials('reader', 'secret'); // Deprecated: use withCredentials().
$client->setTimeout(30);                     // Deprecated: use withTimeout().

$rows = $client->fetchAll(
    'SELECT * FROM events WHERE user_id = {id:UInt64} AND type IN {types:Array(String)} AND ts >= {from:DateTime}',
    ['id' => 42, 'types' => ['click', 'view'], 'from' => new DateTimeImmutable('-1 day')],
);
$row   = $client->fetchRow('SELECT * FROM users WHERE id = {id:UInt64}', ['id' => 42]);    // array|null
$count = $client->fetchOne('SELECT count() FROM users WHERE name = {n:String}', ['n' => "O'Reilly"]);
$ids   = $client->fetchColumn('SELECT id FROM users LIMIT {n:UInt8}', ['n' => 10]);

$client->readOnly(false); // Deprecated: use withReadOnly(ChcReadOnly::Disabled).
$client->write('INSERT INTO users VALUES ({id:UInt64}, {name:String})', ['id' => 43, 'name' => 'Ann']);
````

### Typed parameters

Named `ChcParam` factories render their own `{name:Type}` placeholder; the value is sent separately as
`param_<name>`:

````php
$id = ChcParam::UInt64(42);
$types = ChcParam::Array(['click', 'view'], ChcType::String(), 'types');
$name = ChcParam::String(null, nullable: true);
$rows = $client->fetchAll(
    "SELECT * FROM events WHERE user_id = $id AND type IN $types AND isNull($name)",
    [$id, $types, $name],
);
````

The name is optional; omitted names are generated as `chc_N`. Pass a parameter in a list or under a
matching string key. Use `nullable: true` for scalar factories or `ChcParam::Nullable($value, $type)`
for a nullable composite parameter. An unused `ChcParam` throws before the request is sent. Use
`ChcParam::of($value, $type)` and `ChcType::of()` for other types. `ChcType` is also `Stringable`,
for example `CAST(x AS $type)`.

The last argument of every method is per-query ClickHouse settings, e.g. `['max_execution_time' => 5]`.
PHP values map to: `null` → `NULL`, `bool` → `1`/`0` (`true`/`false` inside arrays), `DateTimeInterface` →
`Y-m-d H:i:s[.u]` (pass `'Y-m-d'` strings for `Date`), lists → `Array(...)`, string-keyed arrays → `Map(...)`.

Read-only queries are sent with GET by default. `$client->setReadOnlyOverPost(true)` sends them with POST and
`readonly=2` instead, so long SQL does not hit URL length limits and does not end up in proxy access logs.
`setReadOnlyOverPost()` is deprecated since 1.4 and removed in 2.0.

## Streaming, inserts, compression, HTTP client

````php
// Rows one by one (FORMAT JSONEachRow): memory does not depend on result size
foreach ($client->iterate('SELECT * FROM hits WHERE dt = {dt:Date}', ['dt' => '2024-01-01']) as $row) {
    // ...
}

// Streaming insert from an array or a generator; list rows are matched to $columns
$client->readOnly(false); // Deprecated: use withReadOnly(ChcReadOnly::Disabled).
$client->insert('db.events', $rowsGenerator);
$client->insert('db.events', [[1, 'click'], [2, 'view']], ['id', 'type']);

$client->setCompression(true);                              // Deprecated: use withCompression().
$client->setHttpClient(Symfony\Component\HttpClient\HttpClient::create()); // Deprecated: use withHttpClient().
$client->setPoolConcurrency(8);                             // Deprecated: use withPoolConcurrency().

// Parallel JSON queries: a ChcResult per key, in the order of the input array
$results = $client->pool(['a' => 'SELECT 1 AS n', 'b' => 'SELECT 2 AS n']);
$results['a']->data[0]['n']; // 1; also meta, rows, totals, extremes, statistics, queryId, summary
````

Errors that ClickHouse sends in the middle of a stream are thrown as `ChcException` after the rows received
before the error. `insert()` takes the table name as an identifier written into SQL as is — never pass user input.
`iterate()`, `insert()`, `pool()` and `getJsonPool()` (deprecated: use `pool()`) always use Symfony HttpClient; other queries use it only after
`setHttpClient()` (deprecated in 1.4; use `withHttpClient()` in new code).

## Query id, summary, sessions, retries, errors

````php
$client->fetchAll($sql, $params, ['query_id' => 'report-42']); // or let the server generate one
$client->getLastQueryId();       // X-ClickHouse-Query-Id — for KILL QUERY / system.query_log
$client->getLastQuerySummary();  // X-ClickHouse-Summary: ['read_rows' => '10', 'read_bytes' => '80', ...]

$client->setSessionId('import-' . bin2hex(random_bytes(8))); // Deprecated: use withSession().
$client->setRetries(3, 0.5); // Deprecated: use withRetries().

try {
    $client->fetchAll('SELECT * FROM missing');
} catch (ChcConnectionException $e) {   // no HTTP response (extends ChcException)
} catch (ChcException $e) {
    $e->getClickHouseErrorCode();  // 60 — ClickHouse error code, null if not a ClickHouse error
    $e->getHttpStatus();           // 404 — HTTP status, null if there was no response
    $e->getCode();                 // as before: ClickHouse error code if known, otherwise HTTP status
}
````

Notes:
- With `FORMAT JSON` ClickHouse returns `UInt64`/`Int64` as strings by default
  (`output_format_json_quote_64bit_integers`; newer servers may differ). Set it explicitly if you rely on the type:
  `addSettings(['output_format_json_quote_64bit_integers' => 0])` (deprecated; use `withSettings()`).
- An error that happens after ClickHouse started streaming the result comes with HTTP 200 and is appended
  to the body; the client detects it and throws `ChcException`. With `wait_end_of_query=1` the server buffers
  the result and reports such errors with a proper HTTP status, and `getLastQuerySummary()` is complete —
  at the cost of memory/latency on the server.

# Install through [Composer](https://getcomposer.org/)
````json
"require": {
    "seva-code/php-click-house-client": "^1.4"
}
````

# Development

Documentation for contributors lives in [`docs/`](docs/README.md):
the [roadmap](docs/roadmap/ROADMAP.md) and [architecture decision records](docs/adr/).

## Tests

```bash
composer install
composer test                 # unit tests
composer phpstan              # static analysis
```

Integration tests run against a real ClickHouse server:

```bash
docker run -d -p 8123:8123 -e CLICKHOUSE_SKIP_USER_SETUP=1 clickhouse/clickhouse-server
CLICKHOUSE_URL=http://localhost:8123/ composer test-integration
```

Optional `CLICKHOUSE_USER` / `CLICKHOUSE_PASSWORD` are passed to the client.
