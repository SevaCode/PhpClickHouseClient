<?php

namespace SevaCode\ClickHouseClient\Transports;

function file_get_contents($filename, $useIncludePath = false, $context = null)
{
    if ($GLOBALS['body_without_headers'] ?? false) {
        return 'Ok.';
    }

    // A read-only local failure supplies a real PHP stream error.
    return \file_get_contents(__FILE__ . '/missing');
}
