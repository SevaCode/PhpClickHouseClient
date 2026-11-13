<?php

namespace SevaCode\ClickHouseClient\Tests\Unit;

use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcMemoryLimitExceededException;
use SevaCode\ClickHouseClient\Exceptions\ChcTimeoutExceededException;
use SevaCode\ClickHouseClient\Tests\TestCase;
use SevaCode\ClickHouseClient\Transports\ChcErrorDetector;

class ChcErrorDetectorTest extends TestCase
{
    public function testFindsMidStreamExceptionsInBodyTail(): void
    {
        // JSON: поле "exception" перед закрывающей скобкой
        $e = ChcErrorDetector::detect(
            '{"data":[{"x":1}],"exception":"Code: 241. DB::Exception: Memory limit (MEMORY_LIMIT_EXCEEDED)"}',
            200, ['json'], 'q',
        );
        $this->assertInstanceOf(ChcMemoryLimitExceededException::class, $e);
        $this->assertSame(241, $e->getCode());
        $this->assertSame('q', $e->getQuery());

        // JSONEachRow: exception отдельной строкой в конце — тот же json-паттерн
        $e = ChcErrorDetector::detect(
            "{\"a\":1}\n{\"exception\": \"Code: 159. DB::Exception: Timeout (TIMEOUT_EXCEEDED)\"}\n",
            200, ['json'], 'q',
        );
        $this->assertInstanceOf(ChcTimeoutExceededException::class, $e);

        // Текстовый формат: plain-text trailer в конце тела
        $e = ChcErrorDetector::detect(
            "1\t2\n3\t4\nCode: 395. DB::Exception: boom (FOO) (version 24.1.4.20 (official build))\n",
            200, ['plain'], 'q',
        );
        $this->assertInstanceOf(ChcException::class, $e);
        $this->assertSame(395, $e->getCode());
        $this->assertNotInstanceOf(ChcMemoryLimitExceededException::class, $e);

        // Тело больше 4 КБ: exception в последних 4 КБ по-прежнему находится
        $e = ChcErrorDetector::detect(
            str_repeat("1\t2\n", 2000) . "Code: 395. DB::Exception: boom (FOO) (version 1.0)\n",
            200, ['plain'], 'q',
        );
        $this->assertSame(395, $e->getCode());

        // XML: <exception>...</exception> перед </result>
        $e = ChcErrorDetector::detect(
            '<result><row>1</row><exception>Code: 395. DB::Exception: boom (FOO) (version 1.0)</exception></result>',
            200, ['xml'], 'q',
        );
        $this->assertSame(395, $e->getCode());
        $this->assertSame('Code: 395. DB::Exception: boom (FOO) (version 1.0)', $e->getMessage());

        // Чужая сигнатура не проверяется: plain-trailer при checks=['json'] — null
        $this->assertNull(ChcErrorDetector::detect(
            "1\t2\nCode: 395. DB::Exception: boom (FOO) (version 1.0)",
            200, ['json'], 'q',
        ));

        // Чистые ответы — null при любых checks
        foreach ([['json'], ['plain'], ['xml'], ['json', 'plain', 'xml']] as $checks) {
            $this->assertNull(ChcErrorDetector::detect('{"data":[{"x":1}],"rows":1}', 200, $checks, 'q'));
            $this->assertNull(ChcErrorDetector::detect("1\t2\n3\t4\n", 200, $checks, 'q'));
        }

        // Строка данных, похожая на исключение, но JSON заканчивается на '}' — не срабатывает
        $this->assertNull(ChcErrorDetector::detect(
            '{"data":[{"v":"Code: 1. DB::Exception: fake (FOO) (version 1.0)"}],"rows":1}',
            200, ['json', 'plain'], 'q',
        ));
    }

    public function testPicksSignatureByResponseHeaders(): void
    {
        $jsonError = '{"data":[],"exception":"Code: 241. DB::Exception: mem (MEMORY_LIMIT_EXCEEDED)"}';
        $tsvError = "1\t2\nCode: 395. DB::Exception: boom (FOO) (version 1.0)";
        $xmlError = '<result><exception>Code: 395. DB::Exception: boom</exception></result>';
        $ok = 'HTTP/1.1 200 OK';

        // X-ClickHouse-Format сужает выбор сигнатуры
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($tsvError, 200, [$ok, 'X-ClickHouse-Format: TabSeparated'], 'q'), ChcException::class);
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($jsonError, 200, [$ok, 'X-ClickHouse-Format: JSON'], 'q'), ChcMemoryLimitExceededException::class);
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($xmlError, 200, [$ok, 'X-ClickHouse-Format: XML'], 'q'), ChcException::class);
        // ...и отсекает чужие: JSON-ошибка при формате TabSeparated не детектится
        ChcErrorDetector::throwIfError($jsonError, 200, [$ok, 'X-ClickHouse-Format: TabSeparated'], 'q');

        // Без X-ClickHouse-Format семейство определяется по Content-Type
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($tsvError, 200, [$ok, 'Content-Type: text/tab-separated-values; charset=UTF-8'], 'q'), ChcException::class);
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($jsonError, 200, [$ok, 'Content-Type: application/json'], 'q'), ChcMemoryLimitExceededException::class);
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($xmlError, 200, [$ok, 'Content-Type: application/xml'], 'q'), ChcException::class);

        // Ни формата, ни Content-Type — все сигнатуры
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($tsvError, 200, [$ok], 'q'), ChcException::class);
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($jsonError, 200, [], 'q'), ChcMemoryLimitExceededException::class);

        // Неоднозначный Content-Type (text/plain) — все сигнатуры
        $this->caughtException(fn() => ChcErrorDetector::throwIfError($xmlError, 200, ['Content-Type: text/plain'], 'q'), ChcException::class);
    }

    public function testUnifiesStatusAndTailErrors(): void
    {
        // не-200 + пустое тело: статус-строка сохраняется в сообщении
        $e = $this->caughtException(fn() => ChcErrorDetector::throwIfError('', 503, ['HTTP/1.1 503 Service Unavailable'], 'q'), ChcException::class);
        $this->assertSame('Unexpected response status: HTTP/1.1 503 Service Unavailable', $e->getMessage());
        $this->assertSame(503, $e->getCode());
        $this->assertSame('q', $e->getQuery());

        // не-200 + пустое тело + нет статус-строки: fallback на числовой код
        $e = $this->caughtException(fn() => ChcErrorDetector::throwIfError('', 503, [], 'q'), ChcException::class);
        $this->assertSame('Unexpected response status: 503', $e->getMessage());
        $this->assertSame(503, $e->getCode());

        // не-200 + тело: тело становится сообщением как раньше
        $e = $this->caughtException(fn() => ChcErrorDetector::throwIfError('Code: 60. DB::Exception: Unknown table', 500, ['HTTP/1.1 500 Internal Server Error'], 'q'), ChcException::class);
        $this->assertSame('Code: 60. DB::Exception: Unknown table', $e->getMessage());
        $this->assertSame(60, $e->getCode());

        // 200 + чистое тело — не бросает
        ChcErrorDetector::throwIfError('{"data":[]}', 200, ['HTTP/1.1 200 OK'], 'q');
        ChcErrorDetector::throwIfError("1\t2\n", 200, [], 'q');
        $this->addToAssertionCount(2);
    }
}
