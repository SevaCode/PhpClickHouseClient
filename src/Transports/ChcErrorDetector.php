<?php
/**
 * Author: SevaCode
 * CreatedAt: 13.11.2026
 */

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcException;

/**
 * Единая обработка ошибочных ответов ClickHouse для всех транспортов.
 *
 * Ошибка может прийти двумя способами:
 *  - не-200 HTTP-статусом (ошибка до отправки данных);
 *  - HTTP 200 + exception, дописанным в самый конец тела посреди стриминга
 *    (например, MEMORY_LIMIT_EXCEEDED). Способ встраивания зависит от семейства
 *    выходного формата: поле "exception" в JSON-семействе, <exception> в XML,
 *    plain-text 'Code: N. DB::Exception: ...' в текстовых форматах.
 * Чтобы не сканировать многомегабайтные ответы, проверяем только последние 4 КБ.
 * ClickHouse 25.x+ при этом выставляет заголовок X-ClickHouse-Exception-Tag
 * и оборачивает ошибку в блок '__exception__\r\n<tag>\r\n<message>\r\n<len> <tag>\r\n__exception__'
 * независимо от формата — такой блок ищем в первую очередь.
 * Текст самой ошибки по HTTP доступен только в теле: X-ClickHouse-Exception-Code
 * несёт лишь числовой код, а при wait_end_of_query=1 тело и вовсе пустое.
 */
class ChcErrorDetector
{
    private const TAIL_SIZE = 4096;
    private const CHECKS_ALL = ['json', 'plain', 'xml'];

    /**
     * Бросает ChcException при ошибочном ответе, иначе ничего не делает.
     *
     * @param string[] $headerLines сырые строки заголовков ответа
     *        ('HTTP/1.1 200 OK', 'X-ClickHouse-Format: JSON', ...),
     *        как $http_response_header у file_get_contents
     *        или getInfo('response_headers') у Symfony HttpClient.
     */
    public static function throwIfError(string $body, int $httpStatus, array $headerLines, ?string $query): void
    {
        if (200 !== $httpStatus) {
            $statusLine = self::statusLine($headerLines);
            if (empty($body)) {
                $body = 'Unexpected response status: ' . ($statusLine ?: (string)$httpStatus);
            }
            throw ChcException::create(
                message: $body,
                code: $httpStatus,
                query: $query,
            );
        }

        $tag = self::exceptionTag($headerLines);
        if (null !== $tag && ($exception = self::detectTagged($body, $tag, $httpStatus, $query))) {
            throw $exception;
        }

        if ($exception = self::detect($body, $httpStatus, self::checksFor($headerLines), $query)) {
            throw $exception;
        }
    }

