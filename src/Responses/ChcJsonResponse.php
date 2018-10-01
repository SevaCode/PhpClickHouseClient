<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient\Responses;

use SevaCode\ClickHouseClient\ChcFormat;

class ChcJsonResponse extends ChcResponse
{
    public function __construct($body)
    {
        parent::__construct($body);
        $this->format = ChcFormat::JSON;
    }

    public function getResponse()
    {
        return json_decode($this->getBody(), true);
    }
}