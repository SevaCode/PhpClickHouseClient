# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/yandex/ClickHouse) HTTP interface

# Use example
````php
$client = new HttpClient();

try {
	$client->setMode(Mode::READONLY);
	$client->setFormat(Format::JSONEachRow);
	$result = $client->query($query);
}
catch (ClickHouseException $e) {
	$error = $e->getMessage();
}
$latency = $client->getLastQueryLatency();
````