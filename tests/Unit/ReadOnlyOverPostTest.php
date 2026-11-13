<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReadOnlyOverPostTest extends TestCase
{
    /**
     * @return array{string, string, array<string, mixed>}
     */
    private function send(callable $configure, string $query = 'SELECT 1', array $settings = []): array
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, $options];
            return new MockResponse('1', ['http_code' => 200]);
        }, 'http://localhost:8123/');
        $transport = new ChcSymfonyCurlTransport($client);
        $configure($transport);
        $req = (new ChcRequest())->setSettings($settings)->setQuery($query)->setReturnFormat('');
        $transport->response($transport->request($req))->getResponse();
        return $captured;
    }

    public function testDefaultIsUnchanged(): void
    {
        [$method, $url, $options] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnly(true));
        $this->assertSame('GET', $method);
        $this->assertStringContainsString('query=SELECT', $url);
        $this->assertStringNotContainsString('readonly', $url);

        [$method, $url] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnly(false));
        $this->assertSame('POST', $method);
        $this->assertStringNotContainsString('readonly', $url);
    }

    public function testReadOnlyOverPost(): void
    {
        [$method, $url, $options] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnly(true)->setReadOnlyOverPost(true));
        $this->assertSame('POST', $method);
        $this->assertStringContainsString('readonly=2', $url);
        $this->assertStringNotContainsString('SELECT', $url);
        $this->assertSame('SELECT 1', $options['body']);

        // Явная настройка readonly пользователя не перетирается
        [, $url] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnly(true)->setReadOnlyOverPost(true), 'SELECT 1', ['readonly' => 1]);
        $this->assertStringContainsString('readonly=1', $url);

        // Для записи опция ни на что не влияет
        [$method, $url] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnly(false)->setReadOnlyOverPost(true));
        $this->assertSame('POST', $method);
        $this->assertStringNotContainsString('readonly', $url);

        // ping по-прежнему GET /ping
        [$method, $url] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setReadOnlyOverPost(true), '');
        $this->assertSame('GET', $method);
        $this->assertStringEndsWith('/ping', $url);
    }

    public function testTimeout(): void
    {
        [, , $options] = $this->send(fn(ChcSymfonyCurlTransport $t) => $t->setTimeout(2.5));
        $this->assertSame(2.5, $options['timeout']);
    }

    public function testClientPassesOptionsToPoolTransport(): void
    {
        $captured = [];
        $client = $this->mockedClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured[] = [$method, $url, $options['timeout']];
            return new MockResponse('{"data":[],"meta":[],"rows":0}', ['http_code' => 200]);
        });
        $client->setReadOnlyOverPost(true);
        $client->setTimeout(3.0);
        $client->setCredentials('u', 'p');
        $client->getJsonPool(['a' => 'SELECT 1'], fn() => null);

        $this->assertSame('POST', $captured[0][0]);
        $this->assertStringContainsString('readonly=2', $captured[0][1]);
        $this->assertStringNotContainsString('user', $captured[0][1]);
        $this->assertSame(3.0, $captured[0][2]);
    }
}
