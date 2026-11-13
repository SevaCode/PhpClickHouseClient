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
