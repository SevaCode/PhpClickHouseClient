<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

use Psr\Log\LoggerInterface;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcHttpTransport;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use SevaCode\ClickHouseClient\Transports\ExtendedSymfonyHttpResponse;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    private ?LoggerInterface $sqlLogger = null;
    private ?float $timeout = null;
    private bool $readOnlyOverPost = false;

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
     * Credentials are sent in X-ClickHouse-User / X-ClickHouse-Key headers,
     * never in the URL. Same as addSettings(['user' => ..., 'password' => ...]).
     */
    public function setCredentials(?string $user, ?string $password = null): void
    {
        $this->settings['user'] = $user;
        $this->settings['password'] = $password;
    }

    /**
     * HTTP read/idle timeout in seconds; null — transport default.
     * Server-side limit is a separate setting: addSettings(['max_execution_time' => N]).
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds;
    }

    /**
     * Opt-in: send read-only queries by POST with readonly=2 instead of GET.
     * The server applies readonly=2 to GET requests itself, so the guarantees are the same,
     * but SQL is not put into the URL (no URL length limit, no SQL in proxy access logs).
     */
    public function setReadOnlyOverPost(bool $enabled): void
    {
        $this->readOnlyOverPost = $enabled;
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
        $this->logSql((string)$query);

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
            ->setReadOnly($this->isReadOnly)
            ->setReadOnlyOverPost($this->readOnlyOverPost)
            ->setTimeout($this->timeout);

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
     * @return bool
     * @throws ChcException when the server is unreachable
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
     * All rows of a SELECT. Values are passed as server-side query parameters:
     *
     *     $client->fetchAll('SELECT * FROM t WHERE id = {id:UInt64} AND tag IN {tags:Array(String)}',
     *         ['id' => 5, 'tags' => ['a', 'b']]);
     *
     * @param array<string, mixed> $params values for {name:Type} placeholders
     * @param array<string, mixed> $settings ClickHouse settings for this query only
     * @return array<int, array<string, mixed>>
     * @throws ChcException
     */
    public function fetchAll(string $sql, array $params = [], array $settings = []): array
    {
        return $this->fetchJson($sql, $params, $settings)['data'];
    }

    /**
     * First row or null.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|null
     * @throws ChcException
     */
    public function fetchRow(string $sql, array $params = [], array $settings = []): ?array
    {
        return $this->fetchAll($sql, $params, $settings)[0] ?? null;
    }

    /**
     * First column of the first row or null.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @throws ChcException
     */
    public function fetchOne(string $sql, array $params = [], array $settings = []): mixed
    {
        $row = $this->fetchRow($sql, $params, $settings);
        return null === $row ? null : (array_values($row)[0] ?? null);
    }

    /**
     * First column of every row.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return array<int, mixed>
     * @throws ChcException
     */
    public function fetchColumn(string $sql, array $params = [], array $settings = []): array
    {
        return array_map(
            static fn(array $row) => array_values($row)[0] ?? null,
            $this->fetchAll($sql, $params, $settings),
        );
    }

    /**
     * DDL/DML (CREATE, INSERT ... VALUES, ALTER ...) with query parameters.
     * Honours readOnly(): call readOnly(false) first, as for execute().
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @throws ChcException
     */
    public function write(string $sql, array $params = [], array $settings = []): ChcResponse
    {
        return $this->runRequest($this->makeParamRequest($sql, $params, $settings, ''));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function fetchJson(string $sql, array $params, array $settings): array
    {
        $sql .= $this->getQueryFormatPostfix(ChcFormat::JSON);
        return $this->runRequest($this->makeParamRequest($sql, $params, $settings, ChcFormat::JSON))->getResponse();
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     */
    private function makeParamRequest(string $sql, array $params, array $settings, string $format): ChcRequest
    {
        $this->logSql($sql);

        return (new ChcRequest)
            ->setSettings(array_merge($this->settings, $settings, ChcParamEncoder::toHttpParams($params)))
            ->setQuery($sql)
            ->setReturnFormat($format);
    }

    /**
     * Sends all queries to ClickHouse in parallel and passes each JSON response
     * through $callback. The returned array is keyed by the original $queries keys.
     *
     * @param array<string> $queries
     * @param callable(array $data, array $meta, int $rows, ?int $rows_before_limit_at_least, ?array $statistics, ?array $totals, mixed $extremes, float $start_time): mixed $callback
     * @return array
     */
    public function getJsonPool(array $queries, callable $callback): array
    {
        $formatPostfix = $this->getQueryFormatPostfix(ChcFormat::JSON);

        $client = $this->createHttpClient();

        $transport = (new ChcSymfonyCurlTransport($client))
            ->setReadOnly($this->isReadOnly)
            ->setReadOnlyOverPost($this->readOnlyOverPost)
            ->setTimeout($this->timeout);

        $responses = [];
        foreach ($queries as $key => $query) {
            $request = $this->makeRequest($query . $formatPostfix, ChcFormat::JSON);
            try {
                $responses[$key] = $transport->request($request);
            } catch (TransportExceptionInterface $e) {
                throw ChcException::create(
                    message: $e->getMessage(),
                    previous: $e,
                    query: $request->getQuery(),
                );
            }
        }

        $result = [];
        foreach ($responses as $key => $response) {
            try {
                $jsonResponse = $transport->response($response)->getResponse();
            } catch (TransportExceptionInterface $e) {
                throw ChcException::create(
                    message: $e->getMessage(),
                    code: (int)$response->getInfo('http_code'),
                    previous: $e,
                    query: $response->getQuery(),
                );
            }
            $result[$key] = $callback(
                $jsonResponse['data'],
                $jsonResponse['meta'],
                $jsonResponse['rows'],
                $jsonResponse['rows_before_limit_at_least'] ?? null,
                $jsonResponse['statistics'] ?? null,
                $jsonResponse['totals'] ?? null,
                $jsonResponse['extremes'] ?? null,
                $response->getInfo('start_time'),
            );
        }

        return $result;

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

    /**
     * HTTP client for concurrent requests (getJsonPool).
     */
    protected function createHttpClient(): HttpClientInterface
    {
        return SymfonyHttpClient::create([
            'base_uri' => $this->url,
        ]);
    }

    public function setSqlLogger(?LoggerInterface $sqlLogger): void
    {
        $this->sqlLogger = $sqlLogger;
    }

    private function logSql(string $query): void
    {
        if (is_null($this->sqlLogger)) {
            return;
        }

        $this->sqlLogger->info($query);
    }
}
