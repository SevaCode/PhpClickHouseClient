# PhpClickHouseClient
PHP Client for [ClickHouse](https://github.com/yandex/ClickHouse) HTTP interface

# Use examples
````php
$data = (new HttpClient())->getData($query);
````

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

# Install through [Composer](https://getcomposer.org/)
````json
"require": {
	"SevaCode/PhpClickHouseClient": "^1.0-dev"
},
"repositories": [
	{
		"type": "vcs",
		"url": "https://github.com/SevaCode/PhpClickHouseClient.git"
	}
]
````