<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

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
    public function __construct($url)
    {
        $this->url = $url;
    }

    public function run(ChcRequest $request)
    {
        $httpQueryValues = $request->getSettings();

        $streamOpts = array(
            'http'=>array(
                'method' => $this->method,
                'ignore_errors' => true,
            ),
        );

        if ($query = $request->getQuery()) {
            if ('POST' === $this->method) {
                $streamOpts['http']['header'] = "Content-type: application/x-www-form-urlencoded";
                $streamOpts['http']['content'] = $query;
            } else {
                $httpQueryValues['query'] = $query;
            }
        }

        $context = stream_context_create($streamOpts);
        $url = $this->url . '?' . http_build_query($httpQueryValues);

        // Выполняем запрос к ClickHouse
        $timeStart = microtime(true);
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            $this->last_query_latency = microtime(true) - $timeStart;
        }

        $httpStatusLine = $http_response_header[0];

        preg_match('{HTTP\/\S*\s(\d{3})}', $httpStatusLine, $match);

        $httpStatus = $match[1];

        if (200 != $httpStatus) {
            if (empty($body)) {
                $body = 'Unexpected response status: ' . $httpStatusLine;
            }
            $e = new ChcException($body, $httpStatus);
            $e->setQuery($query);
            throw $e;
        }

        return (new ChcResponseFactory($request->getReturnFormat()))
            ->make($body);
    }

    /**
     * @param boolean $mode
     * @return $this
     */
    public function setReadOnly($mode)
    {
        $this->method = $mode ? 'GET' : 'POST';
        return $this;
    }

    /**
     * @return float
     */
    public function getLastQueryLatency()
    {
        return $this->last_query_latency;
    }
}