<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

namespace SevaCode\ClickHouseClient;

use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

class ChcException extends \Exception
{
    /**
     * @var string
     */
    private $query;

    public static function create(string $message = '', int|string $code = 0, ?\Throwable $previous = null, ?string $query = null): self
    {
        // Входной $code может быть HTTP-статусом. Серверный Code: N точнее и имеет
        // приоритет над ним и над символическими названиями ошибок в тексте.
        // Формат подтверждён getExceptionMessageAndPattern() в ClickHouse (src/Common/Exception.cpp):
        // https://github.com/ClickHouse/ClickHouse/blob/master/src/Common/Exception.cpp
        // Code example: stream << "Code: " << e.code() << ". " << text;
        // Это best-effort поиск: Code: N должен быть в начале сообщения или строки (допустим отступ);
        // сама регулярка не доказывает, что найденная строка является ошибкой ClickHouse.
        if (preg_match('/^[\t ]*Code:[\t ]*(\d+)\b/m', $message, $match)) {
            $code = (int)$match[1];
        } elseif (!($previous instanceof TimeoutExceptionInterface)) {
            // Запасной поиск по названию: только без Code: N и НЕ для клиентского Symfony-таймаута.
            // Для клиентского Symfony-таймаута сохраняем переданный код:
            // таймаут HTTP-клиента не означает серверную ошибку ClickHouse с кодом 159.
            if (str_contains($message, 'MEMORY_LIMIT_EXCEEDED')) {
                $code = 241;
            } elseif (str_contains($message, 'TIMEOUT_EXCEEDED')) {
                $code = 159;
            }
        }

        // Клиентский таймаут получает специальный класс, но сохраняет свой код (например, 0).
        // Если заменить его на 159, по коду нельзя будет отличить ответ ClickHouse от таймаута HTTP-клиента.
        if ($previous instanceof TimeoutExceptionInterface || $code == 159) {
            $e = new ChcTimeoutExceededException($message, (int)$code, $previous);
        } elseif ($code == 241) {
            $e = new ChcMemoryLimitExceededException($message, (int)$code, $previous);
        } else {
            $e = new self($message, (int)$code, $previous);
        }

        if ($query !== null) {
            $e->setQuery($query);
        }

        return $e;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param string $query
     */
    public function setQuery($query)
    {
        $this->query = $query;
    }
}