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