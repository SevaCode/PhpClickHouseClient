<?php
/**
 * Author: SevaCode
 * CreatedAt: 14.06.2024
 */

namespace SevaCode\ClickHouseClient\Transports;

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
    private bool $readOnly = true;
    private bool $readOnlyOverPost = false;
    private ?float $timeout = null;

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

        // Opt-in: read-only запрос POST'ом с readonly=2 — то же, что сервер выставляет для GET
        if (!empty($query) && $this->readOnly && $this->readOnlyOverPost) {
            $httpMethod = 'POST';
            if (!isset($httpQueryValues['readonly'])) {
                $httpQueryValues['readonly'] = 2;
            }
        }

        $streamOpts = [];
        if (null !== $this->timeout) {
            $streamOpts['timeout'] = $this->timeout;
        }

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
            $streamOpts['query'] = $httpQueryValues;
            if ('POST' === $httpMethod) {
                $httpHeader['Content-type'] = 'application/x-www-form-urlencoded';
                $streamOpts['body'] = $query;
            } else {
                $streamOpts['query']['query'] = $query;
            }
        }

        if (!empty($httpHeader)) {
            $streamOpts['headers'] = $httpHeader;
        }

        return new ExtendedSymfonyHttpResponse(
            decoratedResponse: $this->client->request($httpMethod, $url, $streamOpts),
            request: $request,
            query: $query,
        );
    }

    public function response(ExtendedSymfonyHttpResponse $response): ChcResponse
    {
        // Выполняем запрос к ClickHouse
        $body = $response->getContent(false);

        $httpStatus = $response->getStatusCode();

        // Сырые строки заголовков — из них ChcErrorDetector берёт статус-строку
        // и X-ClickHouse-Format. См. ChcErrorDetector.
        $headerLines = $response->getInfo('response_headers') ?? [];

        ChcErrorDetector::throwIfError($body, $httpStatus, $headerLines, $response->getQuery());

        // X-ClickHouse-Format — эхо реального формата ответа от сервера, надёжнее
        // getReturnFormat(): FORMAT можно вписать в query вручную, а execute()
        // не дописывает FORMAT-клаузу. Заголовка нет у DDL/ping — fallback на request.
        return (new ChcResponseFactory(ChcErrorDetector::responseFormat($headerLines) ?? $response->getRequest()->getReturnFormat()))
            ->make($body, $httpStatus, $response->getQuery());
    }

    public function setReadOnly(bool $isReadOnly): static
    {
        $this->readOnly = $isReadOnly;
        $this->method = $isReadOnly ? 'GET' : 'POST';
        return $this;
    }

    public function setReadOnlyOverPost(bool $readOnlyOverPost): static
    {
        $this->readOnlyOverPost = $readOnlyOverPost;
        return $this;
    }

    /**
     * Idle timeout in seconds; null — the HTTP client default.
     */
    public function setTimeout(?float $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }
}
