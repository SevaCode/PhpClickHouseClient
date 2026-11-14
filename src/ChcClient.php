<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

use Psr\Log\LoggerInterface;
use SevaCode\ClickHouseClient\Exceptions\ChcConnectionException;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcHeaders;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use SevaCode\ClickHouseClient\Transports\ExtendedSymfonyHttpResponse;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChcClient
{
    protected string $url = 'http://localhost:8123/';
    /** @var array<string, mixed> */
    protected array $settings = [
        //'database' => 'default',
        //'max_memory_usage' => 100000000,
        //'max_rows_to_group_by' => 2000000,
    ];

    protected int $readOnlyLevel = 2;

    /** Query latency in seconds. */
    protected ?float $last_query_latency = null;

    private ?LoggerInterface $sqlLogger = null;
    private ?float $timeout = null;
    private bool $compression = false;
    private ?HttpClientInterface $httpClient = null;
    private ?HttpClientInterface $defaultHttpClient = null;
    private ?int $poolConcurrency = null;
    private int $retries = 0;
    private float $retryDelay = 0.0;
    /** @var string[]|null */
    private ?array $lastHeaderLines = null;

    public function __construct(
        string $url = 'http://localhost:8123/',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->url = $url;
        $this->httpClient = $httpClient;
    }

    public function __clone()
    {
        $this->lastHeaderLines = null;
        $this->last_query_latency = null;
    }

    public function withUrl(string $url): static
    {
        $clone = clone $this;
        $clone->url = $url;
        $clone->defaultHttpClient = null;
        return $clone;
    }

    public function withDatabase(string $database): static
    {
        $clone = clone $this;
        $clone->settings['database'] = $database;
        return $clone;
    }

    public function withSettings(array $settings): static
    {
        $clone = clone $this;
        $clone->settings = array_merge($clone->settings, $settings);
        return $clone;
    }

    /**
     * Credentials are sent in X-ClickHouse-User / X-ClickHouse-Key headers,
     * never in the URL. Same as withSettings(['user' => ..., 'password' => ...]).
     */
    public function withCredentials(?string $user, ?string $password = null): static
    {
        $clone = clone $this;
        $clone->settings['user'] = $user;
        $clone->settings['password'] = $password;
        return $clone;
    }

    /**
     * HTTP read/idle timeout in seconds; null — transport default.
     * Server-side limit is a separate setting: withSettings(['max_execution_time' => N]).
     */
    public function withTimeout(?float $seconds): static
    {
        $clone = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }

    public function withReadOnly(int $level): static
    {
        if (!in_array($level, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Read-only level must be 0, 1 or 2');
        }
        $clone = clone $this;
        $clone->readOnlyLevel = $level;
        return $clone;
    }

    public function getReadOnly(): int
    {
        return $this->readOnlyLevel;
    }

    /**
     * Route all requests through this Symfony HTTP client (connection reuse / keep-alive,
     * proxies, custom TLS, retries, test doubles). The default is a shared HttpClient::create()
     * client. The client's base_uri is set to the client URL.
     */
    public function withHttpClient(?HttpClientInterface $httpClient): static
    {
        $clone = clone $this;
        $clone->httpClient = $httpClient;
        return $clone;
    }

    /**
     * Opt-in gzip compression of responses (enable_http_compression=1) and of insert() bodies.
     */
    public function withCompression(bool $enabled): static
    {
        $clone = clone $this;
        $clone->compression = $enabled;
        return $clone;
    }

    /**
     * Max number of simultaneous requests in getJsonPool(); null — all at once (as before).
     */
    public function withPoolConcurrency(?int $concurrency): static
    {
        if (null !== $concurrency && $concurrency < 1) {
            throw new \InvalidArgumentException('Pool concurrency must be >= 1');
        }
        $clone = clone $this;
        $clone->poolConcurrency = $concurrency;
        return $clone;
    }

    /**
     * Opt-in retries of read-only queries on connection errors and HTTP 502/503/504.
     * Writes (withReadOnly(0), insert()), timeouts and ClickHouse errors are never retried.
     */
    public function withRetries(int $count, float $delaySeconds = 0.5): static
    {
        if ($count < 0 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Retries and delay must be >= 0');
        }
        $clone = clone $this;
        $clone->retries = $count;
        $clone->retryDelay = $delaySeconds;
        return $clone;
    }

    /**
     * ClickHouse session (session_id) for temporary tables and SET; null — no session.
     * Queries of one session must not run concurrently (getJsonPool() fails with SESSION_IS_LOCKED).
     */
    public function withSession(?string $sessionId, ?int $timeoutSeconds = null): static
    {
        $clone = clone $this;
        unset($clone->settings['session_id'], $clone->settings['session_timeout']);
        if (null !== $sessionId) {
            $clone->settings['session_id'] = $sessionId;
            if (null !== $timeoutSeconds) {
                $clone->settings['session_timeout'] = $timeoutSeconds;
            }
        }
        return $clone;
    }

    public function withSqlLogger(?LoggerInterface $sqlLogger): static
    {
        $clone = clone $this;
        $clone->sqlLogger = $sqlLogger;
        return $clone;
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
     * Query latency in seconds; null before the first query.
     */
    public function getLastQueryLatency(): ?float
    {
        return $this->last_query_latency;
    }

    protected function makeRequest(string $query, ?string $format = null): ChcRequest
    {
        $this->logSql($query);

        return (new ChcRequest)
            ->setSettings($this->settings)
            ->setQuery($query)
            ->setReturnFormat($format);
    }

    /**
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
                $retryable = $this->readOnlyLevel > 0
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

    public function execute(string $query, ChcFormat|string|null $returnFormat = null): ChcResponse
    {
        return $this->runRequest($this->makeRequest($query, $this->normalizeFormat($returnFormat)));
    }

    public function getRaw(string $query, ChcFormat|string|null $returnFormat = null): string
    {
        return $this->select($query, $returnFormat)->getBody();
    }

    public function getJson(string $query): array
    {
        return $this->select($query, ChcFormat::JSON)->getResponse();
    }

    public function getJsonData(string $query): ?array
    {
        return $this->getJson($query)['data'];
    }

    /**
     * @throws ChcException when the server is unreachable
     */
    public function ping(): bool
    {
        return $this->getRaw('') === ('Ok.' . PHP_EOL);
    }

    private function select(string $query, ChcFormat|string|null $format): ChcResponse
    {
        $format = $this->normalizeFormat($format);
        return $this->runRequest($this->makeRequest($query . $this->getQueryFormatPostfix($format), $format));
    }

    private function normalizeFormat(ChcFormat|string|null $format): ?string
    {
        if ($format instanceof ChcFormat) {
            return $format->value;
        }
        return '' === $format ? null : $format;
    }

    private function getQueryFormatPostfix(?string $format): string
    {
        return null !== $format ? PHP_EOL . 'FORMAT ' . $format : '';
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
     * Honours read-only mode: use withReadOnly(0) to allow writes.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @throws ChcException
     */
    public function write(string $sql, array $params = [], array $settings = []): ChcResponse
    {
        return $this->runRequest($this->makeParamRequest($sql, $params, $settings, null));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function fetchJson(string $sql, array $params, array $settings): array
    {
        $sql .= $this->getQueryFormatPostfix(ChcFormat::JSON->value);
        return $this->runRequest($this->makeParamRequest($sql, $params, $settings, ChcFormat::JSON->value))->getResponse();
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     */
    private function makeParamRequest(string $sql, array $params, array $settings, ?string $format): ChcRequest
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
     */
    public function getJsonPool(array $queries, callable $callback): array
    {
        $formatPostfix = $this->getQueryFormatPostfix(ChcFormat::JSON->value);
        $transport = $this->symfonyTransport();
        $timeStart = microtime(true);

        $keys = array_keys($queries);
        $concurrency = $this->poolConcurrency ?? max(1, count($keys));
        $responses = [];
        $start = function (int $i) use ($keys, $queries, $formatPostfix, $transport, &$responses): void {
            if (!isset($keys[$i])) {
                return;
            }
            $request = $this->makeRequest($queries[$keys[$i]] . $formatPostfix, ChcFormat::JSON->value);
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
        $request = $this->makeParamRequest($sql . $this->getQueryFormatPostfix(ChcFormat::JSONEachRow->value), $params, $settings, ChcFormat::JSONEachRow->value);
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
     * Honours read-only mode: use withReadOnly(0) to allow inserts.
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
        $sql .= ' FORMAT ' . ChcFormat::JSONEachRow->value;
        $this->logSql($sql);

        $settings = array_merge($this->settings, $settings);
        if ($this->readOnlyLevel > 0 && !array_key_exists('readonly', $settings)) {
            $settings['readonly'] = $this->readOnlyLevel;
        }
        $request = (new ChcRequest)->setSettings($settings)->setQuery($sql)->setReturnFormat(null);

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
        $transport = (new ChcSymfonyCurlTransport($this->httpClient ?? ($this->defaultHttpClient ??= $this->createHttpClient())))
            ->setReadOnlyLevel($this->readOnlyLevel)
            ->setCompression($this->compression)
            ->setTimeout($this->timeout);
        if (null !== $this->httpClient) {
            $transport->setOptions(['base_uri' => $this->url]);
        }
        return $transport;
    }

    /**
     * Default HTTP client for all requests.
     */
    protected function createHttpClient(): HttpClientInterface
    {
        return SymfonyHttpClient::create([
            'base_uri' => $this->url,
        ]);
    }

    private function logSql(string $query): void
    {
        if (is_null($this->sqlLogger)) {
            return;
        }

        $this->sqlLogger->info($query);
    }
}
