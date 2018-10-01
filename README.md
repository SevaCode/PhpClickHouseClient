# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/yandex/ClickHouse) HTTP interface

# Use examples
````php
$data = (new HttpClient())->getData($query);
````

````php
$client = new HttpClient();

try {
    if ($client->ping()) {
        $client->readOnly(true);
        $client->setFormat(Format::JSONEachRow);
        $result = $client->query($query);
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
    "SevaCode/PhpClickHouseClient": "^1.1-dev"
},
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/SevaCode/PhpClickHouseClient.git"
    }
]
````