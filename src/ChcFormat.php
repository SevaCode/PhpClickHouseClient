<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

enum ChcFormat: string
{
    case TabSeparated = 'TabSeparated';
    case TabSeparatedWithNames = 'TabSeparatedWithNames';
    case JSON = 'JSON';
    case JSONEachRow = 'JSONEachRow';
    case Pretty = 'Pretty';
}