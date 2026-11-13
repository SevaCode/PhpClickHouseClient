<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Incremental JSONEachRow parser with detection of errors appended by the server mid-stream:
 * '{"exception": "Code: ..."}' line or a '__exception__' block (25.x+).
 * Rows before the error are yielded, then ChcException is thrown.
 *
 * @internal
 */
final class ChcJsonEachRowReader
{
    private const MAX_TAIL = 65536;

    private string $buffer = '';
    private ?string $suspect = null;
    private ?string $tail = null;
    private ?int $status = null;

    public function __construct(private readonly ResponseInterface $response, private readonly ?string $query = null)
    {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function push(string $chunk): \Generator
    {
        if (null === $this->status) {
            $this->status = $this->response->getStatusCode();
        }
        if (null !== $this->tail || 200 !== $this->status) {
            $this->appendTail($chunk);
            return;
        }

        $this->buffer .= $chunk;
        $start = 0;
        while (false !== ($end = strpos($this->buffer, "\n", $start))) {
            $line = substr($this->buffer, $start, $end - $start);
            $start = $end + 1;
            if ($this->failed()) {
                $this->appendTail($line . "\n");
                continue;
            }
            yield from $this->line($line);
        }
        $this->buffer = (string)substr($this->buffer, $start);
        if ($this->failed() && '' !== $this->buffer) {
            $this->appendTail($this->buffer);
            $this->buffer = '';
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     * @throws ChcException
     */
    public function finish(): \Generator
    {
        $status = $this->status ?? $this->response->getStatusCode();
        if (200 === $status && null === $this->tail && '' !== $this->buffer) {
            yield from $this->line($this->buffer);
        } elseif ('' !== $this->buffer) {
            $this->appendTail($this->buffer);
        }
        $this->buffer = '';

        $tail = ($this->suspect ?? '') . ($this->tail ?? '');
        if (200 !== $status || '' !== $tail) {
            $headers = $this->response->getInfo('response_headers') ?? [];
            ChcErrorDetector::throwIfError($tail, $status, $headers, $this->query);
            if (null !== $this->tail) {
                throw ChcException::create(message: 'Malformed JSONEachRow response: ' . substr($this->tail, 0, 1000), code: $status, query: $this->query);
            }
            // '{"exception": ...}' line that is not a server error — ordinary data
            yield from $this->flushSuspect();
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function line(string $line): \Generator
    {
        $row = '' === trim($line) ? null : json_decode($line, true);
        if (!is_array($row)) {
            $this->appendTail(($this->suspect ?? '') . $line . "\n");
            $this->suspect = null;
            return;
        }
        if (1 === count($row) && isset($row['exception']) && is_string($row['exception']) && str_starts_with($row['exception'], 'Code:')) {
            yield from $this->flushSuspect();
            $this->suspect = $line . "\n";
            return;
        }
        yield from $this->flushSuspect();
        yield $row;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function flushSuspect(): \Generator
    {
        if (null !== $this->suspect) {
            $row = json_decode($this->suspect, true);
            $this->suspect = null;
            yield $row;
        }
    }

    private function failed(): bool
    {
        return null !== $this->tail;
    }

    private function appendTail(string $data): void
    {
        $this->tail = substr(($this->tail ?? '') . $data, -self::MAX_TAIL);
    }
}
