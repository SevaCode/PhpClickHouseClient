<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient\Responses;

class ChcResponse
{
    protected $format = '';
    /**
     * @var string
     */
    private $body;

    /**
     * ChcResponse constructor.
     * @param string $body
     */
    public function __construct($body = '')
    {
        $this->body = $body;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param string $body
     */
    public function setBody($body)
    {
        $this->body = $body;
    }

    public function getResponse()
    {
        return $this->getBody();
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }
}