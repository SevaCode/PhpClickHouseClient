<?php
/**
 * Author: SevaCode
 * CreatedAt: 14.06.2024
 */

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Responses\ChcResponseFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @link https://symfony.com/doc/current/http_client.html
 */
class ChcSymfonyCurlTransport
{
    private string $method = 'GET';
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function request(ChcRequest $request): ExtendedSymfonyHttpResponse
    {
        $httpQueryValues = $request->getSettings();

        $query = $request->getQuery();

        // пустой запрос (ping) всегда отпрвляем GET'ом
        $httpMethod = empty($query) ? 'GET' : $this->method;

        $streamOpts = [];

        $httpHeader = [];

        // Authentication using the "X-ClickHouse-User" and "X-ClickHouse-Key" headers.
        // https://clickhouse.com/docs/en/interfaces/http/
        if (isset($httpQueryValues['user'])) {
            if (!empty($httpQueryValues['user'])) {
                $httpHeader['X-ClickHouse-User'] = $httpQueryValues['user'];
            }
            unset($httpQueryValues['user']);
        }
        if (isset($httpQueryValues['password'])) {
            if (!empty($httpQueryValues['password'])) {
                $httpHeader['X-ClickHouse-Key'] = $httpQueryValues['password'];
            }
            unset($httpQueryValues['password']);
        }

        if (isset($httpQueryValues['ssl_cafile'])) {
            if (!empty($httpQueryValues['ssl_cafile'])) {
                $streamOpts['cafile'] = $httpQueryValues['ssl_cafile'];
                $streamOpts['verify_peer'] = true;
            }
            unset($httpQueryValues['ssl_cafile']);
        }

        if (empty($query)) {
            $url = '/ping';
        } else {
            $url = '';
            if ('POST' === $httpMethod) {
                $httpHeader['Content-type'] = 'application/x-www-form-urlencoded';
                $streamOpts['body'] = $query;
            } else {
                $streamOpts['query'] = $httpQueryValues;
                $streamOpts['query']['query'] = $query;
            }
        }

        if (!empty($httpHeader)) {
            $streamOpts['headers'] = $httpHeader;
        }

        return new ExtendedSymfonyHttpResponse(
            decoratedResponse: $this->client->request($this->method, $url, $streamOpts),
            request: $request,
            query: $query,
        );
    }

    public function response(ExtendedSymfonyHttpResponse $response): ChcResponse
    {
        // Выполняем запрос к ClickHouse
        $body = $response->getContent();

        $httpStatus = $response->getStatusCode();

        if (200 != $httpStatus) {
            if (empty($body)) {
                $body = 'Unexpected response status: ' . $httpStatus;
            }
            $e = new ChcException($body, $httpStatus);
            $e->setQuery($response->getQuery());
            throw $e;
        }

        return (new ChcResponseFactory($response->getRequest()->getReturnFormat()))
            ->make($body);
    }

    public function setReadOnly(bool $isReadOnly): static
    {
        $this->method = $isReadOnly ? 'GET' : 'POST';
        return $this;
    }
}
