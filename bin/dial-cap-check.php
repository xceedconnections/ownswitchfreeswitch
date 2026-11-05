<?php

declare(strict_types=1);

/**
 * OpenSIPS exec helper: exit 0 = allow dial, 1 = deny (Rate Cap Exceeded).
 *
 *   php bin/dial-cap-check.php <sip_username> <destination> <trunk_id>
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\RateCapService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Settings\SettingsService;

$user = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $argv[1]) : '';
$dst = isset($argv[2]) ? preg_replace('/\D+/', '', (string) $argv[2]) : '';
$tid = isset($argv[3]) ? (int) preg_replace('/\D+/', '', (string) $argv[3]) : 0;

if ($dst === '' || $tid <= 0) {
    fwrite(STDOUT, "ALLOW skip\n");
    exit(0);
}

$pdo = App::db();
$svc = new RateCapService($pdo, new RateResolver($pdo, new SettingsService($pdo)));
$res = $svc->authorize($user !== '' ? $user : null, $dst, $tid);

if ($res['allow']) {
    fwrite(STDOUT, 'ALLOW ' . $res['reason'] . "\n");
    exit(0);
}

fwrite(STDOUT, 'DENY ' . $res['reason'] . "\n");
fwrite(STDERR, 'DENY ' . $res['reason'] . "\n");
exit(1);
