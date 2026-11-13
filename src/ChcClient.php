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
    private bool $compression = false;
    private ?HttpClientInterface $httpClient = null;
    private ?int $poolConcurrency = null;

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
     * Route all requests through this Symfony HTTP client (connection reuse / keep-alive,
     * proxies, custom TLS, retries, test doubles). Without it, regular queries use the
     * built-in stream transport as before. The client's base_uri is set to the client URL.
     */
    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Opt-in gzip compression of responses (enable_http_compression=1) and of insert() bodies.
     */
    public function setCompression(bool $enabled): void
    {
        $this->compression = $enabled;
    }

    /**
     * Max number of simultaneous requests in getJsonPool(); null — all at once (as before).
     */
    public function setPoolConcurrency(?int $concurrency): void
    {
        if (null !== $concurrency && $concurrency < 1) {
            throw new \InvalidArgumentException('Pool concurrency must be >= 1');
        }
        $this->poolConcurrency = $concurrency;
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
        if (null !== $this->httpClient) {
            $transport = $this->symfonyTransport();
            $timeStart = microtime(true);
            try {
                return $transport->response($transport->request($request));
            } catch (TransportExceptionInterface $e) {
                throw ChcException::create(message: $e->getMessage(), previous: $e, query: $request->getQuery());
            } finally {
                $this->last_query_latency = microtime(true) - $timeStart;
            }
        }

        $transport = (new ChcHttpTransport($this->url))
            ->setReadOnly($this->isReadOnly)
            ->setReadOnlyOverPost($this->readOnlyOverPost)
            ->setCompression($this->compression)
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
        $transport = $this->symfonyTransport();
        $timeStart = microtime(true);

        $keys = array_keys($queries);
        $concurrency = $this->poolConcurrency ?? max(1, count($keys));
        $responses = [];
        $start = function (int $i) use ($keys, $queries, $formatPostfix, $transport, &$responses): void {
            if (!isset($keys[$i])) {
                return;
            }
            $request = $this->makeRequest($queries[$keys[$i]] . $formatPostfix, ChcFormat::JSON);
            try {
                $responses[$i] = $transport->request($request);
            } catch (TransportExceptionInterface $e) {
                throw ChcException::create(
                    message: $e->getMessage(),
                    previous: $e,
                    query: $request->getQuery(),
                );
            }
        };

        try {
            for ($i = 0; $i < $concurrency; $i++) {
                $start($i);
            }

            // Callbacks run in $queries order; at most $concurrency requests are in flight
            $result = [];
            foreach ($keys as $i => $key) {
                $response = $responses[$i];
                unset($responses[$i]);
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
                $start($i + $concurrency);
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
        } finally {
            foreach ($responses as $response) {
                $response->cancel();
            }
            $this->last_query_latency = microtime(true) - $timeStart;
        }

        return $result;
    }

    /**
     * Streams rows of a SELECT one by one (FORMAT JSONEachRow): memory does not depend on result size.
     *
     *     foreach ($client->iterate('SELECT * FROM hits WHERE dt = {dt:Date}', ['dt' => '2024-01-01']) as $row) { ... }
     *
     * Errors sent by the server in the middle of the stream are thrown as ChcException
     * after the rows received before the error.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return \Generator<int, array<string, mixed>>
     * @throws ChcException
     */
    public function iterate(string $sql, array $params = [], array $settings = []): \Generator
    {
        $request = $this->makeParamRequest($sql . $this->getQueryFormatPostfix(ChcFormat::JSONEachRow), $params, $settings, ChcFormat::JSONEachRow);
        $transport = $this->symfonyTransport();
        $timeStart = microtime(true);

        try {
            $response = $transport->request($request);
            if (200 !== $response->getStatusCode()) {
                $transport->response($response); // throws ChcException for non-200
            }
            $reader = new Transports\ChcJsonEachRowReader($response, $request->getQuery());
            foreach ($transport->stream($response) as $chunk) {
                yield from $reader->push($chunk);
            }
            yield from $reader->finish();
        } catch (TransportExceptionInterface $e) {
            throw ChcException::create(message: $e->getMessage(), previous: $e, query: $request->getQuery());
        } finally {
            if (isset($response)) {
                $response->cancel();
            }
            $this->last_query_latency = microtime(true) - $timeStart;
        }
    }

    /**
     * Streaming insert: INSERT INTO $table [($columns)] FORMAT JSONEachRow, the body is sent in chunks
     * while $rows (array or generator) is consumed. Rows are associative arrays; with $columns
     * given, list rows are matched to $columns by position.
     * Honours readOnly(): in read-only mode the server rejects the insert (READONLY).
     *
     * $table is an identifier written into SQL as is (e.g. 'db.events') — never pass user input.
     *
     * @param iterable<array<mixed>> $rows
     * @param list<string>|null $columns
     * @param array<string, mixed> $settings
     * @throws ChcException
     */
    public function insert(string $table, iterable $rows, ?array $columns = null, array $settings = []): ChcResponse
    {
        $sql = 'INSERT INTO ' . $table;
        if (!empty($columns)) {
            $sql .= ' (' . implode(', ', array_map(
                static fn(string $column) => '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $column) . '`',
                $columns,
            )) . ')';
        }
        $sql .= ' FORMAT ' . ChcFormat::JSONEachRow;
        $this->logSql($sql);

        $settings = array_merge($this->settings, $settings);
        if ($this->isReadOnly && !isset($settings['readonly'])) {
            $settings['readonly'] = 2;
        }
        $request = (new ChcRequest)->setSettings($settings)->setQuery($sql)->setReturnFormat('');

        $transport = $this->symfonyTransport();
        $timeStart = microtime(true);
        try {
            return $transport->response($transport->insert($request, Transports\ChcInsertBody::jsonEachRow($rows, $columns, $this->compression), $this->compression));
        } catch (TransportExceptionInterface $e) {
            throw ChcException::create(message: $e->getMessage(), previous: $e, query: $sql);
        } finally {
            $this->last_query_latency = microtime(true) - $timeStart;
        }
    }

    private function symfonyTransport(): ChcSymfonyCurlTransport
    {
        $transport = (new ChcSymfonyCurlTransport($this->httpClient ?? $this->createHttpClient()))
            ->setReadOnly($this->isReadOnly)
            ->setReadOnlyOverPost($this->readOnlyOverPost)
            ->setCompression($this->compression)
            ->setTimeout($this->timeout);
        if (null !== $this->httpClient) {
            $transport->setOptions(['base_uri' => $this->url]);
        }
        return $transport;
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
