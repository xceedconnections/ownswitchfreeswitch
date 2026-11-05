<?php

declare(strict_types=1);

/**
 * Push all active trunks to FreeSWITCH Sofia gateways and print REGISTER state.
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/sync-fs-trunks.php
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;

$prov = FreeswitchProvisioner::fromConfig();
if (!$prov->enabled()) {
    fwrite(STDERR, 'FreeSWITCH ESL not configured: ' . $prov->initError() . "\n");
    exit(1);
}

$pdo = App::db();
$rows = $pdo->query('SELECT * FROM carrier_trunks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo 'Trunks in DB: ' . count($rows) . "\n";

$fail = 0;
foreach ($rows as $t) {
    $id = (int) $t['id'];
    $name = (string) $t['name'];
    $mode = (string) ($t['auth_mode'] ?? 'ip');
    $status = (string) ($t['status'] ?? '');
    echo "\n#{$id} {$name} mode={$mode} status={$status} host={$t['host']}\n";
    try {
        $prov->syncTrunk($t);
        echo "  sync: OK\n";
    } catch (Throwable $e) {
        echo '  sync: FAIL ' . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    usleep(400000);
    $st = $prov->gatewayStatus($id);
    echo '  sofia: ' . ($st['label'] !== '' ? $st['label'] : 'n/a')
        . ($st['state'] !== '' ? " (state={$st['state']})" : '') . "\n";
    if ($mode === 'register' && !in_array($st['state'], ['REGED', 'REGISTER'], true)) {
        $fail++;
    }
}

echo "\nDone. fail_count={$fail}\n";
echo "Expect REGED for Auth=REGISTER trunks. FAIL_WAIT usually means bad password or Asterisk ACL.\n";
exit($fail > 0 ? 2 : 0);
