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
    private HttpClientInterface $client;
    private bool $readOnly = true;
    private bool $readOnlyOverPost = false;
    private ?float $timeout = null;
    private bool $compression = false;
    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function request(ChcRequest $request): ExtendedSymfonyHttpResponse
    {
        $http = ChcHttpRequestBuilder::build($request, $this->readOnly, $this->readOnlyOverPost, $this->compression);

        // gzip-ответ (enable_http_compression=1) Symfony HttpClient распаковывает сам
        $options = $this->options;
        if (!empty($http->query)) {
            $options['query'] = $http->query;
        }
        if (null !== $http->body) {
            $options['body'] = $http->body;
        }
        if (!empty($http->headers)) {
            $options['headers'] = $http->headers;
        }
        if (null !== $http->cafile) {
            $options['cafile'] = $http->cafile;
            $options['verify_peer'] = true;
        }
        if (null !== $this->timeout) {
            $options['timeout'] = $this->timeout;
        }

        return new ExtendedSymfonyHttpResponse(
            decoratedResponse: $this->client->request($http->method, '' === $http->path ? '' : '/' . $http->path, $options),
            request: $request,
            query: (string)$request->getQuery(),
        );
    }

    /**
     * POST with SQL in the URL and $body (chunks of data) streamed as the request body.
     *
     * @param iterable<string> $body
     */
    public function insert(ChcRequest $request, iterable $body, bool $gzip = false): ExtendedSymfonyHttpResponse
    {
        $http = ChcHttpRequestBuilder::build($request, false);
        $query = (string)$request->getQuery();

        $headers = $http->headers;
        unset($headers['Content-type']);
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        $options = $this->options;
        $options['query'] = $http->query + ['query' => $query];
        $options['body'] = $body instanceof \Generator ? $body : (static fn() => yield from $body)();
        if (!empty($headers)) {
            $options['headers'] = $headers;
        }
        if (null !== $http->cafile) {
            $options['cafile'] = $http->cafile;
            $options['verify_peer'] = true;
        }
        if (null !== $this->timeout) {
            $options['timeout'] = $this->timeout;
        }

        return new ExtendedSymfonyHttpResponse(
            decoratedResponse: $this->client->request('POST', '', $options),
            request: $request,
            query: $query,
        );
    }

    /**
     * Body chunks as they arrive.
     *
     * @return \Generator<int, string>
     */
    public function stream(ExtendedSymfonyHttpResponse $response): \Generator
    {
        foreach ($this->client->stream($response->getDecoratedResponse()) as $chunk) {
            $content = $chunk->getContent(); // throws on timeout / network error
            if ('' !== $content) {
                yield $content;
            }
        }
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
        return $this;
    }

    /**
     * Response compression: enable_http_compression=1, decoding is done by the HTTP client.
     */
    public function setCompression(bool $compression): static
    {
        $this->compression = $compression;
        return $this;
    }

    /**
     * Extra Symfony HttpClient options for every request (e.g. base_uri).
     *
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;
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
