# Дорожная карта PhpClickHouseClient

Статус: `[x]` — сделано, `[ ]` — не сделано, `[~]` — сделано частично (см. примечание).
Каждый пункт отмечается в том PR, где он реализован.

Принципы — [ADR-0001: обратная совместимость](../adr/0001-backward-compatibility.md):
этапы 1–4 полностью обратно совместимы и выходят в 1.x; этап 5 требует согласования.

---

## Этап 0. Документация и процесс

- [x] Структура `docs/` (`roadmap/`, `adr/`), шаблон ADR ([PR #1](https://github.com/sevacode/phpclickhouseclient/pull/1)).
- [x] ADR-0001: политика обратной совместимости ([PR #1](https://github.com/sevacode/phpclickhouseclient/pull/1)).
- [x] Этот документ ([PR #1](https://github.com/sevacode/phpclickhouseclient/pull/1)).

## Этап 1. Инфраструктура и качество

Цель: любое изменение проверяется автоматически, в т.ч. против настоящего ClickHouse.

- [x] `composer.json`: явная зависимость `psr/log`; `symfony/http-client: ^6.2 || ^7.0` ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] Совместимость с PHP 8.4: явный nullable в `ExtendedSymfonyHttpResponse::getInfo()` ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] PHPUnit в `require-dev`; регрессионные тесты из `tests/run.php` перенесены в PHPUnit ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] Интеграционные тесты против реального ClickHouse (группа `integration`,
      включаются переменной окружения `CLICKHOUSE_URL`) ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] PHPStan в `require-dev` + `phpstan.neon.dist`, уровень 5 без ошибок (повышать постепенно) ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] GitHub Actions: матрица PHP 8.1–8.4, юнит-тесты, PHPStan, интеграционные тесты
      с ClickHouse в service-контейнере ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] README: разделы «Разработка» и «Тесты» обновлены ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).
