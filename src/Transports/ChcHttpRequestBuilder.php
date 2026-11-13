<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Transports;

use SevaCode\ClickHouseClient\ChcRequest;

/**
 * Single place for ClickHouse HTTP protocol rules shared by all transports:
 * GET/POST choice, ping, authentication headers, TLS CA file, compression.
 *
 * @internal
 */
final class ChcHttpRequestBuilder
{
    public static function build(
        ChcRequest $request,
        bool $readOnly,
        bool $readOnlyOverPost = false,
        bool $compression = false,
    ): ChcHttpRequest {
        $values = $request->getSettings();
        $query = (string)$request->getQuery();
        $headers = [];

        // Authentication using the "X-ClickHouse-User" and "X-ClickHouse-Key" headers.
        // https://clickhouse.com/docs/en/interfaces/http/
        if (isset($values['user'])) {
            if (!empty($values['user'])) {
                $headers['X-ClickHouse-User'] = (string)$values['user'];
            }
            unset($values['user']);
        }
        if (isset($values['password'])) {
            if (!empty($values['password'])) {
                $headers['X-ClickHouse-Key'] = (string)$values['password'];
            }
            unset($values['password']);
        }

        $cafile = null;
        if (isset($values['ssl_cafile'])) {
            if (!empty($values['ssl_cafile'])) {
                $cafile = (string)$values['ssl_cafile'];
            }
            unset($values['ssl_cafile']);
        }

        // пустой запрос (ping) всегда отправляем GET'ом
        if ('' === $query) {
            return new ChcHttpRequest('GET', 'ping', [], $headers, null, $cafile);
        }

        $method = $readOnly ? 'GET' : 'POST';

        // Opt-in: read-only запрос POST'ом с readonly=2 — то же, что сервер выставляет для GET
        if ($readOnly && $readOnlyOverPost) {
            $method = 'POST';
            if (!isset($values['readonly'])) {
                $values['readonly'] = 2;
            }
        }

        if ($compression && !isset($values['enable_http_compression'])) {
            $values['enable_http_compression'] = 1;
        }

        $body = null;
        if ('POST' === $method) {
            $headers['Content-type'] = 'application/x-www-form-urlencoded';
            $body = $query;
        } else {
            $values['query'] = $query;
        }

        return new ChcHttpRequest($method, '', $values, $headers, $body, $cafile);
    }
}
