<?php

declare(strict_types=1);

/**
 * Push all Ownswitch trunks + routing rules into OpenSIPS (drouting / address / uac_registrant).
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/sync-opensips-trunks.php
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Services\Routing\OpensipsRoutingSync;

$prov = FreeswitchProvisioner::fromConfig();
$sync = new OpensipsRoutingSync(App::db(), $prov);
$result = $sync->syncAll();

echo "Synced trunks={$result['trunks']} rules={$result['rules']}\n";
foreach ($result['warnings'] as $w) {
    fwrite(STDERR, "WARN: {$w}\n");
}
exit($result['warnings'] ? 2 : 0);
