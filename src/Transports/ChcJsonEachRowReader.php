<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Incremental JSONEachRow parser with detection of errors appended by the server mid-stream.
 *
 * Why it is needed: ClickHouse sends HTTP 200 and starts streaming rows before the query is
 * finished. If the query fails later, the status can no longer be changed, so the server writes
 * the error into the body after the rows already sent:
 *   - 24.x: a last line '{"exception": "Code: 395. DB::Exception: ..."}';
 *   - 25.x+: a '__exception__ ... __exception__' text block (plus X-ClickHouse-Exception-Tag header).
 * Rows before the error are yielded, then ChcException is thrown.
 *
 * Usage (see ChcClient::iterate()):
 *
 *     $reader = new ChcJsonEachRowReader($response, $query);
 *     foreach ($chunks as $chunk) { yield from $reader->push($chunk); } // rows as they arrive
 *     yield from $reader->finish();                                      // last row or exception
 *
 * The reader is a small state machine:
 *   1. Normal: every complete line is a JSON object → yielded as a row.
 *   2. Suspect: a line that looks like a 24.x error ('{"exception": "Code: ..."}') is held back,
 *      because a real row with a single column named "exception" looks the same. If any other line
 *      follows, it was data and is yielded; if the stream ends on it, it is checked as an error.
 *   3. Failed: the first line that is not a JSON object (error text, '__exception__' block,
 *      garbage) means the data part is over. Everything from there on is collected into $tail
 *      (last MAX_TAIL bytes) and handed to ChcErrorDetector in finish().
 *
 * @internal
 */
final class ChcJsonEachRowReader
{
    /** The error message is at the end of the body, so only the last 64 KB after a failure are kept. */
    private const MAX_TAIL = 65536;

    /** Incomplete last line of the previous chunk, waiting for its "\n" in the next chunk. */
    private string $buffer = '';
    /** Held-back '{"exception": "Code: ..."}' line (state 2), null if none. */
    private ?string $suspect = null;
    /** Non-row data collected after a failure (state 3); null while the stream is still healthy. */
    private ?string $tail = null;
    /** HTTP status, read once on the first chunk (headers are available by then). */
    private ?int $status = null;

    public function __construct(private readonly ResponseInterface $response, private readonly ?string $query = null)
    {
    }

    /**
     * Feeds one network chunk and yields the rows completed by it.
     * A chunk can end in the middle of a line: that part stays in $buffer until the next chunk.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function push(string $chunk): \Generator
    {
        if (null === $this->status) {
            $this->status = $this->response->getStatusCode();
        }
        // Non-200 body is an error message, and after a failure nothing is a row any more:
        // just collect the data for the error detector.
        if (null !== $this->tail || 200 !== $this->status) {
            $this->appendTail($chunk);
            return;
        }

        $this->buffer .= $chunk;
        $start = 0;
        // Process complete lines only; $start is the offset of the next unprocessed line.
        while (false !== ($end = strpos($this->buffer, "\n", $start))) {
            $line = substr($this->buffer, $start, $end - $start);
            $start = $end + 1;
            // line() may have switched to the failed state on a previous line of this chunk.
            if ($this->failed()) {
                $this->appendTail($line . "\n");
                continue;
            }
            yield from $this->line($line);
        }
        // Keep the incomplete rest for the next chunk (or move it to the tail after a failure).
        $this->buffer = (string)substr($this->buffer, $start);
        if ($this->failed() && '' !== $this->buffer) {
            $this->appendTail($this->buffer);
            $this->buffer = '';
        }
    }

    /**
     * Called after the last chunk: yields the remaining row(s) or throws the server error.
     *
     * @return \Generator<int, array<string, mixed>>
     * @throws ChcException
     */
    public function finish(): \Generator
    {
        // Empty body: push() was never called, so the status has not been read yet.
        $status = $this->status ?? $this->response->getStatusCode();
        // The body may end without "\n": the rest of the buffer is the last line.
        if (200 === $status && null === $this->tail && '' !== $this->buffer) {
            yield from $this->line($this->buffer);
        } elseif ('' !== $this->buffer) {
            $this->appendTail($this->buffer);
        }
        $this->buffer = '';

        $tail = ($this->suspect ?? '') . ($this->tail ?? '');
        if (200 !== $status || '' !== $tail) {
            // Throws if the status or the tail contains a ClickHouse error (any of the formats above).
            $headers = $this->response->getInfo('response_headers') ?? [];
            ChcErrorDetector::throwIfError($tail, $status, $headers, $this->query);
            // Non-JSON data without a recognisable error: the response is broken, do not hide it.
            if (null !== $this->tail) {
                throw ChcException::create(message: 'Malformed JSONEachRow response: ' . substr($this->tail, 0, 1000), code: $status, query: $this->query);
            }
            // Only the suspect line was left and it is not a server error — ordinary data
            yield from $this->flushSuspect();
        }
    }

    /**
     * Handles one complete line: yields it as a row, holds it back as a suspect,
     * or switches the reader to the failed state.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function line(string $line): \Generator
    {
        // JSONEachRow never contains empty lines, so an empty line also means "not data".
        $row = '' === trim($line) ? null : json_decode($line, true);
        if (!is_array($row)) {
            // Failed state. A held-back suspect line goes to the tail too: it is part of the error context.
            $this->appendTail(($this->suspect ?? '') . $line . "\n");
            $this->suspect = null;
            return;
        }
        if (1 === count($row) && isset($row['exception']) && is_string($row['exception']) && str_starts_with($row['exception'], 'Code:')) {
            // Possible 24.x error line: release the previous suspect (a row followed it, so it was data)
            // and hold this one back until we know whether the stream ends here.
            yield from $this->flushSuspect();
            $this->suspect = $line . "\n";
            return;
        }
        // An ordinary row: a suspect before it was data, yield it first to keep the order.
        yield from $this->flushSuspect();
        yield $row;
    }

    /**
     * Yields the held-back suspect line as an ordinary row.
     *
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

    /**
     * Separate method instead of reading $this->tail inline: PHPStan does not see that
     * `yield from $this->line()` can change the property and would report the check as always false.
     */
    private function failed(): bool
    {
        return null !== $this->tail;
    }

    private function appendTail(string $data): void
    {
        $this->tail = substr(($this->tail ?? '') . $data, -self::MAX_TAIL);
    }
}
