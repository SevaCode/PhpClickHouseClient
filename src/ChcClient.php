<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcHttpTransport;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use SevaCode\ClickHouseClient\Transports\ExtendedSymfonyHttpResponse;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ChcClient
{
    protected $url = 'http://localhost:8123/';
    protected $settings = [
        //'database' => 'default',
        //'max_memory_usage' => 100000000,
        //'max_rows_to_group_by' => 2000000,
    ];

    protected $isReadOnly = true;

    /**
     * seconds
     * @var float
     */
    protected $last_query_latency;

    /**
     * @param boolean|null $isReadOnly
     * @return bool
     */
    public function readOnly($isReadOnly = null)
    {
        $old = $this->isReadOnly;

        if (!is_null($isReadOnly)) {
            $this->isReadOnly = (boolean)$isReadOnly;
        }

        return $old;
    }

    public function setDatabase($database)
    {
        $this->settings['database'] = $database;
    }

    /**
     * @param array $settings
     */
    public function addSettings($settings)
    {
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * @param string $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * seconds
     * @return float
     */
    public function getLastQueryLatency()
    {
        return $this->last_query_latency;
    }

    /**
     * @param $query
     * @param string $format
     * @return ChcRequest
     */
    protected function makeRequest($query, $format = '')
    {
        return (new ChcRequest)
            ->setSettings($this->settings)
            ->setQuery($query)
            ->setReturnFormat($format);
    }

    /**
     * @param ChcRequest $request
     * @return Responses\ChcResponse
     * @throws ChcException
     */
    protected function runRequest(ChcRequest $request): ChcResponse
    {
        $transport = (new ChcHttpTransport($this->url))
            ->setReadOnly($this->isReadOnly);

        try {
            return $transport->run($request);
        } finally {
            $this->last_query_latency = $transport->getLastQueryLatency();
        }
    }

    /**
     * @param string $query
     * @param string $returnFormat
     * @return ChcResponse
     */
    public function execute($query, $returnFormat = '')
    {
        return $this->runRequest($this->makeRequest($query, $returnFormat));
    }

    /**
     * @param string $query
     * @param string $returnFormat
     * @return string
     */
    public function getRaw($query, $returnFormat = '')
    {
        return $this->select($query, $returnFormat)->getBody();
    }

    /**
     * @param string $query
     * @return array
     */
    public function getJson($query)
    {
        return $this->select($query, ChcFormat::JSON)->getResponse();
    }

    /**
     * @param string $query
     * @return array|null
     */
    public function getJsonData($query)
    {
        return $this->getJson($query)['data'];
    }

    /**
     * @return true
     */
    public function ping()
    {
        return $this->getRaw('') === ('Ok.' . PHP_EOL);
    }

    /**
     * @param string $query
     * @param string $format
     * @return ChcResponse
     */
    private function select($query, $format)
    {
        return $this->execute($query . $this->getQueryFormatPostfix($format), $format);
    }

    private function getQueryFormatPostfix($format)
    {
        return $format ? PHP_EOL . 'FORMAT ' . $format : '';
    }

    /**
     * @param array<string> $queries
     * @param callable(array $data, array $meta, int $rows, ?int $rows_before_limit_at_least, ?array $statistics, ?array $totals, mixed $extremes, float $start_time): mixed $callback
     */
    public function getJsonPool(array $queries, callable $callback): array
    {
        $formatPostfix = $this->getQueryFormatPostfix(ChcFormat::JSON);

        $client = SymfonyHttpClient::create([
            'base_uri' => $this->url,
        ]);

        $transport = (new ChcSymfonyCurlTransport($client))
            ->setReadOnly($this->isReadOnly);

        try {

            $responses = array_map(
                fn(string $query) => $transport->request(
                    $this->makeRequest($query . $formatPostfix, ChcFormat::JSON)
                ), $queries
            );

            return array_map(
                function (ExtendedSymfonyHttpResponse $response) use ($transport, $callback) {
                    $jsonResponse = $transport->response($response)->getResponse();
                    return $callback(
                        $jsonResponse['data'],
                        $jsonResponse['meta'],
                        $jsonResponse['rows'],
                        $jsonResponse['rows_before_limit_at_least'] ?? null,
                        $jsonResponse['statistics'] ?? null,
                        $jsonResponse['totals'] ?? null,
                        $jsonResponse['extremes'] ?? null,
                        $response->getInfo('start_time'),
                    );
                }, $responses
            );

        } catch (TransportExceptionInterface $e) {
            $e = new ChcException($symfonyResponse->getContent(), $symfonyResponse->getStatusCode(), $e);
            $e->setQuery($symfonyResponse->getQuery());
            throw $e;
        }

//        /**
//         * @var ExtendedSymfonyHttpResponse $symfonyResponse
//         * @var ResponseStreamInterface $chunk
//         */
//        foreach ($client->stream(array_keys($responses)) as $symfonyResponse => $chunk) {
//            try {
//                if ($chunk->isTimeout()) {
//                    // ... decide what to do when a timeout occurs
//                    // if you want to stop a response that timed out, don't miss
//                    // calling $response->cancel() or the destructor of the response
//                    // will try to complete it one more time
//                } elseif ($chunk->isFirst()) {
//                    // headers of $response just arrived
//                    // $response->getHeaders() is now a non-blocking call
//                    // if you want to check the status code, you must do it when the
//                    // first chunk arrived, using $response->getStatusCode();
//                    // not doing so might trigger an HttpExceptionInterface
//                } elseif ($chunk->isLast()) {
//                    // the full content of $response just completed
//                    // $response->getContent() is now a non-blocking call
//                    $response = $responses[$symfonyResponse];
//                    $chcResponse = $transport->response($symfonyResponse)->getResponse();
//                    $result[$symfonyResponse->getRequest()->getKey()] = $callback(
//                        $chcResponse['data'],
//                        $chcResponse['meta'],
//                        $chcResponse['rows'],
//                        $chcResponse['rows_before_limit_at_least'] ?? null,
//                        $chcResponse['statistics'] ?? null,
//                        $chcResponse['totals'] ?? null,
//                        $chcResponse['extremes'] ?? null,
//                        $symfonyResponse->getInfo('start_time'),
//                    );
//                } else {
//                    // $chunk->getContent() will return a piece
//                    // of the response body that just arrived
//                }
//            } catch (TransportExceptionInterface $e) {
//                $e = new ChcException($symfonyResponse->getContent(), $symfonyResponse->getStatusCode(), $e);
//                $e->setQuery($symfonyResponse->getQuery());
//                throw $e;
//            }
//        }
//
//        return $result;
    }
}
