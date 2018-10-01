<?php
/**
 * Author: SevaCode
 * CreatedAt: 28.06.2016
 */

namespace SevaCode\ClickHouseClient;

/**
 * @deprecated 
 */
class Mode
{
    const READONLY = 'readonly';
    const WRITE = 'write';

    /**
     * @param string $mode
     * @return bool
     */
    public static function isReadOnly($mode)
    {
        return self::READONLY === $mode;
    }
}