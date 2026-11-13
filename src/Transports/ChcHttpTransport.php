<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\Responses\ChcResponseFactory;

class ChcHttpTransport
{
    /**
     * @var string
     */
    private $url;
    /**
     * @var float
     */
    private $last_query_latency;
    private bool $readOnly = true;
    private bool $readOnlyOverPost = false;
    private ?float $timeout = null;
    private bool $compression = false;

    /**
     * ChcHttpTransport constructor.
     * @param string $url
     */
    public function __construct(string $url)
    {
        $this->url = rtrim($url, '/');
    }

    public function run(ChcRequest $request)
    {
        $http = ChcHttpRequestBuilder::build($request, $this->readOnly, $this->readOnlyOverPost, $this->compression);
        $query = (string)$request->getQuery();

        $headers = $http->headers;
        if ($this->compression) {
            $headers['Accept-Encoding'] = 'gzip';
        }

        $streamOpts = [
            'http' => [
                'method' => $http->method,
                'ignore_errors' => true,
            ],
        ];
        if (null !== $this->timeout) {
            $streamOpts['http']['timeout'] = $this->timeout;
        }
        if (null !== $http->body) {
            $streamOpts['http']['content'] = $http->body;
        }
        if (!empty($headers)) {
            $streamOpts['http']['header'] = array_map(
                static fn(string $name, string $value) => "$name: $value",
                array_keys($headers),
                $headers,
            );
        }
        if (null !== $http->cafile) {
            $streamOpts['ssl'] = [
                'cafile' => $http->cafile,
                'verify_peer' => true,
            ];
        }
        $httpPath = $http->path;
        $httpQueryValues = $http->query;

        $context = stream_context_create($streamOpts);
        $url = $this->url . '/' . $httpPath . (empty($httpQueryValues) ? '' : ('?' . http_build_query($httpQueryValues)));

        // Выполняем запрос к ClickHouse
        $timeStart = microtime(true);
        try {
            error_clear_last();
            $body = file_get_contents($url, false, $context);
        } finally {
            $this->last_query_latency = microtime(true) - $timeStart;
        }

        if (false === $body || empty($http_response_header)) {
            throw ChcException::create(
                message: error_get_last()['message'] ?? 'Request failed',
                query: $query,
            );
        }

        $httpStatusLine = $http_response_header[0];

        if (1 !== preg_match('{HTTP\/\S*\s(\d{3})}', $httpStatusLine, $match)) {
            throw ChcException::create(
                message: 'Unexpected response status: ' . $httpStatusLine,
                query: $query,
            );
        }

        $httpStatus = (int)$match[1];

        foreach ($http_response_header as $line) {
            if (preg_match('{^Content-Encoding:\s*gzip}i', $line)) {
                $decoded = @gzdecode($body);
                if (false === $decoded) {
                    throw ChcException::create(message: 'Failed to decode gzip response', code: $httpStatus, query: $query);
                }
                $body = $decoded;
                break;
            }
        }

        // Не-200 статус либо exception, дописанный в конец тела посреди стриминга
        // (например, MEMORY_LIMIT_EXCEEDED) — см. ChcErrorDetector.
        ChcErrorDetector::throwIfError($body, $httpStatus, $http_response_header, $query);

        // X-ClickHouse-Format — эхо реального формата ответа от сервера, надёжнее
        // getReturnFormat(): FORMAT можно вписать в query вручную, а execute()
        // не дописывает FORMAT-клаузу. Заголовка нет у DDL/ping — fallback на request.
        return (new ChcResponseFactory(ChcErrorDetector::responseFormat($http_response_header) ?? $request->getReturnFormat()))
            ->make($body, $httpStatus, $query);
    }

    public function setReadOnly(bool $isReadOnly): ChcHttpTransport
    {
        $this->readOnly = $isReadOnly;
        return $this;
    }

    /**
     * Response compression: enable_http_compression=1 + Accept-Encoding: gzip.
     */
    public function setCompression(bool $compression): static
    {
        $this->compression = $compression;
        return $this;
    }

    public function setReadOnlyOverPost(bool $readOnlyOverPost): static
    {
        $this->readOnlyOverPost = $readOnlyOverPost;
        return $this;
    }

    /**
     * Read timeout in seconds; null — default_socket_timeout.
     */
    public function setTimeout(?float $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function getLastQueryLatency(): float
    {
        return $this->last_query_latency;
    }
}
