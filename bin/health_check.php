<?php

declare(strict_types=1);

/**
 * Health probe for operators/monitoring. Exit code 0 = no failures, 1 = at least one component failed.
 *   php bin/health_check.php [--json]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\System\HealthService;

try {
    $pdo = App::db();
} catch (\Throwable) {
    $pdo = null;
}
$checks = (new HealthService())->run($pdo);

if (in_array('--json', $argv, true)) {
    echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($checks as $c) {
        printf("%-24s %-13s %s\n", $c['component'], strtoupper($c['status']), $c['detail']);
    }
}
$failed = array_filter($checks, static fn ($c) => $c['status'] === 'fail');
exit($failed ? 1 : 0);
