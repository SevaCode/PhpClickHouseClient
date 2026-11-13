<?php

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\Tests\TestCase;

/**
 * Runs against a real ClickHouse server, e.g.
 * CLICKHOUSE_URL=http://localhost:8123/ vendor/bin/phpunit --testsuite integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function url(): string
    {
        $url = getenv('CLICKHOUSE_URL');
        if (!$url) {
            $this->markTestSkipped('Set CLICKHOUSE_URL to run integration tests.');
        }
        return $url;
    }

    protected function client(): ChcClient
    {
        $client = new ChcClient();
        $client->setUrl($this->url());
        if (getenv('CLICKHOUSE_USER')) {
            $client->addSettings([
                'user' => getenv('CLICKHOUSE_USER'),
                'password' => (string)getenv('CLICKHOUSE_PASSWORD'),
            ]);
        }
        return $client;
    }
}
