<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ChcSymfonyCurlTransportTest extends TestCase
{
    public function testResponseFailuresPreserveStatusClickHouseErrorCodeAndContext(): void
    {
        $memoryBody = '{"data":[{"x":1}]' . "\nCode: 241. DB::Exception: query failed";
        $timeoutBody = '{"data":[{"x":1}]' . "\nCode: 159. DB::Exception: query failed";
        $cases = [
            ['partial {', 200, ChcException::class, 'partial {', 200],
            ['0', 200, ChcException::class, '0', 200],
            ['', 200, ChcException::class, 'Empty response body', 200],
            [$memoryBody, 200, ChcMemoryLimitExceededException::class, $memoryBody, 241],
            [$timeoutBody, 200, ChcTimeoutExceededException::class, $timeoutBody, 159],
            ['Code: 60. DB::Exception: Unknown table', 500, ChcException::class, 'Code: 60. DB::Exception: Unknown table', 60],
            ["1\t2\nCode: 395. DB::Exception: boom (FOO) (version 1.0 (official build))", 200, ChcException::class, 'Code: 395. DB::Exception: boom (FOO) (version 1.0 (official build))', 395],
            ['Proxy error', 502, ChcException::class, 'Proxy error', 502],
            ['', 503, ChcException::class, 'Unexpected response status: 503', 503],
        ];
        foreach ($cases as [$body, $status, $class, $message, $code]) {
            $e = $this->caughtException(fn() => $this->response($body, $status)->getResponse(), $class);
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($code, $e->getCode());
            $this->assertSame('query-context', $e->getQuery());
        }
    }

    public function testResponseFormatComesFromXClickHouseFormatHeader(): void
    {
        $make = function (array $info) {
            $transport = new ChcSymfonyCurlTransport(new MockHttpClient(new MockResponse('{"data":[{"x":1}],"rows":1}', $info)));
            $req = (new ChcRequest())->setSettings([])->setQuery('SELECT 1 FORMAT JSON')->setReturnFormat('');
            return $transport->response($transport->request($req));
        };

        // Заголовок есть — реальный формат сервера важнее returnFormat
        $response = $make(['http_code' => 200, 'response_headers' => [0 => 'HTTP/1.1 200 OK', 'x-clickhouse-format' => 'JSON']]);
        $this->assertSame(ChcFormat::JSON, $response->getFormat());
        $this->assertSame([['x' => 1]], $response->getResponse()['data']);

        // Заголовка нет (DDL/ping/старый сервер) — fallback на returnFormat
        $response = $make(['http_code' => 200]);
        $this->assertSame('', $response->getFormat());
        $this->assertSame('{"data":[{"x":1}],"rows":1}', $response->getResponse());

        // Сервер сказал TabSeparated — сырой ответ, не JSON-парсер
        $transport = new ChcSymfonyCurlTransport(new MockHttpClient(new MockResponse('{"data":[]}', [
            'http_code' => 200,
            'response_headers' => [0 => 'HTTP/1.1 200 OK', 'x-clickhouse-format' => 'TabSeparated'],
        ])));
        $req = (new ChcRequest())->setSettings([])->setQuery('SELECT 1')->setReturnFormat('');
        $response = $transport->response($transport->request($req));
        $this->assertSame('', $response->getFormat());
        $this->assertSame('{"data":[]}', $response->getResponse());
    }

    public function testForwardsMethodAndSettings(): void
    {
        $settings = ['database' => 'analytics', 'max_threads' => 2, 'user' => 'alice', 'password' => 'secret'];
        foreach ([[true, 'GET'], [false, 'POST']] as [$readOnly, $method]) {
            $client = new MockHttpClient(function ($actualMethod, $url, $options) use ($method) {
                $this->assertSame($method, $actualMethod);
                parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
                $this->assertSame('analytics', $params['database'] ?? null);
                $this->assertSame('2', $params['max_threads'] ?? null);
                $this->assertArrayNotHasKey('user', $params);
                $this->assertArrayNotHasKey('password', $params);
                $this->assertContains('X-ClickHouse-User: alice', $options['headers']);
                $this->assertContains('X-ClickHouse-Key: secret', $options['headers']);
                return new MockResponse();
            });
            (new ChcSymfonyCurlTransport($client))->setReadOnly($readOnly)
                ->request($this->request()->setSettings($settings));
        }
    }

    public function testPingStaysGetEvenInReadWriteMode(): void
    {
        $client = new MockHttpClient(function ($method, $url) {
            $this->assertSame('GET', $method);
            $this->assertSame('/ping', parse_url($url, PHP_URL_PATH));
            $this->assertNull(parse_url($url, PHP_URL_QUERY));
            return new MockResponse("Ok.\n", ['http_code' => 200]);
        });
        $transport = (new ChcSymfonyCurlTransport($client))->setReadOnly(false);
        $response = $transport->response($transport->request($this->request('', '')->setSettings(['database' => 'analytics'])));
        $this->assertSame("Ok.\n", $response->getResponse());
    }
}
