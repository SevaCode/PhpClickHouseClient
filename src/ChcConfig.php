<?php
namespace SevaCode\ClickHouseClient;

class ChcConfig
{
    protected static $http = [
        'scheme' => 'http',
        'host' => 'localhost',
        'port' => '8123',
    ];

    protected static $settings = [
        //'database' => 'default',
        //'max_memory_usage' => 100000000,
        //'max_rows_to_group_by' => 2000000,
    ];

    /**
     * @param array $http
     */
    public static function setHttp($http)
    {
        self::$http = $http;
    }

    /**
     * @param string $scheme
     */
    public static function setHttpScheme($scheme)
    {
        self::$http['scheme'] = $scheme;
    }

    /**
     * @param string $host
     */
    public static function setHttpHost($host)
    {
        self::$http['host'] = $host;
    }

    /**
     * @param string $port
     */
    public static function setHttpPort($port)
    {
        self::$http['port'] = $port;
    }

    public static function getHttpUri()
    {
        $http = self::$http;
        return $http['scheme'] . '://' . $http['host'] . ':' . $http['port'] . '/';
    }

    /**
     * @param array $settings
     */
    public static function setSettings($settings)
    {
        self::$settings = $settings;
    }

    /**
     * @param string $name
     * @param string $value
     */
    public static function setSetting($name, $value)
    {
        self::$settings[$name] = $value;
    }
}