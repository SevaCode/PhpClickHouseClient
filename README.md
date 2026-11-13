# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/yandex/ClickHouse) HTTP interface

# Use examples
````php
$data = (new ChcClient())->getJsonData($query);
````

````php
$client = new ChcClient();

try {
    if ($client->ping()) {
        $client->readOnly(true);
        $result = $client->getRaw($query, ChcFormat::JSONEachRow);
    } else {
        throw new Exception('ClickHouse is down.');
    }
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
$client->setCredentials('reader', 'secret'); // X-ClickHouse-User / X-ClickHouse-Key headers
$client->setTimeout(30);                     // HTTP timeout, seconds

$rows = $client->fetchAll(
    'SELECT * FROM events WHERE user_id = {id:UInt64} AND type IN {types:Array(String)} AND ts >= {from:DateTime}',
    ['id' => 42, 'types' => ['click', 'view'], 'from' => new DateTimeImmutable('-1 day')],
);
$row   = $client->fetchRow('SELECT * FROM users WHERE id = {id:UInt64}', ['id' => 42]);    // array|null
$count = $client->fetchOne('SELECT count() FROM users WHERE name = {n:String}', ['n' => "O'Reilly"]);
$ids   = $client->fetchColumn('SELECT id FROM users LIMIT {n:UInt8}', ['n' => 10]);

$client->readOnly(false);
$client->write('INSERT INTO users VALUES ({id:UInt64}, {name:String})', ['id' => 43, 'name' => 'Ann']);
````

The last argument of every method is per-query ClickHouse settings, e.g. `['max_execution_time' => 5]`.
PHP values map to: `null` → `NULL`, `bool` → `1`/`0` (`true`/`false` inside arrays), `DateTimeInterface` →
`Y-m-d H:i:s[.u]` (pass `'Y-m-d'` strings for `Date`), lists → `Array(...)`, string-keyed arrays → `Map(...)`.

Read-only queries are sent with GET by default. `$client->setReadOnlyOverPost(true)` sends them with POST and
`readonly=2` instead, so long SQL does not hit URL length limits and does not end up in proxy access logs.

# Install through [Composer](https://getcomposer.org/)
````json
"require": {
    "seva-code/php-click-house-client": "^1.2.0-dev"
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
