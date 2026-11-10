<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient\Responses;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;

class ChcJsonResponse extends ChcResponse
{
    private int $httpStatus;
    private ?string $query;

    public function __construct($body, int $httpStatus = 0, ?string $query = null)
    {
        parent::__construct($body);
        $this->format = ChcFormat::JSON;
        $this->httpStatus = $httpStatus;
        $this->query = $query;
    }

    public function getResponse()
    {
        $response = json_decode($this->getBody(), true);

        if (!is_array($response)) {
            throw ChcException::create(
                message: '' === $this->getBody() ? 'Empty response body' : $this->getBody(),
                code: $this->httpStatus,
                query: $this->query,
            );
        }

        return $response;
    }
}