- [x] Детекция ошибок посреди стриминга исправлена по итогам интеграционных тестов: plain-text
      сразу после JSON-данных (ClickHouse 23.x) и блок `__exception__` с `X-ClickHouse-Exception-Tag` (25.x+) ([PR #2](https://github.com/sevacode/phpclickhouseclient/pull/2)).

## Этап 2. Безопасность и корректность

Цель: безопасная передача пользовательских значений и явная конфигурация подключения.

- [x] Серверные параметризованные запросы: `{name:Type}` в SQL + значения отдельно
      (`param_name=...`). Кодирование значений (`ChcParamEncoder`): string, int, float, bool,
      null, `DateTimeInterface`, массивы (в т.ч. вложенные) ([PR #3](https://github.com/sevacode/phpclickhouseclient/pull/3)).
- [x] Новый высокоуровневый API с параметрами (новые методы, старые не меняются):
      `fetchAll()`, `fetchRow()`, `fetchOne()`, `fetchColumn()`, `write()` ([PR #3](https://github.com/sevacode/phpclickhouseclient/pull/3)).
- [x] Учётные данные отдельно от настроек ClickHouse: `setCredentials($user, $password)`
      (старый способ через `addSettings(['user' => ..., 'password' => ...])` продолжает работать) ([PR #3](https://github.com/sevacode/phpclickhouseclient/pull/3)).
- [x] Таймаут HTTP-запроса: `setTimeout(?float $seconds)` для обоих транспортов ([PR #3](https://github.com/sevacode/phpclickhouseclient/pull/3)).
- [x] Opt-in чтение через POST: `setReadOnlyOverPost(true)` — read-only запросы уходят POST
      с `readonly=2` (то же, что сервер выставляет для GET), SQL не попадает в URL
      и не упирается в лимит длины URL. По умолчанию — как раньше, GET ([PR #3](https://github.com/sevacode/phpclickhouseclient/pull/3)).

## Этап 3. Транспорт и производительность

- [x] Общая сборка HTTP-запроса (аутентификация, TLS, ping, параметры) для обоих
      транспортов — устранение дублирования между `ChcHttpTransport` и `ChcSymfonyCurlTransport` ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).
- [x] Внедрение HTTP-клиента: `setHttpClient(HttpClientInterface $client)` — все запросы
      клиента идут через него с переиспользованием соединений (keep-alive).
      Без вызова — поведение прежнее ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).
- [x] Потоковое чтение: `iterate($sql, $params)` — генератор строк поверх `JSONEachRow`,
      память не зависит от размера результата ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).
- [x] Потоковая вставка: `insert($table, iterable $rows, $columns)` — `INSERT ... FORMAT JSONEachRow`,
      тело отправляется потоком ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).
- [x] HTTP-сжатие ответов: `setCompression(true)` (`enable_http_compression=1` + gzip) ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).
- [x] `getJsonPool()`: ограничение параллельности (`setPoolConcurrency()`), заполнение
      `getLastQueryLatency()`, использование внедрённого HTTP-клиента, удалён закомментированный код ([PR #4](https://github.com/sevacode/phpclickhouseclient/pull/4)).

## Этап 4. Возможности драйвера

- [ ] `query_id` у каждого запроса (свой из настроек или сгенерированный), `getLastQueryId()`.
- [ ] Сессии: `setSessionId(?string)` для временных таблиц и сессионных настроек.
- [ ] Статистика запроса из `X-ClickHouse-Summary`: `getLastQuerySummary()`, `ChcResponse::getSummary()`.
- [ ] Повтор запросов при сетевых ошибках и 502/503/504 — только для read-only запросов:
      `setRetries(int $count, float $delaySeconds)`. По умолчанию выключено.
- [ ] Исключения: `ChcConnectionException` (нет HTTP-ответа) как наследник `ChcException`;
      `getServerCode()` и `getHttpStatus()` отдельно от `getCode()`.
- [ ] `isAlive(): bool` — проверка доступности без исключений (`ping()` не меняется).
- [ ] README: документация нового API; нюанс 64-битных целых
      (`output_format_json_quote_64bit_integers`) и режима `wait_end_of_query=1`.
- [ ] CHANGELOG.md, git-тег релиза (тег ставит владелец).

---

## Этап 5. Требует согласования (нарушает обратную совместимость)

Не реализуется без явного одобрения. Вероятная цель — 2.0.

- [ ] Удалить устаревшие `HttpClient` и `Mode`.
- [ ] Строгие типы в сигнатурах публичных методов и свойств, `declare(strict_types=1)`.
- [ ] `ChcFormat` → `enum`.
- [ ] Иммутабельный клиент (`with*()`-методы) вместо мутабельных сеттеров.
- [ ] Всегда POST + `readonly=2` для чтения (сделать поведением по умолчанию).
- [ ] Отказ от транспорта на `file_get_contents` в пользу единственного на Symfony HttpClient.
- [ ] `Content-Type: text/plain` вместо `application/x-www-form-urlencoded` для POST.
- [ ] `ping()` возвращает `false` вместо исключения при недоступном сервере.
- [ ] Новая иерархия исключений по серверным кодам (не только 159/241), отдельные коды.
- [ ] Переименование классов без префикса `Chc`.
- [ ] Перевод комментариев в коде на английский.
- [ ] Массовое автоформатирование кода (PHP-CS-Fixer / PER-CS).
- [ ] Минимальная версия PHP 8.2 / Symfony 6.4.

## Вне рамок / открытые вопросы

- Сжатие тела запроса (gzip/zstd для `insert`) — конфликтует с потоковой отправкой;
  вернуться после этапа 3.
- Бинарные форматы (`RowBinary`, `Native`) — выигрыш в скорости, но большой объём кода.
- Надёжная детекция ошибок посреди стрима по маркерам новых версий ClickHouse
  вместо эвристики по хвосту тела — нужно исследование по версиям сервера.
