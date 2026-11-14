<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Responses;

use SevaCode\ClickHouseClient\ChcFormat;

class ChcResponseFactory
{
    private ?string $format;

    public function __construct(?string $format = null)
    {
        $this->format = $format;
    }

    public function make(string $body = '', int $httpStatus = 0, ?string $query = null): ChcResponse
    {
        switch ($this->format) {
            case ChcFormat::JSON->value:
                return new ChcJsonResponse($body, $httpStatus, $query);

            default:
                return new ChcResponse($body);
        }
    }
}