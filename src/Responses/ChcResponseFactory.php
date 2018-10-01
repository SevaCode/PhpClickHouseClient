<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient\Responses;

use SevaCode\ClickHouseClient\Format;

class ChcResponseFactory
{
    /**
     * @var string
     */
    private $format;

    /**
     * ChcResponseFactory constructor.
     * @param string $format
     */
    public function __construct($format = '')
    {
        $this->format = $format;
    }

    /**
     * @param string $body
     * @return ChcResponse
     */
    public function make($body = '')
    {
        switch ($this->format) {
            case Format::JSON:
                return new ChcJsonResponse($body);

            default:
                return new ChcResponse($body);
        }
    }
}