<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;


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
     * @throws ClickHouseException
     */
    protected function runRequest(ChcRequest $request)
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
     * @return string
     */
    function execute($query, $returnFormat = '')
    {
        return $this->runRequest($this->makeRequest($query, $returnFormat))
            ->getBody();
    }

    /**
     * @param string $query
     * @param string $returnFormat
     * @return string
     */
    function getRaw($query, $returnFormat = '')
    {
        return $this->execute($query, $returnFormat);
    }

    /**
     * @param string $query
     * @return array
     */
    function getJson($query)
    {
        return $this->runRequest($this->makeRequest($query, ChcFormat::JSON))
            ->getResponse();
    }

    /**
     * @param string $query
     * @return array|null
     */
    function getJsonData($query)
    {
        return $this->getJson($query)['data'];
    }

    /**
     * @return true
     */
    function ping()
    {
        return $this->runRequest($this->makeRequest(''))->getBody() === ('Ok.' . PHP_EOL);
    }
}