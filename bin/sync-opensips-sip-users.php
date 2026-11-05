<?php

declare(strict_types=1);

/**
 * Push all active Ownswitch SIP accounts into opensips.subscriber (HA1 auth).
 * If password_enc is present, HA1 is recomputed for OPENSIPS_SIP_DOMAIN (fixes realm drift).
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/sync-opensips-sip-users.php
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\MediaPolicy;

$prov = OpensipsProvisioner::fromConfig();
if (!$prov->enabled()) {
    fwrite(STDERR, "OpenSIPS provisioning disabled. Set OPENSIPS_ENABLED=1 in /etc/ownswitch/ownswitch.env\n");
    fwrite(STDERR, $prov->initError() !== '' ? ('Detail: ' . $prov->initError() . "\n") : '');
    exit(1);
}

$domain = (string) App::config('app.integrations.opensips.sip_domain');
$pdo = App::db();
$rows = $pdo->query('SELECT * FROM sip_accounts')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ok = 0;
$skip = 0;
$rehashed = 0;
foreach ($rows as $r) {
    $username = (string) $r['username'];
    if (($r['status'] ?? '') !== 'active') {
        $prov->removeSubscriber($username, (string) ($r['domain'] ?? $domain));
        $skip++;
        continue;
    }

    $ha1 = (string) ($r['ha1'] ?? '');
    $ha1b = (string) ($r['ha1b'] ?? '');
    $plain = '';
    if (!empty($r['password_enc'])) {
        try {
            $plain = Crypto::decrypt((string) $r['password_enc']);
        } catch (\Throwable $e) {
            fwrite(STDERR, "WARN: {$username}: cannot decrypt password_enc ({$e->getMessage()})\n");
        }
    }

    // Recompute HA1 for the live SIP realm whenever we have the secret
    if ($plain !== '') {
        [$ha1, $ha1b] = OpensipsProvisioner::ha1($username, $domain, $plain);
        $pdo->prepare('UPDATE sip_accounts SET domain = ?, ha1 = ?, ha1b = ? WHERE id = ?')
            ->execute([$domain, $ha1, $ha1b, (int) $r['id']]);
        $r['domain'] = $domain;
        $r['ha1'] = $ha1;
        $r['ha1b'] = $ha1b;
        $r['_plain_password'] = $plain;
        $rehashed++;
    } elseif ((string) ($r['domain'] ?? '') !== $domain) {
        fwrite(STDERR, "WARN: {$username} domain={$r['domain']} but OPENSIPS_SIP_DOMAIN={$domain}. "
            . "Reset SIP password in portal so HA1 matches the challenge realm.\n");
    }

    if (strlen($ha1) !== 32) {
        fwrite(STDERR, "WARN: {$username} has invalid HA1 — reset password in portal.\n");
        $skip++;
        continue;
    }

    $effective = MediaPolicy::resolve(
        isset($r['media_policy']) ? (string) $r['media_policy'] : null,
        $pdo
    );
    $r['_effective_media'] = $effective;
    $prov->upsertSubscriber($username, $domain, $ha1, $ha1b, $r);
    $prov->syncSipPeerProfile($username, $effective === 'record' ? 'record' : $effective, (int) ($r['max_channels'] ?? 2), $r);
    $ok++;
}

$proxy = trim((string) App::config('app.integrations.opensips.public_ip', ''));
if ($proxy === '') {
    $proxy = $domain;
}

echo "Provisioned {$ok} active SIP user(s); rehashed {$rehashed}; skipped {$skip}.\n";
echo "Auth mode        : plaintext password (calculate_ha1=1) — Domain may be IP or any FQDN\n";
echo "Softphone Domain : {$proxy}  OR  {$domain}  OR any FQDN pointing here\n";
echo "Outbound Proxy   : leave BLANK\n";
echo "Port / Transport : 5060 UDP\n";
if ($rehashed === 0 && $ok > 0) {
    echo "NOTE: No password_enc — set a new SIP password in the portal once, Save, then re-run this script.\n";
}
exit(0);
