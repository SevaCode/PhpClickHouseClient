<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Responses;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\ChcFormat;

class ChcJsonResponse extends ChcResponse
{
    private int $httpStatus;
    private ?string $query;

    public function __construct(string $body, int $httpStatus = 0, ?string $query = null)
    {
        parent::__construct($body);
        $this->format = ChcFormat::JSON->value;
        $this->httpStatus = $httpStatus;
        $this->query = $query;
    }

    public function getResponse(): array
    {
        $response = json_decode($this->getBody(), true);

        if (!is_array($response)) {
            throw ChcException::create(
                message: '' === $this->getBody() ? 'Empty response body' : $this->getBody(),
                code: $this->httpStatus,
                query: $this->query,
            );
        }

        if (!empty($response['exception'])) {
            throw ChcException::create(
                message: (string)$response['exception'],
                code: $this->httpStatus,
                query: $this->query,
            );
        }

        return $response;
    }
}