    /**
     * Формат ответа из заголовка X-ClickHouse-Format — эхо реального формата
     * от сервера, надёжнее клиентского returnFormat (FORMAT можно вписать
     * в query вручную, execute() не дописывает FORMAT-клаузу).
     * null — заголовка нет (DDL/ping/старый сервер).
     *
     * @param string[] $headerLines
     */
    public static function responseFormat(array $headerLines): ?string
    {
        foreach ($headerLines as $line) {
            if (preg_match('{^X-ClickHouse-Format:\s*(\S+)}i', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Значение X-ClickHouse-Exception-Tag (ClickHouse 25.x+), null — заголовка нет.
     *
     * @param string[] $headerLines
     */
    private static function exceptionTag(array $headerLines): ?string
    {
        foreach ($headerLines as $line) {
            if (preg_match('{^X-ClickHouse-Exception-Tag:\s*(\S+)}i', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Блок '__exception__\r\n<tag>\r\n<message>\r\n<len> <tag>\r\n__exception__' в конце тела.
     */
    public static function detectTagged(string $body, string $tag, int $httpStatus, ?string $query): ?ChcException
    {
        $tail = strlen($body) > self::TAIL_SIZE ? substr($body, -self::TAIL_SIZE) : $body;
        $quotedTag = preg_quote($tag, '/');
        if (preg_match('/__exception__\r?\n' . $quotedTag . '\r?\n([\s\S]*?)\r?\n\d+ ' . $quotedTag . '\r?\n__exception__\s*$/', $tail, $m)) {
            return ChcException::create(
                message: rtrim($m[1]),
                code: $httpStatus,
                query: $query,
            );
        }
        return null;
    }

    /**
     * Последняя строка 'HTTP/x.y NNN Reason' — финальный статус-ответ
     * (при редиректах и 1xx informational-ответов таких строк может быть несколько).
     *
     * @param string[] $headerLines
     */
    private static function statusLine(array $headerLines): ?string
    {
        $statusLine = null;
        foreach ($headerLines as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $statusLine = $line;
            }
        }
        return $statusLine;
    }

    /**
     * MIME-тип из Content-Type без параметров, в нижнем регистре.
     *
     * @param string[] $headerLines
     */
    private static function contentType(array $headerLines): ?string
    {
        foreach ($headerLines as $line) {
            if (preg_match('{^Content-Type:\s*([^;\s]+)}i', $line, $m)) {
                return strtolower($m[1]);
            }
        }
        return null;
    }

    /**
     * Какие сигнатуры ошибки искать в теле — по X-ClickHouse-Format,
     * при его отсутствии по семейству Content-Type, иначе все.
     *
     * @param string[] $headerLines
     * @return string[] подмножество ['json', 'plain', 'xml']
     */
    private static function checksFor(array $headerLines): array
    {
        $format = self::responseFormat($headerLines);
        if (null !== $format) {
            return match ($format) {
                // Эти форматы встраивают exception как поле/строку "exception";
                // старые серверы (23.x) дописывают plain-text сразу после данных
                'JSON', 'JSONCompact', 'JSONStrings', 'JSONEachRow' => ['json', 'plain'],
                'XML' => ['xml'],
                // JSONCompactEachRow и будущие JSON*-форматы — все сигнатуры,
                // способ встраивания для них не проверен
                default => str_starts_with($format, 'JSON') ? self::CHECKS_ALL : ['plain'],
            };
        }

        // X-ClickHouse-Format нет — семейство ответа прикидываем по Content-Type
        $contentType = self::contentType($headerLines);
        if (null === $contentType) {
            return self::CHECKS_ALL;
        }
        // application/json, application/x-ndjson и прочие *json*
        if (str_contains($contentType, 'json')) {
            return ['json', 'plain'];
        }
        if (str_contains($contentType, 'xml')) {
            return ['xml'];
        }
        if (in_array($contentType, ['text/tab-separated-values', 'text/csv'], true)) {
            return ['plain'];
        }
        // text/plain и прочие неоднозначные — все сигнатуры
        return self::CHECKS_ALL;
    }

    /**
     * Ищет сигнатуру ClickHouse-исключения в последних TAIL_SIZE байтах тела.
     *
     * @param string[] $checks подмножество ['json', 'plain', 'xml'] — см. checksFor()
     * @return ChcException|null null — если в хвосте тела нет сигнатуры ошибки ClickHouse
     */
    public static function detect(string $body, int $httpStatus, array $checks, ?string $query): ?ChcException
    {
        $tail = strlen($body) > self::TAIL_SIZE ? substr($body, -self::TAIL_SIZE) : $body;

        foreach ($checks as $check) {
            switch ($check) {
                // JSON-семейство: "exception" перед закрывающей скобкой
                // (JSON/JSONCompact/JSONStrings) или отдельной строкой (JSONEachRow)
                case 'json':
                    if (str_contains($tail, '"exception"') &&
                        preg_match('/"exception"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*}\s*$/s', $tail, $m)) {
                        return ChcException::create(
                            message: json_decode('"' . $m[1] . '"') ?? $m[1],
                            code: $httpStatus,
                            query: $query,
                        );
                    }
                    break;

                // Текстовые форматы: plain-text 'Code: N. DB::Exception: ...'
                // в конце тела. Сигнатура '(EXCEPTION_NAME) (version X.Y.Z)'
                // в конце ответа специфична для ClickHouse.
                // Проверяем безусловно даже при известном формате: returnFormat
                // может не совпадать с реальным FORMAT в query.
                case 'plain':
                    if (preg_match('/(Code:\s*\d+\.[\s\S]*\(version[\s\S]*\))\s*$/', $tail, $m)) {
                        return ChcException::create(
                            message: $m[1],
                            code: $httpStatus,
                            query: $query,
                        );
                    }
                    break;

                // XML: <exception>Code: N. DB::Exception: ...</exception> перед </result>
                case 'xml':
                    if (preg_match('/<exception>(Code:\s*\d+\.[\s\S]*?)<\/exception>/', $tail, $m)) {
                        return ChcException::create(
                            message: html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                            code: $httpStatus,
                            query: $query,
                        );
                    }
                    break;
            }
        }

        return null;
    }
}
