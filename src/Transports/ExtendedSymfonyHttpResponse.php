<?php

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcRequest;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ExtendedSymfonyHttpResponse implements ResponseInterface
{
    public function __construct(
        private readonly ResponseInterface $decoratedResponse,
        private readonly ChcRequest $request,
        private readonly string $query,
    )
    {
    }

    public function getStatusCode(): int
    {
        return $this->decoratedResponse->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->decoratedResponse->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->decoratedResponse->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->decoratedResponse->toArray($throw);
    }

    public function cancel(): void
    {
        $this->decoratedResponse->cancel();
    }

    public function getInfo(string $type = null): mixed
    {
        return $this->decoratedResponse->getInfo($type);
    }

    public function getRequest(): ChcRequest
    {
        return $this->request;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }
}
