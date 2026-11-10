<?php

namespace Symfony\Component\HttpClient;

use Symfony\Contracts\HttpClient\HttpClientInterface;

// Stand-in for the standalone test runner only: this file must be loaded
// before the real Symfony\Component\HttpClient\HttpClient class is autoloaded.
class HttpClient
{
    public static $responseFactory;

    public static function create(array $defaultOptions = []): HttpClientInterface
    {
        return new \Symfony\Component\HttpClient\MockHttpClient(self::$responseFactory);
    }
}
