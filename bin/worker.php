#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * systemd entrypoint (deploy/templates/systemd/ownswitch-worker.service).
 * Forwards to worker_cdr.php with --loop when no args are given.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$forward = array_values(array_filter($argv, static fn ($a, $i) => $i > 0, ARRAY_FILTER_USE_BOTH));
if ($forward === []) {
    $forward = ['--loop'];
}
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/worker_cdr.php');
foreach ($forward as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}
passthru($cmd, $rc);
exit($rc);
