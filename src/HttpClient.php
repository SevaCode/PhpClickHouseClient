<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

namespace SevaCode\ClickHouseClient;

/**
 * @deprecated use ChcClient
 */
class HttpClient extends ChcClient
{
    /**
     * readonly or write
     * @var string
     * @deprecated use $this->readOnly
     */
    protected $mode = Mode::READONLY;

    /**
     * @see ChcFormat
     * @var string
     */
    protected $format = '';

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
     * @see ChcFormat
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @see ChcFormat
     * @param string $format
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    public function query($query)
    {
        if ($this->isReadOnly) {
            return $this->getRaw($query, $this->format);
        } else {
            return $this->execute($query, $this->format)->getBody();
        }
    }

    /**
     * @param string $query
     * @return array|null
     */
    function getData($query)
    {
        return $this->getJsonData($query);
    }
}