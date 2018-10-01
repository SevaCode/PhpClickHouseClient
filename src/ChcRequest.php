<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

class ChcRequest
{
    /**
     * @var array
     */
    private $settings;
    /**
     * @var string
     */
    private $query;
    /**
     * @var string
     */
    private $returnFormat;

    /**
     * @return array
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * @param array $settings
     * @return $this
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param string $query
     * @return $this
     */
    public function setQuery($query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return string
     */
    public function getReturnFormat()
    {
        return $this->returnFormat;
    }

    /**
     * @param string $returnFormat
     * @return $this
     */
    public function setReturnFormat($returnFormat)
    {
        $this->returnFormat = $returnFormat;
        return $this;
    }
}