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
    /** @var string[] */
    private array $headerLines = [];

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

    /**
     * @return mixed
     */
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

    /**
     * @param string[] $headerLines raw response header lines
     * @internal set by transports
     */
    public function setHeaderLines(array $headerLines): void
    {
        $this->headerLines = $headerLines;
    }

    /**
     * Raw response header lines ('HTTP/1.1 200 OK', 'X-ClickHouse-Query-Id: ...').
     *
     * @return string[]
     */
    public function getHeaderLines(): array
    {
        return $this->headerLines;
    }

    /**
     * X-ClickHouse-Query-Id of the query.
     */
    public function getQueryId(): ?string
    {
        return \SevaCode\ClickHouseClient\Transports\ChcHeaders::queryId($this->headerLines);
    }

    /**
     * X-ClickHouse-Summary (read_rows, read_bytes, written_rows, elapsed_ns, ...).
     * Complete only with wait_end_of_query=1, otherwise it is the progress at the moment headers were sent.
     *
     * @return array<string, string>|null
     */
    public function getSummary(): ?array
    {
        return \SevaCode\ClickHouseClient\Transports\ChcHeaders::summary($this->headerLines);
    }
}
