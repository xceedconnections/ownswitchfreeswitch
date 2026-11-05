#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rebuild DNC Redis SETs + OpenSIPS dnc_cache from portal MySQL.
 * Usage: php bin/sync-dnc-cache.php [group_id]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Dnc\DncService;

$gid = isset($argv[1]) ? (int) $argv[1] : null;
if ($gid !== null && $gid <= 0) {
    $gid = null;
}

$svc = new DncService(App::db());
$svc->rebuildGroupCache($gid);
fwrite(STDOUT, 'DNC cache rebuilt' . ($gid ? " for group {$gid}" : ' for all groups') . PHP_EOL);
