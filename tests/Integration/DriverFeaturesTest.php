<?php

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use Symfony\Component\HttpClient\HttpClient;

class DriverFeaturesTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function transports(): iterable
    {
        yield 'native' => [false];
        yield 'symfony' => [true];
    }

    /**
     * @dataProvider transports
     */
    public function testQueryIdSummaryAndSession(bool $symfony): void
    {
        $client = $this->client();
        if ($symfony) {
            $client->setHttpClient(HttpClient::create());
        }

        $client->fetchAll('SELECT number FROM numbers(10)', [], ['wait_end_of_query' => 1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$client->getLastQueryId());
        $this->assertSame('10', $client->getLastQuerySummary()['read_rows'] ?? null);

        $client->fetchAll('SELECT 1', [], ['query_id' => 'chc-it-' . ($id = bin2hex(random_bytes(4)))]);
        $this->assertSame("chc-it-$id", $client->getLastQueryId());

        $client->setSessionId('chc-it-' . bin2hex(random_bytes(4)));
        $client->readOnly(false);
        $client->write('CREATE TEMPORARY TABLE tmp (x UInt8)');
        $client->write('INSERT INTO tmp VALUES (7)');
        $client->readOnly(true);
        $this->assertSame(7, $client->fetchOne('SELECT x FROM tmp'));
        foreach ($client->iterate('SELECT x FROM tmp') as $row) {
            $this->assertSame(['x' => 7], $row);
        }
        $this->assertNotNull($client->getLastQueryId());

        $client->setSessionId(null);
        $e = $this->caughtException(fn() => $client->fetchOne('SELECT x FROM tmp'), ChcException::class);
        $this->assertSame(60, $e->getServerCode()); // UNKNOWN_TABLE
        $this->assertSame(404, $e->getHttpStatus());
    }

    public function testConnectionErrorsAndIsAlive(): void
    {
        $this->assertTrue($this->client()->isAlive());

        $client = new ChcClient();
        $client->setUrl('http://127.0.0.1:1/');
        $client->setRetries(2, 0.01);
        $this->assertFalse(@$client->isAlive());
        $this->caughtException(fn() => @$client->fetchAll('SELECT 1'), ChcConnectionException::class);
        $client->setHttpClient(HttpClient::create());
        $this->caughtException(fn() => $client->fetchAll('SELECT 1'), ChcConnectionException::class);
        $this->caughtException(fn() => iterator_to_array($client->iterate('SELECT 1')), ChcConnectionException::class);
    }
}
