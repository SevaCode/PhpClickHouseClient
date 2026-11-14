<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

use Psr\Log\LoggerInterface;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcHeaders;
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
    private int $readOnlyLevel = 2;

    /**
     * seconds
     * @var float|null
     */
    protected $last_query_latency;

    private ?LoggerInterface $sqlLogger = null;
    private ?float $timeout = null;
    private bool $readOnlyOverPost = false;
    private bool $compression = false;
    private ?HttpClientInterface $httpClient = null;
    private ?int $poolConcurrency = null;
    private int $retries = 0;
    private float $retryDelay = 0.0;
    /** @var string[]|null */
    private ?array $lastHeaderLines = null;

    public function __construct(string $url = 'http://localhost:8123/', ?HttpClientInterface $httpClient = null)
    {
        $this->setUrl($url);
        $this->setHttpClient($httpClient);
    }

    public function withUrl(string $url): static
    {
        return $this->withClone(function (self $clone) use ($url) {
            $clone->setUrl($url);
        });
    }

    public function withDatabase(string $database): static
    {
        return $this->withClone(function (self $clone) use ($database) {
            $clone->setDatabase($database);
        });
    }

    public function withSettings(array $settings): static
    {
        return $this->withClone(function (self $clone) use ($settings) {
            $clone->addSettings($settings);
        });
    }

    public function withCredentials(?string $user, ?string $password = null): static
    {
        return $this->withClone(function (self $clone) use ($user, $password) {
            $clone->setCredentials($user, $password);
        });
    }

    public function withTimeout(?float $seconds): static
    {
        return $this->withClone(function (self $clone) use ($seconds) {
            $clone->setTimeout($seconds);
        });
    }

    /**
     * Read-only level 0 allows writes; 1 and 2 use the existing read-only behavior in 1.4.
     * Both 1 and 2 send GET requests, so ClickHouse applies the stricter profile level.
     * In 2.0, level 1 sends readonly=1 over POST.
     */
    public function withReadOnly(int $level): static
    {
        if (!in_array($level, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Read-only level must be 0, 1 or 2');
        }

        return $this->withClone(function (self $clone) use ($level) {
            $clone->readOnly(0 !== $level);
            $clone->readOnlyLevel = $level;
        });
    }

    public function getReadOnly(): int
    {
        return $this->isReadOnly ? $this->readOnlyLevel : 0;
    }

    public function withCompression(bool $enabled): static
    {
        return $this->withClone(function (self $clone) use ($enabled) {
            $clone->setCompression($enabled);
        });
    }

    public function withHttpClient(?HttpClientInterface $httpClient): static
    {
        return $this->withClone(function (self $clone) use ($httpClient) {
            $clone->setHttpClient($httpClient);
        });
    }

    public function withPoolConcurrency(?int $concurrency): static
    {
        return $this->withClone(function (self $clone) use ($concurrency) {
            $clone->setPoolConcurrency($concurrency);
        });
    }

    public function withRetries(int $count, float $delaySeconds = 0.5): static
    {
        return $this->withClone(function (self $clone) use ($count, $delaySeconds) {
            $clone->setRetries($count, $delaySeconds);
        });
    }

    public function withSession(?string $sessionId, ?int $timeoutSeconds = null): static
    {
        return $this->withClone(function (self $clone) use ($sessionId, $timeoutSeconds) {
            $clone->setSessionId($sessionId, $timeoutSeconds);
        });
    }

    public function withSqlLogger(?LoggerInterface $sqlLogger): static
    {
        return $this->withClone(function (self $clone) use ($sqlLogger) {
            $clone->setSqlLogger($sqlLogger);
        });
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withReadOnly()/getReadOnly() instead
     * @param boolean|null $isReadOnly
     * @return bool
     */
    public function readOnly($isReadOnly = null)
    {
        $old = $this->isReadOnly;

        if (!is_null($isReadOnly)) {
            $this->isReadOnly = (boolean)$isReadOnly;
            $this->readOnlyLevel = $this->isReadOnly ? 2 : 0;
        }

        return $old;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withDatabase() instead
     */
    public function setDatabase($database)
    {
        $this->settings['database'] = $database;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withSettings() instead
     * @param array $settings
     */
    public function addSettings($settings)
    {
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withCredentials() instead
     *
     * Credentials are sent in X-ClickHouse-User / X-ClickHouse-Key headers,
     * never in the URL. Same as addSettings(['user' => ..., 'password' => ...]).
     */
    public function setCredentials(?string $user, ?string $password = null): void
    {
        $this->settings['user'] = $user;
        $this->settings['password'] = $password;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withTimeout() instead
     *
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
     * @deprecated since 1.4: in 2.0 reads always use POST + readonly=2 and this method is removed.
     */
    public function setReadOnlyOverPost(bool $enabled): void
    {
        $this->readOnlyOverPost = $enabled;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withHttpClient() instead
     *
     * Route all requests through this Symfony HTTP client (connection reuse / keep-alive,
     * proxies, custom TLS, retries, test doubles). Without it, regular queries use the
     * built-in stream transport as before. The client's base_uri is set to the client URL.
     */
    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withCompression() instead
     *
     * Opt-in gzip compression of responses (enable_http_compression=1) and of insert() bodies.
     */
    public function setCompression(bool $enabled): void
    {
        $this->compression = $enabled;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withPoolConcurrency() instead
     *
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
     * @deprecated since 1.4, removed in 2.0, use withRetries() instead
     *
     * Opt-in retries of read-only queries on connection errors and HTTP 502/503/504.
     * Writes (readOnly(false), insert()), timeouts and ClickHouse errors are never retried.
     */
    public function setRetries(int $count, float $delaySeconds = 0.5): void
    {
        if ($count < 0 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Retries and delay must be >= 0');
        }
        $this->retries = $count;
        $this->retryDelay = $delaySeconds;
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withSession() instead
     *
     * ClickHouse session (session_id) for temporary tables and SET; null — no session.
     * Queries of one session must not run concurrently (getJsonPool() fails with SESSION_IS_LOCKED).
     */
    public function setSessionId(?string $sessionId, ?int $timeoutSeconds = null): void
    {
        unset($this->settings['session_id'], $this->settings['session_timeout']);
        if (null !== $sessionId) {
            $this->settings['session_id'] = $sessionId;
            if (null !== $timeoutSeconds) {
                $this->settings['session_timeout'] = $timeoutSeconds;
            }
        }
    }

    /**
     * X-ClickHouse-Query-Id of the last successful query (execute, get*, fetch*, write, iterate, insert).
     */
    public function getLastQueryId(): ?string
    {
        return null === $this->lastHeaderLines ? null : ChcHeaders::queryId($this->lastHeaderLines);
    }

    /**
     * X-ClickHouse-Summary of the last successful query, see ChcResponse::getSummary().
     *
     * @return array<string, string>|null
     */
    public function getLastQuerySummary(): ?array
    {
        return null === $this->lastHeaderLines ? null : ChcHeaders::summary($this->lastHeaderLines);
    }

    /**
     * ping() without exceptions: false if the server is unreachable or not healthy.
     */
    public function isAlive(): bool
    {
        try {
            return $this->ping();
        } catch (ChcException) {
            return false;
        }
    }

    /**
     * @deprecated since 1.4, removed in 2.0, use withUrl() instead
     * @param string $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * seconds
     * @return float|null
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
        $this->lastHeaderLines = null;
        $response = $this->runWithRetries(fn() => $this->sendRequest($request));
        $this->lastHeaderLines = $response->getHeaderLines();
        return $response;
    }

    private function sendRequest(ChcRequest $request): ChcResponse
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
     * @template T
     * @param callable(): T $send
     * @return T
     */
    private function runWithRetries(callable $send): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $send();
            } catch (ChcException $e) {
                $retryable = $this->isReadOnly
                    && ($e instanceof ChcConnectionException || in_array($e->getHttpStatus(), [502, 503, 504], true));
                if (!$retryable || $attempt >= $this->retries) {
                    throw $e;
                }
                if ($this->retryDelay > 0) {
                    usleep((int)($this->retryDelay * 1000000));
                }
            }
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
     * In 2.0 returns false instead of throwing; use isAlive() for a non-throwing check now.
     *
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
     * In 2.0 replaced by pool() returning result objects.
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
            $this->lastHeaderLines = null;
            $response = $this->runWithRetries(function () use ($transport, $request) {
                try {
                    $response = $transport->request($request);
                    if (200 !== $response->getStatusCode()) {
                        $transport->response($response); // throws ChcException for non-200
                    }
                    return $response;
                } catch (TransportExceptionInterface $e) {
                    throw ChcException::create(message: $e->getMessage(), previous: $e, query: $request->getQuery());
                }
            });
            $reader = new Transports\ChcJsonEachRowReader($response, $request->getQuery());
            foreach ($transport->stream($response) as $chunk) {
                yield from $reader->push($chunk);
            }
            yield from $reader->finish();
            $this->lastHeaderLines = $response->getInfo('response_headers') ?? [];
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
            $this->lastHeaderLines = null;
            $response = $transport->response($transport->insert($request, Transports\ChcInsertBody::jsonEachRow($rows, $columns, $this->compression), $this->compression));
            $this->lastHeaderLines = $response->getHeaderLines();
            return $response;
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

    /**
     * @deprecated since 1.4, removed in 2.0, use withSqlLogger() instead
     */
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

    /**
     * @param callable(self): void $configure
     */
    private function withClone(callable $configure): static
    {
        $clone = clone $this;
        $clone->lastHeaderLines = null;
        $clone->last_query_latency = null;
        $configure($clone);
        return $clone;
    }
}
