<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

namespace SevaCode\ClickHouseClient;


class HttpClient
{
    protected $url = 'http://localhost:8123/';
    protected $settings = [
        //'database' => 'default',
        //'max_memory_usage' => 100000000,
        //'max_rows_to_group_by' => 2000000,
    ];

    protected $isReadOnly = true;

    /**
     * readonly or write
     * @var string
     * @deprecated use $this->readOnly
     */
    protected $mode = Mode::READONLY;

    /**
     * @see Format
     * @var string
     */
    protected $format = '';

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

    /**
     * readonly or write
     * @return string
     * @deprecated use $this->readOnly()
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * readonly or write
     * @param string $mode
     * @deprecated use $this->readOnly(true|false)
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
        $this->readOnly(Mode::isReadOnly($mode));
    }

    /**
     * @see Format
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @see Format
     * @param string $format
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    public function setDatabase($database)
    {
        $this->settings['database'] = $database;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
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

    public function query($query)
    {
        if ($this->isReadOnly && $this->format) {
            $query .= PHP_EOL . 'FORMAT ' . $this->format;
        }
        return $this->runRequest($this->makeRequest($query, $this->format))
            ->getBody();
    }

    private function makeRequest($query, $format = '')
    {
        return (new ChcRequest)
            ->setSettings($this->settings)
            ->setQuery($query)
            ->setReturnFormat($format);
    }

    private function runRequest(ChcRequest $request)
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
     * @return array|null
     */
    function getData($query)
    {
        return $this->runRequest($this->makeRequest($query, Format::JSON))
            ->getResponse()['data'];
    }

    /**
     * @return true
     */
    function ping()
    {
        return 'Ok.' === $this->runRequest($this->makeRequest(''))->getBody();
    }
}