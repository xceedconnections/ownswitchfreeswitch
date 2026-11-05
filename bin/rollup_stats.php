<?php

declare(strict_types=1);

/**
 * Recompute stats_hourly / stats_daily from CDRs (idempotent).
 *   php bin/rollup_stats.php                       last 2 days
 *   php bin/rollup_stats.php --from=2026-10-01 --to=2026-10-31
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Reports\StatsRollupService;

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(from|to)=(\d{4}-\d{2}-\d{2})$/', $a, $m)) {
        $o[$m[1]] = $m[2];
    }
}
$utc = new \DateTimeZone('UTC');
$to = new \DateTimeImmutable($o['to'] ?? 'now', $utc);
$from = new \DateTimeImmutable($o['from'] ?? $to->modify('-1 day')->format('Y-m-d'), $utc);
if ($from > $to) {
    fwrite(STDERR, "--from must not be after --to\n");
    exit(2);
}
$r = (new StatsRollupService(App::db()))->rollup($from, $to);
echo sprintf("Rolled up %s .. %s: hourly rows affected=%d, daily rows affected=%d\n", $from->format('Y-m-d'), $to->format('Y-m-d'), $r['hourly'], $r['daily']);
