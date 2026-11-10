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

# Tests

Run the regression tests from the repository root after installing dependencies:

````bash
composer install
php tests/run.php
````

The tests use mocks and do not require a running ClickHouse server or PHPUnit.
Run the script as a standalone PHP process because it replaces the HTTP client with a test double.

A test deliberately triggers a stream failure to verify that its details are preserved
in the exception. A `file_get_contents()` warning is expected in the output.
A successful run ends with `0 failed` and exits with code `0`.