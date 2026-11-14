# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/ClickHouse/ClickHouse) HTTP interface

## Requirements

PHP 8.3+ and `symfony/http-client` `^6.4 || ^7.0`.

# Use examples
````php
$data = (new ChcClient())->getJsonData($query);
````

````php
$client = new ChcClient();

if (!$client->ping()) {
    throw new Exception('ClickHouse is down.');
}

try {
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
$client = (new ChcClient())
    ->withCredentials('reader', 'secret') // X-ClickHouse-User / X-ClickHouse-Key headers
    ->withTimeout(30);                    // HTTP timeout, seconds

$rows = $client->fetchAll(
    'SELECT * FROM events WHERE user_id = {id:UInt64} AND type IN {types:Array(String)} AND ts >= {from:DateTime}',
    ['id' => 42, 'types' => ['click', 'view'], 'from' => new DateTimeImmutable('-1 day')],
);
$row   = $client->fetchRow('SELECT * FROM users WHERE id = {id:UInt64}', ['id' => 42]);    // array|null
$count = $client->fetchOne('SELECT count() FROM users WHERE name = {n:String}', ['n' => "O'Reilly"]);
$ids   = $client->fetchColumn('SELECT id FROM users LIMIT {n:UInt8}', ['n' => 10]);

$writeClient = $client->withReadOnly(0);
$writeClient->write('INSERT INTO users VALUES ({id:UInt64}, {name:String})', ['id' => 43, 'name' => 'Ann']);
````

The last argument of every method is per-query ClickHouse settings, e.g. `['max_execution_time' => 5]`.
PHP values map to: `null` → `NULL`, `bool` → `1`/`0` (`true`/`false` inside arrays), `DateTimeInterface` →
`Y-m-d H:i:s[.u]` (pass `'Y-m-d'` strings for `Date`), lists → `Array(...)`, string-keyed arrays → `Map(...)`.

Read queries use POST with SQL in the body and `readonly=2` by default, so they have no URL length limit
and SQL does not appear in proxy access logs. If a user's profile sets `readonly=1`, use
`$client = $client->withReadOnly(1);` (or `$client = $client->withSettings(['readonly' => 1]);`).

## Streaming, inserts, compression, HTTP client

````php
// Rows one by one (FORMAT JSONEachRow): memory does not depend on result size
foreach ($client->iterate('SELECT * FROM hits WHERE dt = {dt:Date}', ['dt' => '2024-01-01']) as $row) {
    // ...
}

// Streaming insert from an array or a generator; list rows are matched to $columns
$client = $client->withCompression(true)
    ->withHttpClient(Symfony\Component\HttpClient\HttpClient::create())
    ->withPoolConcurrency(8);
$writeClient = $client->withReadOnly(0);
$writeClient->insert('db.events', $rowsGenerator);
$writeClient->insert('db.events', [[1, 'click'], [2, 'view']], ['id', 'type']);
````

Errors that ClickHouse sends in the middle of a stream are thrown as `ChcServerException` after the rows received
before the error. `insert()` takes the table name as an identifier written into SQL as is — never pass user input.
All requests use Symfony HttpClient; inject a configured client with `withHttpClient()` when needed.

## Query id, summary, sessions, retries, errors

````php
$client->fetchAll($sql, $params, ['query_id' => 'report-42']); // or let the server generate one
$client->getLastQueryId();       // X-ClickHouse-Query-Id — for KILL QUERY / system.query_log
$client->getLastQuerySummary();  // X-ClickHouse-Summary: ['read_rows' => '10', 'read_bytes' => '80', ...]

$client = $client->withSession('import-' . bin2hex(random_bytes(8))); // temporary tables, SET
$client = $client->withRetries(3, 0.5); // read-only queries only: connection errors and HTTP 502/503/504

try {
    $client->fetchAll('SELECT * FROM missing');
} catch (ChcClientTimeoutException $e) { // HTTP client timeout (also a ChcConnectionException)
} catch (ChcConnectionException $e) {     // no HTTP response
} catch (ChcServerException $e) {         // ClickHouse error; memory/timeout subclasses included
    $e->getCode();                        // ClickHouse error code, e.g. 60
} catch (ChcHttpException $e) {           // HTTP status without a ClickHouse error code
    $e->getHttpStatus();
} catch (ChcException $e) {
    $e->getClickHouseErrorCode();  // 60 — ClickHouse error code, null if not a ClickHouse error
    $e->getHttpStatus();           // 404 — HTTP status, null if there was no response
    $e->getCode();                 // ClickHouse error code or 0, never the HTTP status
}
````

`ChcTimeoutExceededException` is reserved for server-side error 159; HTTP client timeouts use
`ChcClientTimeoutException`. `catch (ChcException)` still catches all client exceptions.
`isAlive()` remains as a deprecated alias for the non-throwing `ping()` and will be removed in 3.0.

Notes:
- With `FORMAT JSON` ClickHouse returns `UInt64`/`Int64` as strings by default
  (`output_format_json_quote_64bit_integers`; newer servers may differ). Set it explicitly if you rely on the type:
  `$client = $client->withSettings(['output_format_json_quote_64bit_integers' => 0])`.
- An error that happens after ClickHouse started streaming the result comes with HTTP 200 and is appended
  to the body; the client detects it and throws `ChcException`. With `wait_end_of_query=1` the server buffers
  the result and reports such errors with a proper HTTP status, and `getLastQuerySummary()` is complete —
  at the cost of memory/latency on the server.

# Install through [Composer](https://getcomposer.org/)
````json
"require": {
    "seva-code/php-click-house-client": "1.4.x-dev"
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
