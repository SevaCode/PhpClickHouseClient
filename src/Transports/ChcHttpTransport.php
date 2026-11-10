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
    private $method = 'GET';
    /**
     * @var string
     */
    private $url;
    /**
     * @var float
     */
    private $last_query_latency;

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
        $httpQueryValues = $request->getSettings();

        $query = $request->getQuery();

        // пустой запрос (ping) всегда отпрвляем GET'ом
        $httpMethod = empty($query) ? 'GET' : $this->method;

        $streamOpts = array(
            'http'=>array(
                'method' => $httpMethod,
                'ignore_errors' => true,
            ),
        );

        $httpHeader = [];

        // Authentication using the "X-ClickHouse-User" and "X-ClickHouse-Key" headers.
        // https://clickhouse.com/docs/en/interfaces/http/
        if (isset($httpQueryValues['user'])) {
            if (!empty($httpQueryValues['user'])) {
                $httpHeader[] = 'X-ClickHouse-User: ' . $httpQueryValues['user'];
            }
            unset($httpQueryValues['user']);
        }
        if (isset($httpQueryValues['password'])) {
            if (!empty($httpQueryValues['password'])) {
                $httpHeader[] = 'X-ClickHouse-Key: ' . $httpQueryValues['password'];
            }
            unset($httpQueryValues['password']);
        }

        if (isset($httpQueryValues['ssl_cafile'])) {
            if (!empty($httpQueryValues['ssl_cafile'])) {
                $streamOpts['ssl'] = [
                    'cafile' => $httpQueryValues['ssl_cafile'],
                    'verify_peer' => true,
                ];
            }
            unset($httpQueryValues['ssl_cafile']);
        }

        if (empty($query)) {
            $httpPath = 'ping';
            $httpQueryValues = [];
        } else {
            $httpPath = '';
            if ('POST' === $httpMethod) {
                $httpHeader[] = "Content-type: application/x-www-form-urlencoded";
                $streamOpts['http']['content'] = $query;
            } else {
                $httpQueryValues['query'] = $query;
            }
        }

        if (!empty($httpHeader)) {
            $streamOpts['http']['header'] = $httpHeader;
        }

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

        $httpStatusLine = $http_response_header[0] ?? '';

        if (1 !== preg_match('{HTTP\/\S*\s(\d{3})}', $httpStatusLine, $match)) {
            throw ChcException::create(
                message: 'Unexpected response status: ' . $httpStatusLine,
                query: $query,
            );
        }

        $httpStatus = (int)$match[1];

        if (200 != $httpStatus) {
            if (empty($body)) {
                $body = 'Unexpected response status: ' . $httpStatusLine;
            }
            throw ChcException::create(
                message: $body,
                code: $httpStatus,
                query: $query,
            );
        }

        return (new ChcResponseFactory($request->getReturnFormat()))
            ->make($body, $httpStatus, $query);
    }

    public function setReadOnly(bool $isReadOnly): ChcHttpTransport
    {
        $this->method = $isReadOnly ? 'GET' : 'POST';
        return $this;
    }

    public function getLastQueryLatency(): float
    {
        return $this->last_query_latency;
    }
}
