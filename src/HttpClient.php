<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

namespace SevaCode\ClickHouseClient;


class HttpClient
{
	protected $url = 'http://localhost:8123/';
	protected $settings = [
		//'database' => 'default',
		//'max_memory_usage' => 100000000,
		//'max_rows_to_group_by' => 2000000,
	];

	/**
	 * readonly or write
	 * @var string
	 */
	protected $mode = Mode::READONLY;

	/**
	 * @see Format
	 * @var string
	 */
	protected $format = '';

	/**
	 * seconds
	 * @var float
	 */
	protected $last_query_latency;

	/**
	 * readonly or write
	 * @return string
	 */
	public function getMode()
	{
		return $this->mode;
	}

	/**
	 * readonly or write
	 * @param string $mode
	 */
	public function setMode($mode)
	{
		$this->mode = $mode;
	}

	/**
	 * @see Format
	 * @return string
	 */
	public function getFormat()
	{
		return $this->format;
	}

	/**
	 * @see Format
	 * @param string $format
	 */
	public function setFormat($format)
	{
		$this->format = $format;
	}

	public function setDatabase($database)
	{
		$this->settings['database'] = $database;
	}

	/**
	 * @return string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * @param string $url
	 */
	public function setUrl($url)
	{
		$this->url = $url;
	}

	/**
	 * seconds
	 * @return float
	 */
	public function getLastQueryLatency()
	{
		return $this->last_query_latency;
	}

	public function query($query)
	{
		$httpQueryValues = $this->settings;

		$streamOpts = array(
			'http'=>array(
				'method' => 'GET',
				'ignore_errors' => true,
			),
		);

		if ($query) {
			if (Mode::isReadOnly($this->mode)) {
				if ($this->format) {
					$query .= PHP_EOL . 'FORMAT ' . $this->format;
				}
				$httpQueryValues['query'] = $query;
			}
			else {
				$streamOpts['http']['method'] = 'POST';
				$streamOpts['http']['header'] = "Content-type: application/x-www-form-urlencoded";
				$streamOpts['http']['content'] = $query;
			}
		}

		$context = stream_context_create($streamOpts);
		$url = $this->url . '?' . http_build_query($httpQueryValues);

		// Выполняем запрос к ClickHouse
		$timeStart = microtime(true);
		$result = file_get_contents($url, false, $context);
		$this->last_query_latency = microtime(true) - $timeStart;

		if (!preg_match('~^HTTP/\d\.\d 200 ~i', $http_response_header[0])) {
			throw new ClickHouseException($result, 500);
		}

		return $result;
	}

	/**
	 * @param string $query
	 * @return array|null
	 */
	function getData($query)
	{
		$this->setFormat(Format::JSON);
		$result = json_decode($this->query($query), true);
		return $result['data'];
	}
}