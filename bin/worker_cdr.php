<?php

declare(strict_types=1);

/**
 * CDR ingest worker. Sources:
 *   1. table cdr_ingest_queue (status=pending, JSON payload)
 *   2. spool files storage/cdr_spool/*.json (one object or an array of objects)
 * Idempotent: retries/duplicates never double charge (unique external_cdr_key + ledger idempotency key).
 *
 *   php bin/worker_cdr.php            process once and exit (use Windows Task Scheduler / cron)
 *   php bin/worker_cdr.php --loop     poll every 2 seconds
 *   php bin/worker_cdr.php --limit=500
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\CdrRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Cdr\CdrIngestService;
use Ownswitch\Services\Cdr\OpensipsAccImporter;
use Ownswitch\Services\Settings\SettingsService;

$loop = in_array('--loop', $argv, true);
$limit = 200;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d{1,5})$/', $a, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$pdo = App::db();
$settings = new SettingsService($pdo);
$rating = new RatingService();
$ledger = new LedgerService($pdo);
$ingest = new CdrIngestService($pdo, $rating, new RateResolver($pdo, $settings), $ledger, new PrepaidService($pdo, $ledger, $rating), $settings, new CdrRepository($pdo));
$prepaid = new PrepaidService($pdo, $ledger, $rating);

$spool = App::basePath('storage/cdr_spool');
foreach ([$spool, "$spool/done", "$spool/failed"] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0770, true);
    }
}

$cycle = static function () use ($pdo, $ingest, $prepaid, $limit, $spool): int {
    $done = 0;
    $prepaid->expireStale();

    // OpenSIPS acc import is unused once FreeSWITCH posts xml_cdr. Skip when that DB is not configured.
    try {
        $os = OpensipsProvisioner::fromConfig();
        if ($os->enabled()) {
            $pulled = (new OpensipsAccImporter($pdo, $os))->import($limit);
            if ($pulled > 0) {
                echo gmdate('c') . " queued {$pulled} OpenSIPS acc row(s)\n";
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'opensips acc import: ' . $e->getMessage() . "\n");
    }

    $rows = $pdo->query("SELECT id, payload, source FROM cdr_ingest_queue WHERE status = 'pending' ORDER BY id LIMIT " . (int) $limit)->fetchAll();
    foreach ($rows as $row) {
        try {
            $payload = json_decode((string) $row['payload'], true, 16, JSON_THROW_ON_ERROR);
            $srcLabel = trim((string) ($row['source'] ?? 'worker'));
            if ($srcLabel === '') {
                $srcLabel = 'worker';
            }
            $res = $ingest->ingest(is_array($payload) ? $payload : [], $srcLabel);
            if ($res['status'] === 'invalid') {
                throw new \RuntimeException(implode('; ', $res['errors'] ?? []));
            }
            $pdo->prepare("UPDATE cdr_ingest_queue SET status = 'done', processed_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            $done++;
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE cdr_ingest_queue SET status = IF(attempts >= 4, 'failed', 'pending'), attempts = attempts + 1, last_error = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 480), $row['id']]);
            fwrite(STDERR, "queue #{$row['id']} error: {$e->getMessage()}\n");
        }
    }

    foreach (array_slice(glob($spool . '/*.json') ?: [], 0, $limit) as $file) {
        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            $list = is_array($data) && array_is_list($data) ? $data : [$data];
            foreach ($list as $item) {
                $res = $ingest->ingest(is_array($item) ? $item : [], 'worker');
                if ($res['status'] === 'invalid') {
                    throw new \RuntimeException(implode('; ', $res['errors'] ?? []));
                }
                $done++;
            }
            rename($file, $spool . '/done/' . basename($file));
        } catch (\Throwable $e) {
            fwrite(STDERR, basename($file) . ' error: ' . $e->getMessage() . "\n");
            @rename($file, $spool . '/failed/' . basename($file));
        }
    }
    return $done;
};

do {
    $n = $cycle();
    echo gmdate('c') . " processed {$n} CDR(s)\n";
    if ($loop) {
        sleep(2);
    }
} while ($loop);
