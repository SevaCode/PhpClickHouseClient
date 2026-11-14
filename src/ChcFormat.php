<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

namespace SevaCode\ClickHouseClient;

/**
 * In 2.0 becomes enum ChcFormat: string; use the constants (ChcFormat::JSON), not string literals.
 */
class ChcFormat
{
    const TabSeparated = 'TabSeparated';
    const TabSeparatedWithNames = 'TabSeparatedWithNames';
    const JSON = 'JSON';
    const JSONEachRow = 'JSONEachRow';
    const Pretty = 'Pretty';
}