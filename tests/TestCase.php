<?php

namespace SevaCode\ClickHouseClient\Tests;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @template T of ChcException
     * @param class-string<T> $class
     * @return T
     */
    protected function caughtException(callable $run, string $class): ChcException
    {
        try {
            $run();
        } catch (ChcException $e) {
            $this->assertSame($class, get_class($e));
            return $e;
        }
        $this->fail('Expected ' . $class);
    }

    protected function request(string $query = 'query-context', string $format = ChcFormat::JSON): ChcRequest
    {
        return (new ChcRequest())->setSettings([])->setQuery($query)->setReturnFormat($format);
    }

    protected function response(string $body, int $status = 200): ChcResponse
    {
        $transport = new ChcSymfonyCurlTransport(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));
        return $transport->response($transport->request($this->request()));
    }

    /**
     * ChcClient whose concurrent requests go to a MockHttpClient.
     *
     * @param callable|MockResponse|iterable<MockResponse> $responseFactory
     */
    protected function mockedClient(callable|MockResponse|iterable $responseFactory): ChcClient
    {
        return new class($responseFactory) extends ChcClient {
            public function __construct(private readonly mixed $responseFactory)
            {
            }

            protected function createHttpClient(): HttpClientInterface
            {
                return new MockHttpClient($this->responseFactory, $this->url);
            }
        };
    }
}
