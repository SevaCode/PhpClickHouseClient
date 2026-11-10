<?php

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;
use SevaCode\ClickHouseClient\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\ChcRequest;
use SevaCode\ClickHouseClient\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Responses\ChcResponse;
use SevaCode\ClickHouseClient\Transports\ChcHttpTransport;
use SevaCode\ClickHouseClient\Transports\ChcSymfonyCurlTransport;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/stream_functions.php';

    function same($expected, $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function caughtException(callable $run, string $class): ChcException
    {
        try {
            $run();
        } catch (ChcException $e) {
            same($class, get_class($e));
            return $e;
        }
        throw new \RuntimeException('Expected ' . $class);
    }

    function request(string $query = 'query-context', string $format = ChcFormat::JSON): ChcRequest
    {
        return (new ChcRequest())->setSettings([])->setQuery($query)->setReturnFormat($format);
    }

    function response(string $body, int $status = 200): ChcResponse
    {
        $transport = new ChcSymfonyCurlTransport(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));
        return $transport->response($transport->request(request()));
    }

    function poolFailure(callable $respond, string $class): ChcException
    {
        $sentQuery = null;
        HttpClient::$responseFactory = function ($method, $url) use (&$sentQuery, $respond) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
            $sentQuery = $params['query'] ?? null;
            return $respond();
        };
        $e = caughtException(
            fn() => (new ChcClient())->getJsonPool(['query-context'], function () {
                throw new \LogicException('Callback invoked on failed request');
            }),
            $class,
        );
        same(true, null !== $sentQuery);
        same($sentQuery, $e->getQuery());
        return $e;
    }

    $tests = [];
    $tests['server codes, symbolic fallbacks, and HTTP fallback'] = function () {
        $query = 'query-context';
        $previous = new \RuntimeException('original');
        $cases = [
            ['Code: 60. DB::Exception: Unknown table', 500, ChcException::class, 60],
            ["Code: 60. DB::Exception: Unknown table\nTIMEOUT_EXCEEDED", 500, ChcException::class, 60],
            ['Code: 241. DB::Exception: Memory limit exceeded', 500, ChcMemoryLimitExceededException::class, 241],
            ["partial JSON\n  Code: 159. DB::Exception: Timeout exceeded", 200, ChcTimeoutExceededException::class, 159],
            ['MEMORY_LIMIT_EXCEEDED', 500, ChcMemoryLimitExceededException::class, 241],
            ['TIMEOUT_EXCEEDED', 500, ChcTimeoutExceededException::class, 159],
            ['Service unavailable', 503, ChcException::class, 503],
            ['', 241, ChcMemoryLimitExceededException::class, 241],
            ['', 159, ChcTimeoutExceededException::class, 159],
        ];
        foreach ($cases as [$message, $inputCode, $class, $code]) {
            $e = caughtException(
                function () use ($message, $inputCode, $previous, $query) {
                    throw ChcException::create($message, $inputCode, $previous, $query);
                },
                $class,
            );
            same($message, $e->getMessage());
            same($code, $e->getCode());
            same($query, $e->getQuery());
            same($previous, $e->getPrevious());
        }
    };
    $tests['client timeout preserves context and does not fabricate server code'] = function () {
        $previous = new TimeoutException('TIMEOUT_EXCEEDED');
        $message = $previous->getMessage();
        $e = caughtException(
            function () use ($message, $previous) {
                throw ChcException::create($message, 0, $previous, 'query-context');
            },
            ChcTimeoutExceededException::class,
        );
        same($message, $e->getMessage());
        same(0, $e->getCode());
        same('query-context', $e->getQuery());
        same($previous, $e->getPrevious());
    };
    $tests['response failures preserve status, server code and context'] = function () {
        $memoryBody = '{"data":[{"x":1}]' . "\nCode: 241. DB::Exception: query failed";
        $timeoutBody = '{"data":[{"x":1}]' . "\nCode: 159. DB::Exception: query failed";
        $cases = [
            ['partial {', 200, ChcException::class, 'partial {', 200],
            ['0', 200, ChcException::class, '0', 200],
            ['', 200, ChcException::class, 'Empty response body', 200],
            [$memoryBody, 200, ChcMemoryLimitExceededException::class, $memoryBody, 241],
            [$timeoutBody, 200, ChcTimeoutExceededException::class, $timeoutBody, 159],
            ['Code: 60. DB::Exception: Unknown table', 500, ChcException::class, 'Code: 60. DB::Exception: Unknown table', 60],
            ['Proxy error', 502, ChcException::class, 'Proxy error', 502],
            ['', 503, ChcException::class, 'Unexpected response status: 503', 503],
        ];
        foreach ($cases as [$body, $status, $class, $message, $code]) {
            $e = caughtException(fn() => response($body, $status)->getResponse(), $class);
            same($message, $e->getMessage());
            same($code, $e->getCode());
            same('query-context', $e->getQuery());
        }
    };
    $tests['native stream failure preserves stream error context'] = function () {
        $transport = new ChcHttpTransport('http://unused.invalid');
        $e = caughtException(fn() => $transport->run(request()), ChcException::class);
        same(0, $e->getCode());
        same('query-context', $e->getQuery());
        same(error_get_last()['message'] ?? null, $e->getMessage());
    };
    $tests['missing headers use fallback rather than stale last error'] = function () {
        $GLOBALS['body_without_headers'] = true;
        try {
            $transport = new ChcHttpTransport('http://unused.invalid');
            $e = caughtException(fn() => $transport->run(request()), ChcException::class);
            same('Request failed', $e->getMessage());
            same(0, $e->getCode());
            same('query-context', $e->getQuery());
        } finally {
            unset($GLOBALS['body_without_headers']);
        }
    };
    $tests['pool valid JSON reaches callback'] = function () {
        HttpClient::$responseFactory = new MockResponse('{"data":[{"x":1}],"meta":[],"rows":1}', ['http_code' => 200]);
        $result = (new ChcClient())->getJsonPool(['query-context'], fn($data, $meta, $rows) => [$data, $meta, $rows]);
        $expected = [[['x' => 1]], [], 1];
        same([$expected], $result);
    };
    $tests['pool mid-stream error never reaches callback'] = function () {
        $body = '{"data":[' . "\nCode: 241. DB::Exception: Memory limit exceeded";
        $e = poolFailure(fn() => new MockResponse($body, ['http_code' => 200]), ChcMemoryLimitExceededException::class);
        same($body, $e->getMessage());
        same(241, $e->getCode());
        same(null, $e->getPrevious());
    };
    $tests['pool request-time timeout retains original exception and query'] = function () {
        $previous = new TimeoutException('Request timed out');
        $e = poolFailure(fn() => throw $previous, ChcTimeoutExceededException::class);
        same($previous->getMessage(), $e->getMessage());
        same(0, $e->getCode());
        same($previous, $e->getPrevious());
    };
    $tests['pool response-time timeout retains original exception and HTTP status'] = function () {
        $e = poolFailure(fn() => new MockResponse((function () {
            yield 'partial body';
            yield ''; // MockResponse represents an idle timeout with an empty chunk.
        })(), ['http_code' => 200]), ChcTimeoutExceededException::class);
        same(200, $e->getCode());
        same(true, $e->getPrevious() instanceof TimeoutExceptionInterface);
        same($e->getPrevious()->getMessage(), $e->getMessage());
    };

    $tests['symfony transport forwards method and settings'] = function () {
        $settings = ['database' => 'analytics', 'max_threads' => 2, 'user' => 'alice', 'password' => 'secret'];
        foreach ([[true, 'GET'], [false, 'POST']] as [$readOnly, $method]) {
            $client = new MockHttpClient(function ($actualMethod, $url) use ($method) {
                same($method, $actualMethod);
                parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
                same('analytics', $params['database'] ?? null);
                same('2', $params['max_threads'] ?? null);
                same(false, isset($params['user']));
                same(false, isset($params['password']));
                return new MockResponse();
            });
            (new ChcSymfonyCurlTransport($client))->setReadOnly($readOnly)
                ->request(request()->setSettings($settings));
        }
    };

    $tests['symfony ping stays GET even in read-write mode'] = function () {
        $client = new MockHttpClient(function ($method, $url) {
            same('GET', $method);
            same('/ping', parse_url($url, PHP_URL_PATH));
            return new MockResponse("Ok.\n", ['http_code' => 200]);
        });
        $transport = (new ChcSymfonyCurlTransport($client))->setReadOnly(false);
        $response = $transport->response($transport->request(request('', '')->setSettings(['database' => 'analytics'])));
        same("Ok.\n", $response->getResponse());
    };

    $failed = 0;
    foreach ($tests as $name => $run) {
        try {
            $run();
            echo "PASS $name\n";
        } catch (\Throwable $e) {
            ++$failed;
            echo "FAIL $name: " . $e->getMessage() . "\n";
        }
    }
    echo count($tests) . " tests, $failed failed\n";
    exit($failed ? 1 : 0);
