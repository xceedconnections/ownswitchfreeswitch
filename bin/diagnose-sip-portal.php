<?php

declare(strict_types=1);

/**
 * Show why the portal thinks softphones are offline.
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/diagnose-sip-portal.php
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;

$cfg = (array) App::config('app.integrations.opensips', []);
echo "OPENSIPS_ENABLED cfg : " . (!empty($cfg['enabled']) ? 'yes' : 'no') . "\n";
echo "sip_domain           : " . ($cfg['sip_domain'] ?? '') . "\n";
echo "public_ip            : " . ($cfg['public_ip'] ?? '') . "\n";
echo "mi_url               : " . ($cfg['mi_url'] ?? '') . "\n";
echo "db host/name/user    : " . ($cfg['db']['host'] ?? '') . ' / ' . ($cfg['db']['database'] ?? '') . ' / ' . ($cfg['db']['username'] ?? '') . "\n";
echo "db password set      : " . (((string) ($cfg['db']['password'] ?? '')) !== '' ? 'yes' : 'NO') . "\n\n";

$prov = OpensipsProvisioner::fromConfig();
echo "provisioner enabled  : " . ($prov->enabled() ? 'yes' : 'NO') . "\n";
echo "init error           : " . ($prov->initError() !== '' ? $prov->initError() : '(none)') . "\n";
echo "MI configured        : " . ($prov->hasMi() ? 'yes' : 'no') . "\n\n";

if (!$prov->enabled()) {
    fwrite(STDERR, "Cannot read opensips.location — fix OPENSIPS_DB_* in /etc/ownswitch/ownswitch.env\n");
    exit(1);
}

$regs = $prov->registeredContacts();
echo "registeredContacts() : " . count($regs) . " user(s)\n";
foreach ($regs as $u => $info) {
    echo "  - {$u} contact=" . ($info['contact'] ?? '') . " received=" . ($info['received'] ?? '') . "\n";
}

$pdo = $prov->pdo();
if ($pdo) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tables = array_map('strval', $tables);
        if (!in_array('location', $tables, true)) {
            fwrite(STDERR, "\nMISSING table opensips.location (usrloc schema).\n");
            fwrite(STDERR, "Fix: sudo bash /opt/ownswitch-src/deploy/fix-opensips-provision.sh\n");
            fwrite(STDERR, "  or: sudo deploy/install.sh --only 50\n");
            exit(1);
        }
        $n = (int) $pdo->query('SELECT COUNT(*) FROM location')->fetchColumn();
        echo "\nopensips.location rows: {$n}\n";
        foreach ($pdo->query('SELECT username, domain, contact, received, expires FROM location LIMIT 20') as $row) {
            echo '  ' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $ns = (int) $pdo->query('SELECT COUNT(*) FROM subscriber')->fetchColumn();
        echo "opensips.subscriber rows: {$ns}\n";
        foreach ($pdo->query('SELECT username, domain, LEFT(ha1,12) ha1 FROM subscriber LIMIT 20') as $row) {
            echo '  ' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
        }
        if (!$prov->hasMi()) {
            echo "\nNote: MI not configured — portal uses SQL location only (write-through).\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'SQL error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$portal = App::db()->query('SELECT id, username, domain, status, LEFT(ha1,12) ha1 FROM sip_accounts')->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo "\nportal sip_accounts:\n";
foreach ($portal as $row) {
    $u = strtolower((string) $row['username']);
    $on = isset($regs[$u]) ? 'REGISTERED' : 'offline';
    echo "  #{$row['id']} {$row['username']}@{$row['domain']} status={$row['status']} portal_view={$on}\n";
}

exit(0);
