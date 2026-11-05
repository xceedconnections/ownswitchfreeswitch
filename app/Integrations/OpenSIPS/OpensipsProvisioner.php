<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\OpenSIPS;

use Ownswitch\Support\Crypto;
use PDO;

/**
 * Projects Ownswitch SIP accounts, IP peers and carrier trunks into the OpenSIPS DB
 * (subscriber, address, dr_gateways, dr_rules, uac_registrant) and reloads via MI.
 *
 * Address groups (must match opensips.cfg.tpl):
 *   1 = customer IP auth
 *   2 = provider / carrier IPs (inbound)
 */
final class OpensipsProvisioner
{
    public const GRP_CUSTOMER = 1;
    public const GRP_CARRIER = 2;
    public const DR_GROUP = '1';

    /** True when the last miCall got an HTTP/CLI response (even with JSON-RPC error). */
    private bool $lastMiReachable = false;

    /** True when dlg_list returned an explicit empty dialog set (safe to wipe DB zombies). */
    private bool $miDialogsEmptyConfirmed = false;

    public function __construct(
        private ?PDO $osDb,
        private string $miUrl = '',
        private string $sipDomain = '',
        private string $initError = '',
        private string $publicIp = '',
        private string $miFifo = '',
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = (array) \Ownswitch\App::config('app.integrations.opensips', []);
        if (empty($cfg['enabled'])) {
            return new self(null, '', '', 'OPENSIPS_ENABLED is off');
        }
        $db = (array) ($cfg['db'] ?? []);
        if (trim((string) ($db['username'] ?? '')) === '' || (string) ($db['password'] ?? '') === '') {
            return new self(null, '', '', 'OpenSIPS DB username/password missing in /etc/ownswitch/ownswitch.env');
        }
        try {
            $pdo = \Ownswitch\Database\Connection::make($db + ['charset' => 'utf8mb4']);
        } catch (\Throwable $e) {
            error_log('[ownswitch] OpenSIPS DB connect failed: ' . $e->getMessage());
            return new self(null, '', '', 'Cannot connect to OpenSIPS DB: ' . $e->getMessage());
        }
        $mi = trim((string) ($cfg['mi_url'] ?? ''));
        if ($mi === '') {
            $mi = 'http://127.0.0.1:8888/mi';
        }
        return new self(
            $pdo,
            $mi,
            (string) ($cfg['sip_domain'] ?? ''),
            '',
            (string) ($cfg['public_ip'] ?? ''),
            (string) ($cfg['mi_fifo'] ?? '/run/opensips/opensips_fifo'),
        );
    }

    public function enabled(): bool
    {
        return $this->osDb !== null;
    }

    public function initError(): string
    {
        return $this->initError;
    }

    public function hasMi(): bool
    {
        if ($this->miUrl !== '') {
            return true;
        }
        return $this->miFifo !== '' && @is_writable($this->miFifo);
    }

    /** OpenSIPS MySQL handle (null when provisioning disabled). */
    public function pdo(): ?PDO
    {
        return $this->osDb;
    }

    public static function ha1(string $username, string $realm, string $password): array
    {
        return [md5("{$username}:{$realm}:{$password}"), md5("{$username}@{$realm}:{$realm}:{$password}")];
    }

    /**
     * @param array<string,mixed>|null $account Ownswitch sip_accounts row.
     *        Pass `_plain_password` so OpenSIPS calculate_ha1=1 can auth any Domain (FQDN/IP).
     */
    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b, ?array $account = null): void
    {
        $db = $this->db();
        if (!$this->tableExists('subscriber')) {
            throw new \RuntimeException('opensips.subscriber table missing — re-run deploy stage 50 / OpenSIPS schema');
        }
        $cols = $this->columns('subscriber');

        // use_domain=0 → one subscriber row per username. Prefer exact (user,domain),
        // then drop any other domain rows for the same user to avoid account_idx dupes.
        $stExact = $db->prepare('SELECT id FROM subscriber WHERE username = ? AND domain = ? LIMIT 1');
        $stExact->execute([$username, $domain]);
        $id = $stExact->fetchColumn();
        if ($id) {
            $db->prepare('DELETE FROM subscriber WHERE username = ? AND id <> ?')->execute([$username, $id]);
        } else {
            $stAny = $db->prepare('SELECT id FROM subscriber WHERE username = ? ORDER BY id ASC LIMIT 1');
            $stAny->execute([$username]);
            $id = $stAny->fetchColumn();
            if ($id) {
                // Reuse oldest row; remove siblings first so domain UPDATE cannot collide
                $db->prepare('DELETE FROM subscriber WHERE username = ? AND id <> ?')->execute([$username, $id]);
            }
        }

        $ipAuth = $account !== null && (
            !empty($account['_clear_sip_secret'])
            || strtolower(trim((string) ($account['connection_mode'] ?? ''))) === 'ip'
        );
        $set = [
            'domain' => $domain,
            'ha1' => $ipAuth ? str_repeat('0', 32) : $ha1,
        ];
        if (in_array('ha1b', $cols, true)) {
            $set['ha1b'] = $ipAuth ? str_repeat('0', 32) : $ha1b;
        }
        if (in_array('password', $cols, true)) {
            if ($ipAuth) {
                $set['password'] = '';
            } else {
                $plain = '';
                if ($account !== null) {
                    $plain = (string) ($account['_plain_password'] ?? '');
                    if ($plain === '' && !empty($account['password_enc'])) {
                        try {
                            $plain = Crypto::decrypt((string) $account['password_enc']);
                        } catch (\Throwable) {
                            $plain = '';
                        }
                    }
                }
                if ($plain !== '') {
                    $set['password'] = $plain;
                } elseif (!$id) {
                    $set['password'] = '';
                }
            }
        }
        if ($account !== null && in_array('rpid', $cols, true)) {
            $cid = trim((string) ($account['caller_id'] ?? ''));
            $set['rpid'] = $cid !== '' ? $cid : '';
        }
        if ($account !== null) {
            $this->ensureSubscriberExtraColumns();
            $cols = $this->columns('subscriber');
            if (in_array('media_policy', $cols, true)) {
                $mp = strtolower(trim((string) ($account['_effective_media'] ?? $account['media_policy'] ?? 'relay')));
                if ($mp === '' || $mp === 'default' || $mp === 'inherit') {
                    $mp = 'relay';
                }
                $set['media_policy'] = in_array($mp, ['direct', 'relay', 'record'], true) ? $mp : 'relay';
            }
            if (in_array('dtmf_pass', $cols, true)) {
                $dp = strtolower(trim((string) ($account['dtmf_pass'] ?? 'yes')));
                $set['dtmf_pass'] = $dp === 'no' ? 'no' : 'yes';
            }
            if (in_array('cid_mode', $cols, true)) {
                $mode = strtolower(trim((string) ($account['caller_id_mode'] ?? '')));
                $cid = trim((string) ($account['caller_id'] ?? ''));
                if ($mode === '' || $mode === 'auto') {
                    $mode = $cid !== '' ? 'fixed' : 'passthrough';
                }
                $set['cid_mode'] = in_array($mode, ['passthrough', 'fixed', 'display_name'], true) ? $mode : 'passthrough';
            }
            if (in_array('cid_min_len', $cols, true)) {
                $set['cid_min_len'] = max(0, (int) ($account['cid_min_len'] ?? 0));
            }
            if (in_array('cid_max_len', $cols, true)) {
                $set['cid_max_len'] = max(0, (int) ($account['cid_max_len'] ?? 0));
            }
            if (in_array('allow_calls', $cols, true)) {
                $set['allow_calls'] = $this->customerAllowsCalls($account) ? 1 : 0;
            }
            if (in_array('dst_format', $cols, true)) {
                $set['dst_format'] = \Ownswitch\Support\DstFormat::wireCode(
                    (string) ($account['dst_format'] ?? 'as_is')
                );
            }
            if (in_array('dnc_group_id', $cols, true)) {
                $set['dnc_group_id'] = max(0, (int) ($account['dnc_group_id'] ?? 0));
            }
        }

        if ($id) {
            $parts = [];
            $vals = [];
            foreach ($set as $c => $v) {
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $db->prepare('UPDATE subscriber SET ' . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
        } else {
            $insert = ['username' => $username, 'domain' => $domain] + $set;
            foreach (['email_address', 'rpid'] as $opt) {
                if (in_array($opt, $cols, true) && !array_key_exists($opt, $insert)) {
                    $insert[$opt] = '';
                }
            }
            $cnames = array_keys($insert);
            $sql = 'INSERT INTO subscriber (' . implode(',', array_map(static fn ($c) => "`{$c}`", $cnames)) . ') VALUES ('
                . implode(',', array_fill(0, count($cnames), '?')) . ')';
            $db->prepare($sql)->execute(array_values($insert));
        }

        // Fixed-host SIP users → permissions address (customer group); honour sip_port
        if ($account !== null && $this->tableExists('address')) {
            $host = trim((string) ($account['sip_host'] ?? 'dynamic'));
            $ctx = 'sip:' . $username;
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, $ctx]);
            if ($host !== '' && strtolower($host) !== 'dynamic' && filter_var($host, FILTER_VALIDATE_IP)) {
                $port = (int) ($account['sip_port'] ?? 5060);
                if ($port < 0 || $port > 65535) {
                    $port = 5060;
                }
                // 0 = any port (OpenSIPS address semantics)
                $this->upsertAddress(
                    self::GRP_CUSTOMER,
                    $host,
                    str_contains($host, ':') ? 128 : 32,
                    $port,
                    'any',
                    $ctx
                );
            }
        }
    }

    public function removeSubscriber(string $username, string $domain): void
    {
        $db = $this->db();
        if ($this->tableExists('subscriber')) {
            // use_domain=0 — remove all domain rows for this username
            $db->prepare('DELETE FROM subscriber WHERE username = ?')->execute([$username]);
        }
        if ($this->tableExists('address')) {
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, 'sip:' . $username]);
        }
        // Drop live registrations so disabled SIP cannot place calls
        if ($this->tableExists('location')) {
            try {
                $db->prepare('DELETE FROM location WHERE username = ?')->execute([$username]);
            } catch (\Throwable) {
            }
        }
        if ($this->hasMi()) {
            $this->miCall('ul_rm', ['AOR' => $username]);
            $this->miCall('ul_rm', [$username]);
        }
        unset($domain);
    }

    /** Disabled SIP / inactive customer / prepaid with no funds → allow_calls=0 → 402. */
    private function customerAllowsCalls(array $account): bool
    {
        if (strtolower(trim((string) ($account['status'] ?? 'active'))) !== 'active') {
            return false;
        }
        $cid = isset($account['customer_id']) ? (int) $account['customer_id'] : 0;
        if ($cid <= 0) {
            return true;
        }
        try {
            $portal = \Ownswitch\App::db();
            $st = $portal->prepare('SELECT billing_mode, credit_limit, status FROM customers WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) {
                return true;
            }
            if (strtolower((string) ($c['status'] ?? '')) !== 'active') {
                return false;
            }
            if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
                return true;
            }
            $bst = $portal->prepare('SELECT balance, reserved FROM balances WHERE customer_id = ? LIMIT 1');
            $bst->execute([$cid]);
            $b = $bst->fetch(\PDO::FETCH_ASSOC) ?: ['balance' => '0', 'reserved' => '0'];
            $avail = (float) ($b['balance'] ?? 0) - (float) ($b['reserved'] ?? 0);
            return $avail > 0.000001;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Re-push allow_calls for every active SIP account of a customer (after top-up / spend). */
    public function refreshAllowCallsForCustomer(int $customerId): void
    {
        if ($customerId <= 0 || !$this->enabled()) {
            return;
        }
        try {
            $portal = \Ownswitch\App::db();
            $st = $portal->prepare("SELECT * FROM sip_accounts WHERE customer_id = ? AND status = 'active'");
            $st->execute([$customerId]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $effective = \Ownswitch\Support\MediaPolicy::resolve(
                    isset($row['media_policy']) ? (string) $row['media_policy'] : null,
                    $portal
                );
                $row['_effective_media'] = $effective;
                $this->upsertSubscriber(
                    (string) $row['username'],
                    (string) $row['domain'],
                    (string) $row['ha1'],
                    (string) ($row['ha1b'] ?? ''),
                    $row
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] refreshAllowCallsForCustomer: ' . $e->getMessage());
        }
    }

    public function upsertAddress(int $group, string $ip, int $mask, int $port, string $proto, string $context = ''): void
    {
        $db = $this->db();
        $st = $db->prepare('SELECT id FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?');
        $st->execute([$group, $ip, $mask, $port]);
        if ($id = $st->fetchColumn()) {
            if ($context !== '') {
                $db->prepare('UPDATE address SET proto = ?, context_info = ? WHERE id = ?')->execute([$proto, $context, $id]);
            }
            return;
        }
        $db->prepare('INSERT INTO address (grp, ip, mask, port, proto, pattern, context_info) VALUES (?,?,?,?,?,NULL,?)')
            ->execute([$group, $ip, $mask, $port, $proto, $context !== '' ? $context : null]);
    }

    public function removeAddress(int $group, string $ip, int $mask, int $port): void
    {
        $this->db()->prepare('DELETE FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?')->execute([$group, $ip, $mask, $port]);
    }

    public function reloadAddresses(): bool
    {
        return $this->mi('address_reload');
    }

    public function reloadRouting(): bool
    {
        return $this->mi('dr_reload');
    }

    public function reloadRegistrant(): bool
    {
        return $this->mi('reg_reload') || $this->mi('uac_reg_reload');
    }

    /** @param array<string,mixed> $trunk */
    public function syncTrunk(array $trunk): void
    {
        $id = (int) $trunk['id'];
        $gwid = self::gwid($id);
        $active = ($trunk['status'] ?? '') === 'active';
        $host = (string) $trunk['host'];
        $port = (int) ($trunk['port'] ?? 5060);
        $transport = strtolower((string) ($trunk['transport'] ?? 'udp'));
        $address = $host . ($port > 0 && $port !== 5060 ? ':' . $port : '');
        if (in_array($transport, ['tcp', 'tls'], true)) {
            $address .= ';transport=' . $transport;
        }

        if (!$active) {
            $this->removeTrunk($id);
            return;
        }

        if (!$this->tableExists('dr_gateways')) {
            throw new \RuntimeException('opensips.dr_gateways missing — run: sudo bash /opt/ownswitch-src/deploy/fix-drouting-schema.sh');
        }

        // Multi remove-prefix is applied in OpenSIPS script via attrs rstrip=; keep gateway.strip=0.
        $strip = 0;
        $pri = trim((string) ($trunk['add_prefix'] ?? ''));
        $probe = strtolower((string) ($trunk['qualify'] ?? 'yes')) === 'yes' ? 1 : 0;
        $attrs = self::trunkAttrs($trunk);
        $this->upsertGateway($gwid, $address, substr((string) $trunk['name'], 0, 120), $strip, $pri !== '' ? $pri : null, $probe, $attrs);

        if (filter_var($host, FILTER_VALIDATE_IP) && $this->tableExists('address')) {
            try {
                $this->upsertAddress(self::GRP_CARRIER, $host, str_contains($host, ':') ? 128 : 32, 0, 'any', 'trunk:' . $id);
            } catch (\Throwable $e) {
                error_log('[ownswitch] address upsert trunk ' . $id . ': ' . $e->getMessage());
            }
        }

        $mode = (string) ($trunk['auth_mode'] ?? 'ip');
        if ($mode === 'register') {
            try {
                $this->upsertRegistrant($trunk);
            } catch (\Throwable $e) {
                // Still keep the gateway; REGISTER is optional enhancement.
                throw new \RuntimeException(
                    'Gateway saved, but outbound REGISTER failed: ' . $e->getMessage()
                    . ' (For IP peering use Auth mode = IP peer instead.)',
                    0,
                    $e
                );
            }
        } else {
            $this->removeRegistrant($id);
        }
    }

    private function upsertGateway(
        string $gwid,
        string $address,
        string $desc,
        int $strip = 0,
        ?string $priPrefix = null,
        int $probeMode = 0,
        ?string $attrs = null,
    ): void {
        $db = $this->db();
        $cols = $this->columns('dr_gateways');
        if (!in_array('gwid', $cols, true) || !in_array('address', $cols, true)) {
            throw new \RuntimeException('dr_gateways schema unexpected (need gwid+address columns)');
        }
        $st = $db->prepare('SELECT id FROM dr_gateways WHERE gwid = ?');
        $st->execute([$gwid]);
        $rowId = $st->fetchColumn();

        $fields = ['type' => 0, 'address' => $address];
        if (in_array('strip', $cols, true)) {
            $fields['strip'] = max(0, $strip);
        }
        if (in_array('pri_prefix', $cols, true)) {
            $fields['pri_prefix'] = $priPrefix;
        }
        if (in_array('state', $cols, true)) {
            $fields['state'] = 0;
        }
        if (in_array('description', $cols, true)) {
            $fields['description'] = $desc;
        }
        if (in_array('probe_mode', $cols, true)) {
            $fields['probe_mode'] = $probeMode > 0 ? 1 : 0;
        }
        if (in_array('attrs', $cols, true)) {
            $fields['attrs'] = $attrs !== null && $attrs !== '' ? mb_substr($attrs, 0, 250) : null;
        }

        if ($rowId) {
            $parts = [];
            $vals = [];
            foreach ($fields as $c => $v) {
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $rowId;
            $db->prepare('UPDATE dr_gateways SET ' . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
            return;
        }

        $insert = ['gwid' => $gwid] + $fields;
        if (in_array('socket', $cols, true) && !array_key_exists('socket', $insert)) {
            $insert['socket'] = null;
        }
        $names = array_keys($insert);
        $sql = 'INSERT INTO dr_gateways (' . implode(',', array_map(static fn ($c) => "`{$c}`", $names)) . ') VALUES ('
            . implode(',', array_fill(0, count($names), '?')) . ')';
        $db->prepare($sql)->execute(array_values($insert));
    }

    /** Digits to strip from RURI = length of a single remove_prefix (legacy). */
    public static function stripDigits(string $removePrefix): int
    {
        $first = explode(',', $removePrefix)[0] ?? '';
        $p = preg_replace('/\D+/', '', $first) ?? '';
        return min(32, strlen($p));
    }

    /** @param array<string,mixed> $trunk OpenSIPS-applied attrs only (From / CID rewrite / multi-strip / tid). */
    public static function trunkAttrs(array $trunk): string
    {
        $parts = [];
        $tid = (int) ($trunk['id'] ?? 0);
        if ($tid > 0) {
            $parts[] = 'tid=' . $tid;
        }
        foreach (['fu' => 'from_user', 'fd' => 'from_domain', 'cidadd' => 'cid_add_prefix', 'cidrem' => 'cid_remove_prefix'] as $k => $col) {
            $v = trim((string) ($trunk[$col] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . rawurlencode($v);
            }
        }
        $cmin = (int) ($trunk['cid_min_len'] ?? 0);
        $cmax = (int) ($trunk['cid_max_len'] ?? 0);
        if ($cmin > 0) {
            $parts[] = 'cidmin=' . $cmin;
        }
        if ($cmax > 0) {
            $parts[] = 'cidmax=' . $cmax;
        }
        $dncid = (int) ($trunk['dnc_group_id'] ?? 0);
        if ($dncid > 0) {
            $parts[] = 'dncid=' . $dncid;
        }
        $dstfmt = \Ownswitch\Support\DstFormat::wireCode((string) ($trunk['dst_format'] ?? 'as_is'));
        if ($dstfmt !== 'as_is') {
            $parts[] = 'dstfmt=' . $dstfmt;
        }
        $rawRem = trim((string) ($trunk['remove_prefix'] ?? ''));
        if ($rawRem !== '') {
            $list = [];
            foreach (preg_split('/[\s,;|]+/', $rawRem) ?: [] as $p) {
                $p = preg_replace('/\D+/', '', $p) ?? '';
                if ($p !== '' && !in_array($p, $list, true)) {
                    $list[] = $p;
                }
            }
            usort($list, static fn ($a, $b) => strlen($b) <=> strlen($a));
            if ($list !== []) {
                $parts[] = 'rstrip=' . rawurlencode(implode(',', $list));
            }
        }
        return implode(';', $parts);
    }

    public function removeTrunk(int $trunkId): void
    {
        $gwid = self::gwid($trunkId);
        $db = $this->db();
        if ($this->tableExists('dr_gateways')) {
            $db->prepare('DELETE FROM dr_gateways WHERE gwid = ?')->execute([$gwid]);
        }
        if ($this->tableExists('address')) {
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CARRIER, 'trunk:' . $trunkId]);
        }
        $this->removeRegistrant($trunkId);
    }

    /**
     * @param list<array{prefix:string,carrier_trunk_id:int,priority:int,status?:string,sort_alg?:string,strategy?:string}> $rules
     */
    public function syncRoutingRules(array $rules): void
    {
        if (!$this->tableExists('dr_rules')) {
            throw new \RuntimeException('opensips.dr_rules missing — apply drouting schema');
        }
        $db = $this->db();
        $byPrefix = [];
        foreach ($rules as $r) {
            if (($r['status'] ?? 'active') !== 'active') {
                continue;
            }
            $byPrefix[(string) $r['prefix']][] = $r;
        }
        foreach ($byPrefix as &$list) {
            usort($list, static fn ($a, $b) => ((int) $a['priority']) <=> ((int) $b['priority']));
        }
        unset($list);

        $db->prepare('DELETE FROM dr_rules WHERE groupid = ?')->execute([self::DR_GROUP]);
        $prio = 0;
        $hasSort = in_array('sort_alg', $this->columns('dr_rules'), true);
        foreach ($byPrefix as $prefix => $list) {
            $gws = [];
            $sortAlg = 'N';
            foreach ($list as $r) {
                $gws[] = self::gwid((int) $r['carrier_trunk_id']);
                if (($r['sort_alg'] ?? '') === 'R' || ($r['strategy'] ?? '') === 'random') {
                    $sortAlg = 'R'; // equal random pick among gateways
                }
            }
            $gwlist = implode(',', array_unique($gws));
            if ($gwlist === '') {
                continue;
            }
            // Trunk-group catch-all (*) → empty OpenSIPS prefix (default / longest-match fallback)
            $drPrefix = $prefix === '*' ? '' : $prefix;
            $desc = 'ownswitch:' . ($prefix === '*' ? 'default' : $prefix);
            if ($hasSort) {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, sort_alg, description) VALUES (?,?,?,?,NULL,?,?,?)')
                    ->execute([self::DR_GROUP, $drPrefix, '', $prio++, $gwlist, $sortAlg, $desc]);
            } else {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, description) VALUES (?,?,?,?,NULL,?,?)')
                    ->execute([self::DR_GROUP, $drPrefix, '', $prio++, $gwlist, $desc]);
            }
        }
    }

    /** @param array<string,mixed> $trunk */
    private function upsertRegistrant(array $trunk): void
    {
        $table = $this->registrantTable();
        if ($table === null) {
            throw new \RuntimeException('registrant / uac_registrant table missing — install opensips-uac-module');
        }
        $id = (int) $trunk['id'];
        $user = trim((string) ($trunk['auth_username'] ?? ''));
        $enc = (string) ($trunk['auth_secret_enc'] ?? '');
        if ($user === '' || $enc === '') {
            throw new \DomainException('Register mode needs Username and Password on the trunk.');
        }
        $pass = Crypto::decrypt($enc);
        $host = trim((string) $trunk['host']);
        $port = (int) ($trunk['port'] ?? 5060);
        if ($port <= 0) {
            $port = 5060;
        }
        $proxyHost = trim((string) ($trunk['outbound_proxy'] ?? ''));
        $registrarUri = 'sip:' . $host . ($port !== 5060 ? ':' . $port : '');
        $proxyUri = $proxyHost !== '' && strcasecmp($proxyHost, $host) !== 0
            ? ('sip:' . $proxyHost . ($port !== 5060 ? ':' . $port : ''))
            : null;
        // Contact must advertise the switch public IP (not a private NIC).
        $contactHost = $this->publicIp !== '' ? $this->publicIp : ($this->sipDomain !== '' ? $this->sipDomain : $host);
        $aor = 'sip:' . $user . '@' . $host;
        $binding = 'sip:' . $user . '@' . $contactHost;
        $uuid = self::regUuid($id);
        $cols = $this->columns($table);
        $db = $this->db();

        // OpenSIPS 3.x default schema (registrar/aor/binding_URI/username/password)
        if (in_array('registrar', $cols, true) && in_array('aor', $cols, true)) {
            $this->upsertRegistrantModern($db, $table, $cols, $uuid, $registrarUri, $proxyUri, $aor, $binding, $user, $pass);
            return;
        }

        // Legacy uac_registrant (l_uuid / auth_proxy / …) — keep IP2IP unaffected
        if (!in_array('l_uuid', $cols, true)) {
            throw new \RuntimeException("{$table} schema unexpected — need OpenSIPS 3.x registrant columns or legacy l_uuid");
        }
        $domain = $this->sipDomain !== '' ? $this->sipDomain : $host;
        $authProxy = $proxyUri ?? $registrarUri;
        // Empty realm → use WWW-Authenticate realm from vendor (forced host realm breaks many providers)
        $realm = '';
        $st = $db->prepare("SELECT id FROM `{$table}` WHERE l_uuid = ?");
        $st->execute([$uuid]);
        $fields = [
            'l_username' => $user,
            'l_domain' => $domain,
            'r_username' => $user,
            'r_domain' => $host,
            'realm' => $realm,
            'auth_username' => $user,
            'auth_password' => $pass,
            'auth_proxy' => $authProxy,
            'expires' => 3600,
        ];
        if (in_array('contact_addr', $cols, true)) {
            $fields['contact_addr'] = $contactHost . ':5060';
        }
        if ($rowId = $st->fetchColumn()) {
            $parts = [];
            $vals = [];
            foreach ($fields as $c => $v) {
                if (!in_array($c, $cols, true)) {
                    continue;
                }
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $rowId;
            $db->prepare("UPDATE `{$table}` SET " . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
        } else {
            $insert = ['l_uuid' => $uuid] + $fields;
            $insert = array_filter(
                $insert,
                static fn ($k) => $k === 'l_uuid' || in_array($k, $cols, true),
                ARRAY_FILTER_USE_KEY
            );
            $insert['l_uuid'] = $uuid;
            $names = array_keys($insert);
            $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(static fn ($c) => "`{$c}`", $names)) . ') VALUES ('
                . implode(',', array_fill(0, count($names), '?')) . ')';
            $db->prepare($sql)->execute(array_values($insert));
        }
    }

    /**
     * OpenSIPS 3.6+ registrant row (table registrant or uac_registrant with modern columns).
     *
     * @param list<string> $cols
     */
    private function upsertRegistrantModern(
        PDO $db,
        string $table,
        array $cols,
        string $uuid,
        string $registrarUri,
        ?string $proxyUri,
        string $aor,
        string $binding,
        string $user,
        string $pass,
    ): void {
        $fields = [
            'registrar' => $registrarUri,
            'aor' => $aor,
            'binding_URI' => $binding,
            'username' => $user,
            'password' => $pass,
            'expiry' => 3600,
            'state' => 0,
        ];
        if (in_array('proxy', $cols, true)) {
            $fields['proxy'] = $proxyUri;
        }
        if (in_array('third_party_registrant', $cols, true)) {
            $fields['third_party_registrant'] = null;
        }
        if (in_array('binding_params', $cols, true)) {
            $fields['binding_params'] = null;
        }
        if (in_array('forced_socket', $cols, true)) {
            $fields['forced_socket'] = null;
        }
        $idCol = in_array('l_uuid', $cols, true) ? 'l_uuid' : (in_array('description', $cols, true) ? 'description' : null);

        $rowId = null;
        if ($idCol !== null) {
            $st = $db->prepare("SELECT id FROM `{$table}` WHERE `{$idCol}` = ?");
            $st->execute([$uuid]);
            $rowId = $st->fetchColumn() ?: null;
        }
        if ($rowId === null) {
            $st = $db->prepare("SELECT id FROM `{$table}` WHERE aor = ? AND registrar = ? LIMIT 1");
            $st->execute([$aor, $registrarUri]);
            $rowId = $st->fetchColumn() ?: null;
        }
        if ($idCol !== null) {
            $fields[$idCol] = $uuid;
        }

        if ($rowId) {
            $parts = [];
            $vals = [];
            foreach ($fields as $c => $v) {
                if (!in_array($c, $cols, true)) {
                    continue;
                }
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $rowId;
            $db->prepare("UPDATE `{$table}` SET " . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
            return;
        }

        $insert = [];
        foreach ($fields as $c => $v) {
            if (in_array($c, $cols, true)) {
                $insert[$c] = $v;
            }
        }
        $names = array_keys($insert);
        $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(static fn ($c) => "`{$c}`", $names)) . ') VALUES ('
            . implode(',', array_fill(0, count($names), '?')) . ')';
        $db->prepare($sql)->execute(array_values($insert));
    }

    private function removeRegistrant(int $trunkId): void
    {
        $table = $this->registrantTable();
        if ($table === null) {
            return;
        }
        $uuid = self::regUuid($trunkId);
        $cols = $this->columns($table);
        $db = $this->db();
        if (in_array('l_uuid', $cols, true)) {
            $db->prepare("DELETE FROM `{$table}` WHERE l_uuid = ?")->execute([$uuid]);
        }
        if (in_array('description', $cols, true)) {
            $db->prepare("DELETE FROM `{$table}` WHERE description = ?")->execute([$uuid]);
        }
    }

    /** Prefer OpenSIPS 3.x `registrant`, then legacy `uac_registrant`. */
    private function registrantTable(): ?string
    {
        if ($this->tableExists('registrant')) {
            $cols = $this->columns('registrant');
            if (in_array('registrar', $cols, true) || in_array('l_uuid', $cols, true)) {
                return 'registrant';
            }
        }
        if ($this->tableExists('uac_registrant')) {
            return 'uac_registrant';
        }
        return null;
    }

    /**
     * Map signalling source IP → registered SIP username (ViciDial / dynamic REGISTER).
     * Used when ACC src_user is empty or is CallerID digits.
     */
    public function usernameBySourceIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || !$this->enabled()) {
            return null;
        }
        $hits = [];
        foreach ($this->registeredContacts() as $user => $info) {
            $blob = strtolower(($info['received'] ?? '') . ' ' . ($info['contact'] ?? ''));
            if ($blob === '') {
                continue;
            }
            if (preg_match('#(?:sip:)?(?:[^@;>\s]+@)?' . preg_quote(strtolower($ip), '#') . '(?:[:;>\s]|$)#', $blob)) {
                $hits[strtolower((string) $user)] = true;
            }
        }
        if ($this->tableExists('address')) {
            try {
                $st = $this->db()->prepare(
                    'SELECT context_info FROM address WHERE grp = ? AND ip = ? LIMIT 4'
                );
                $st->execute([self::GRP_CUSTOMER, $ip]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ctx) {
                    $ctx = trim((string) $ctx);
                    if (preg_match('#^sip:([^@;>\s]+)$#i', $ctx, $m)) {
                        $hits[strtolower($m[1])] = true;
                    }
                }
            } catch (\Throwable) {
            }
        }
        $users = array_keys($hits);
        return count($users) === 1 ? (string) $users[0] : null;
    }

    public static function gwid(int $trunkId): string
    {
        return 'T' . $trunkId;
    }

    public static function regUuid(int $trunkId): string
    {
        return 'trunk-' . $trunkId;
    }

    public function mi(string $command): bool
    {
        $r = $this->miCall($command);
        return $r !== null && !isset($r['error']);
    }

    /**
     * JSON-RPC MI call returning decoded body (null on transport failure).
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function miCall(string $method, array $params = []): ?array
    {
        $this->lastMiReachable = false;
        if (!is_string($method) || !preg_match('/^[a-zA-Z0-9_]+$/', $method)) {
            return null;
        }
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'id' => 1];
        if ($params !== []) {
            $body['params'] = $params;
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $urls = [];
        if ($this->miUrl !== '') {
            $urls[] = $this->miUrl;
        }
        foreach (['http://127.0.0.1:8888/mi', 'http://127.0.0.1:8888/'] as $fallback) {
            if (!in_array($fallback, $urls, true)) {
                $urls[] = $fallback;
            }
        }
        foreach ($urls as $url) {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 4,
                'ignore_errors' => true,
                'content' => $payload,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false || $raw === '') {
                continue;
            }
            $r = json_decode($raw, true);
            if (!is_array($r)) {
                continue;
            }
            $this->lastMiReachable = true;
            return $r;
        }
        // opensips-cli FIFO fallback — including param'd calls (dlg_end_dlg <id>)
        $cliArgs = escapeshellarg($method);
        if ($params !== []) {
            if (array_is_list($params)) {
                foreach ($params as $p) {
                    if (!is_scalar($p)) {
                        continue;
                    }
                    $cliArgs .= ' ' . escapeshellarg((string) $p);
                }
            } else {
                // opensips-cli 3.6: dlg_end_dlg dialog_id=3261:1097
                foreach ($params as $k => $p) {
                    if (!is_scalar($p) || !is_string($k)) {
                        continue;
                    }
                    $cliArgs .= ' ' . escapeshellarg($k . '=' . (string) $p);
                }
            }
        }
        $cli = trim((string) @shell_exec('opensips-cli -x mi ' . $cliArgs . ' 2>/dev/null'));
        if ($cli !== '') {
            $this->lastMiReachable = true;
            $decoded = json_decode($cli, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (preg_match('/error|fail|not found|Invalid|Unknown/i', $cli)) {
                return ['error' => ['message' => $cli]];
            }
            return ['result' => $cli];
        }
        return null;
    }

    /**
     * Live dialogs: MI is source of truth (includes early/ringing).
     * DB fallback only when MI is unreachable — never merge DB zombies into MI.
     *
     * @return list<array<string,mixed>>
     */
    public function listDialogs(): array
    {
        $this->miDialogsEmptyConfirmed = false;
        $fromMi = $this->listDialogsMi();
        if ($this->lastMiReachable) {
            $liveIds = [];
            foreach ($fromMi as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $cid = (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? $d['CallId'] ?? $d['ci'] ?? '');
                if ($cid !== '') {
                    $liveIds[] = $cid;
                }
            }
            // Only sync-delete DB when we have a parsed live set OR confirmed empty dlg_list
            if ($liveIds !== [] || $this->miDialogsEmptyConfirmed) {
                $this->purgeStaleDialogDbRows($liveIds);
            }
            return $fromMi;
        }
        return $this->listDialogsDb();
    }

    /** @return list<array<string,mixed>> */
    private function listDialogsMi(): array
    {
        // Prefer dlg_list_ctx so os_src / os_dst dlg_vals are available for live display
        foreach (['dlg_list_ctx', 'dlg_list'] as $method) {
            $r = $this->miCall($method);
            if ($r === null) {
                continue;
            }
            if (isset($r['error'])) {
                // Transport worked; method missing — try next
                continue;
            }
            $result = $r['result'] ?? $r['Result'] ?? null;
            if ($result === null && isset($r['Dialogs'])) {
                $result = $r;
            }
            if ($result === null || $result === '' || $result === []) {
                $this->miDialogsEmptyConfirmed = true;
                return [];
            }
            if (!is_array($result)) {
                continue;
            }
            // {"Dialogs":[]} / {"dialogs":[]}
            foreach (['Dialogs', 'dialogs', 'Dialog', 'dialog'] as $k) {
                if (array_key_exists($k, $result) && $result[$k] === []) {
                    $this->miDialogsEmptyConfirmed = true;
                    return [];
                }
            }
            $list = $this->extractDialogList($result);
            if ($list !== []) {
                return $list;
            }
            // Unparsed non-empty payload — do not claim idle / wipe DB
        }
        return [];
    }

    /**
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    private function extractDialogList(array $result): array
    {
        $found = [];
        $seen = [];
        $walk = static function ($node) use (&$walk, &$found, &$seen): void {
            if (!is_array($node)) {
                return;
            }
            $cid = (string) ($node['callid'] ?? $node['Call-ID'] ?? $node['call_id']
                ?? $node['CallId'] ?? $node['ci'] ?? '');
            $hasUri = isset($node['from_uri']) || isset($node['caller']) || isset($node['From'])
                || isset($node['to_uri']) || isset($node['callee']) || isset($node['fromuri'])
                || isset($node['touri']) || isset($node['from']) || isset($node['to']);
            $hasState = isset($node['state']) || isset($node['State']) || isset($node['dlg_state'])
                || isset($node['dlgstate']) || isset($node['status']);
            // Accept any dialog-shaped node with a Call-ID (early/ringing may lack full URIs in some builds)
            if ($cid !== '' && ($hasUri || $hasState || isset($node['start_time']) || isset($node['values']))) {
                if (!isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $found[] = $node;
                }
                return;
            }
            foreach (['Dialogs', 'Dialog', 'dialogs', 'dialog', 'result', 'Result', 'Values', 'values'] as $k) {
                if (isset($node[$k]) && is_array($node[$k]) && $k !== 'Values' && $k !== 'values') {
                    $walk($node[$k]);
                }
            }
            if (array_is_list($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
                return;
            }
            foreach ($node as $k => $child) {
                if (is_array($child) && !in_array((string) $k, ['Dialogs', 'Dialog', 'dialogs', 'dialog', 'result', 'Result', 'values', 'Values'], true)) {
                    $walk($child);
                }
            }
        };
        $walk($result);
        if ($found !== []) {
            return $found;
        }
        // Legacy shapes
        foreach (['Dialogs', 'Dialog', 'dialogs', 'dialog'] as $k) {
            if (isset($result[$k]) && is_array($result[$k])) {
                $v = $result[$k];
                if ($v !== [] && !array_is_list($v) && (isset($v['callid']) || isset($v['Call-ID']))) {
                    return [$v];
                }
                if (array_is_list($v)) {
                    return array_values(array_filter($v, 'is_array'));
                }
            }
        }
        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function listDialogsDb(): array
    {
        try {
            $db = $this->db();
            if (!$this->tableExists('dialog')) {
                return [];
            }
            $cols = $this->columns('dialog');
            if (!in_array('callid', $cols, true)) {
                return [];
            }
            $select = ['callid'];
            foreach (['from_uri', 'to_uri', 'from_tag', 'to_tag', 'dlg_id', 'h_entry', 'h_id', 'hash_entry', 'hash_id'] as $c) {
                if (in_array($c, $cols, true)) {
                    $select[] = $c;
                }
            }
            $stateCol = in_array('state', $cols, true) ? 'state' : (in_array('dlg_state', $cols, true) ? 'dlg_state' : null);
            if ($stateCol !== null) {
                $select[] = "`{$stateCol}` AS state";
            }
            $startCol = in_array('start_time', $cols, true) ? 'start_time' : null;
            if ($startCol !== null) {
                $select[] = "`{$startCol}` AS start_time";
            }
            // Exclude deleted (5) — those are zombies left after BYE / timeout
            $where = '';
            if ($stateCol !== null) {
                $where = " WHERE `{$stateCol}` IS NOT NULL AND `{$stateCol}` <> 5 AND `{$stateCol}` <> '5'";
            }
            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM dialog' . $where
                . ' ORDER BY ' . ($startCol ? "`{$startCol}`" : 'callid') . ' DESC LIMIT 500';
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] listDialogsDb: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * When MI reports live Call-IDs, drop dialog DB rows that are no longer live (zombies).
     *
     * @param list<string> $liveCallIds Empty = purge all dialog table rows (MI says idle).
     */
    public function purgeStaleDialogDbRows(array $liveCallIds): int
    {
        if (!$this->enabled() || !$this->tableExists('dialog')) {
            return 0;
        }
        try {
            $db = $this->db();
            if ($liveCallIds === []) {
                $n = $db->exec('DELETE FROM dialog');
                if ($this->tableExists('dialog_vars')) {
                    $db->exec('DELETE FROM dialog_vars');
                }
                return is_int($n) ? $n : 0;
            }
            // Keep only live callids — delete the rest
            $placeholders = implode(',', array_fill(0, count($liveCallIds), '?'));
            $st = $db->prepare("DELETE FROM dialog WHERE callid NOT IN ({$placeholders})");
            $st->execute(array_values($liveCallIds));
            return $st->rowCount();
        } catch (\Throwable $e) {
            error_log('[ownswitch] purgeStaleDialogDbRows: ' . $e->getMessage());
            return 0;
        }
    }

    /** Delete one dialog row (portal hangup fallback when MI cannot terminate in-memory dlg). */
    public function purgeDialogByCallId(string $callId): bool
    {
        $callId = trim($callId);
        if ($callId === '' || !$this->enabled() || !$this->tableExists('dialog')) {
            return false;
        }
        try {
            $st = $this->db()->prepare('DELETE FROM dialog WHERE callid = ?');
            $st->execute([$callId]);
            return $st->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sync SIP peer profile into OpenSIPS for dialplan / media lookups.
     *
     * @param array<string,mixed>|null $account optional codecs, dtmf, nat, etc.
     */
    public function syncSipPeerProfile(string $username, string $mediaPolicy, int $maxChannels, ?array $account = null): void
    {
        $db = $this->db();
        // MySQL 8 reserves QUALIFY — always backtick that identifier.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ownswitch_sip (
                username VARCHAR(64) NOT NULL PRIMARY KEY,
                media_policy VARCHAR(16) NOT NULL DEFAULT \'relay\',
                max_channels INT NOT NULL DEFAULT 2,
                sip_port INT UNSIGNED NOT NULL DEFAULT 5060,
                codecs VARCHAR(190) NULL,
                dtmfmode VARCHAR(32) NULL,
                dtmf_pass VARCHAR(8) NULL,
                nat VARCHAR(64) NULL,
                insecure VARCHAR(64) NULL,
                peer_type VARCHAR(16) NULL,
                `qualify` VARCHAR(8) NULL,
                allowtransfer VARCHAR(8) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach ([
            ['sip_port', 'INT UNSIGNED NOT NULL DEFAULT 5060'],
            ['codecs', 'VARCHAR(190) NULL'],
            ['dtmfmode', 'VARCHAR(32) NULL'],
            ['dtmf_pass', 'VARCHAR(8) NULL'],
            ['nat', 'VARCHAR(64) NULL'],
            ['insecure', 'VARCHAR(64) NULL'],
            ['peer_type', 'VARCHAR(16) NULL'],
            ['qualify', 'VARCHAR(8) NULL'],
            ['allowtransfer', 'VARCHAR(8) NULL'],
            ['max_cps', 'INT NOT NULL DEFAULT 1'],
            ['max_call_duration', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ] as [$col, $def]) {
            try {
                $db->exec("ALTER TABLE ownswitch_sip ADD COLUMN `{$col}` {$def}");
            } catch (\Throwable) {
                // column already exists
            }
        }
        try {
            $db->exec('ALTER TABLE ownswitch_sip MODIFY max_channels INT NOT NULL DEFAULT 2');
        } catch (\Throwable) {
        }

        $port = (int) ($account['sip_port'] ?? 5060);
        if ($port < 0 || $port > 65535) {
            $port = 5060;
        }
        $maxCps = (int) ($account['max_cps'] ?? 1);
        if ($maxCps < -1) {
            $maxCps = -1;
        }
        if ($maxCps === 0) {
            $maxCps = 1;
        }
        $maxDur = (int) ($account['max_call_duration'] ?? 0);
        if ($maxDur < 0) {
            $maxDur = 0;
        }
        if ($maxDur > 604800) {
            $maxDur = 604800;
        }
        $st = $db->prepare(
            'INSERT INTO ownswitch_sip
                (username, media_policy, max_channels, max_cps, max_call_duration, sip_port, codecs, dtmfmode, dtmf_pass, nat, insecure, peer_type, `qualify`, allowtransfer)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                media_policy = VALUES(media_policy),
                max_channels = VALUES(max_channels),
                max_cps = VALUES(max_cps),
                max_call_duration = VALUES(max_call_duration),
                sip_port = VALUES(sip_port),
                codecs = VALUES(codecs),
                dtmfmode = VALUES(dtmfmode),
                dtmf_pass = VALUES(dtmf_pass),
                nat = VALUES(nat),
                insecure = VALUES(insecure),
                peer_type = VALUES(peer_type),
                `qualify` = VALUES(`qualify`),
                allowtransfer = VALUES(allowtransfer)'
        );
        $st->execute([
            $username,
            $mediaPolicy,
            (int) $maxChannels,
            $maxCps,
            $maxDur,
            $port,
            isset($account['codecs']) ? mb_substr((string) $account['codecs'], 0, 190) : null,
            isset($account['dtmfmode']) ? mb_substr((string) $account['dtmfmode'], 0, 32) : null,
            isset($account['dtmf_pass']) ? mb_substr((string) $account['dtmf_pass'], 0, 8) : 'yes',
            isset($account['nat']) ? mb_substr((string) $account['nat'], 0, 64) : null,
            isset($account['insecure']) ? mb_substr((string) $account['insecure'], 0, 64) : null,
            isset($account['peer_type']) ? mb_substr((string) $account['peer_type'], 0, 16) : null,
            isset($account['qualify']) ? mb_substr((string) $account['qualify'], 0, 8) : null,
            isset($account['allowtransfer']) ? mb_substr((string) $account['allowtransfer'], 0, 8) : null,
        ]);
    }

    public function removeSipPeerProfile(string $username): void
    {
        if ($this->tableExists('ownswitch_sip')) {
            $this->db()->prepare('DELETE FROM ownswitch_sip WHERE username = ?')->execute([$username]);
        }
    }

    /** Map DID digits → SIP AOR via OpenSIPS dbaliases (alias_db module). */
    public function syncDidAlias(string $didNorm, string $sipUsername, string $domain): void
    {
        $db = $this->db();
        $this->ensureDbAliases();
        $aliasUser = preg_replace('/\D+/', '', $didNorm) ?? '';
        if ($aliasUser === '' || $sipUsername === '') {
            throw new \InvalidArgumentException('DID alias requires digits and SIP username');
        }
        $db->prepare('DELETE FROM dbaliases WHERE alias_username = ?')->execute([$aliasUser]);
        // Also store +prefixed form if different
        $db->prepare('DELETE FROM dbaliases WHERE alias_username = ?')->execute(['+' . $aliasUser]);
        $ins = $db->prepare('INSERT INTO dbaliases (alias_username, alias_domain, username, domain) VALUES (?,?,?,?)');
        $ins->execute([$aliasUser, $domain, $sipUsername, $domain]);
        $ins->execute(['+' . $aliasUser, $domain, $sipUsername, $domain]);
        $this->mi('alias_db_reload') || $this->mi('domain_reload');
    }

    public function removeDidAlias(string $didNorm): void
    {
        if (!$this->tableExists('dbaliases')) {
            return;
        }
        $aliasUser = preg_replace('/\D+/', '', $didNorm) ?? '';
        if ($aliasUser === '') {
            return;
        }
        $this->db()->prepare('DELETE FROM dbaliases WHERE alias_username IN (?,?)')->execute([$aliasUser, '+' . $aliasUser]);
        $this->mi('alias_db_reload');
    }

    /**
     * Hang up a live dialog via MI dlg_end_dlg (sends BYE both ways).
     * OpenSIPS 3.x param is dialog_id = Call-ID or internal ID from dlg_list (e.g. "3261:1097").
     *
     * @return array{ok:bool,detail:string}
     */
    public function endDialog(string $callId, ?string $fromTag = null, ?string $dialogId = null): array
    {
        if ($callId === '' && ($dialogId === null || $dialogId === '')) {
            return ['ok' => false, 'detail' => 'Missing Call-ID'];
        }
        // Resolve internal dialog ID from live MI list when possible (most reliable)
        $ids = [];
        if ($dialogId !== null && trim($dialogId) !== '') {
            $ids[] = trim($dialogId);
        }
        $resolved = $this->resolveDialogIdsForHangup($callId, $fromTag);
        foreach ($resolved as $id) {
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($callId !== '' && !in_array($callId, $ids, true)) {
            $ids[] = $callId;
        }

        // DB dlg_id (64-bit) is the reliable hangup key when MI JSON omits "ID"
        if ($callId !== '') {
            foreach ($this->dialogHashIdsFromDb($callId) as $dbId) {
                if (!in_array($dbId, $ids, true)) {
                    array_unshift($ids, $dbId);
                }
            }
        }

        // OpenSIPS 3.6 accepts the dlg_list DID (6ae.4b38d013) or the SIP Call-ID.
        // Colon hash ids are not that DID — try them last, never instead of it.
        $ordered = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '' || preg_match('/[\r\n\x00]/', $id) || isset($ordered[$id])) {
                continue;
            }
            $ordered[$id] = str_contains($id, '.') ? 0 : (str_contains($id, ':') ? 2 : 1);
        }
        asort($ordered);

        $last = 'no MI response';
        $miOk = false;
        foreach (array_keys($ordered) as $id) {
            $attempts = [
                ['dialog_id' => $id],
                [$id],
            ];
            foreach ($attempts as $params) {
                $r = $this->miCall('dlg_end_dlg', $params);
                if ($r === null) {
                    $r = $this->miFifoJson('dlg_end_dlg', is_array($params) && !array_is_list($params) ? $params : ['dialog_id' => $id]);
                }
                if ($r !== null && !isset($r['error'])) {
                    $miOk = true;
                    $last = 'dlg_end_dlg ok id=' . $id;
                    break 2;
                }
                $last = is_array($r['error'] ?? null)
                    ? (string) ($r['error']['message'] ?? json_encode($r['error']))
                    : (string) ($r['error'] ?? ($r === null ? 'MI unreachable' : 'no response'));
            }
        }
        if (!$miOk && $callId !== '') {
            $seq = $this->miCall('dlg_send_sequential', ['callid' => $callId, 'method' => 'BYE']);
            if ($seq !== null && !isset($seq['error'])) {
                $miOk = true;
                $last = 'dlg_send_sequential BYE';
            } elseif ($last === 'no MI response') {
                $last = 'dlg_end_dlg did not accept Call-ID or dialog DID';
            }
        }

        if ($miOk) {
            if ($callId !== '') {
                $this->purgeDialogByCallId($callId);
            }
            return ['ok' => true, 'detail' => 'Hangup sent (BYE both legs).'];
        }

        // Do NOT purge DB on failure — that only hides the call while media continues
        if ($callId !== '' && !$this->dialogStillLive($callId, $fromTag)) {
            $this->purgeDialogByCallId($callId);
            return ['ok' => true, 'detail' => 'Call no longer in OpenSIPS (already ended).'];
        }

        return [
            'ok' => false,
            'detail' => 'Could not terminate call via OpenSIPS MI: ' . $last
                . ' — ensure opensips is active and mi_http listens on 127.0.0.1:8888'
                . ' (sudo bash /opt/ownswitch-src/deploy/fix-opensips-activating.sh)',
        ];
    }

    /**
     * OpenSIPS dialog.dlg_id is (h_entry << 32) | h_id. MI wants "entry:id".
     *
     * @return list<string>
     */
    private function dialogHashIdsFromDb(string $callId): array
    {
        $callId = trim($callId);
        if ($callId === '' || !$this->tableExists('dialog')) {
            return [];
        }
        try {
            $cols = $this->columns('dialog');
            if (!in_array('dlg_id', $cols, true) || !in_array('callid', $cols, true)) {
                return [];
            }
            $st = $this->db()->prepare('SELECT dlg_id FROM dialog WHERE callid = ? LIMIT 5');
            $st->execute([$callId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
                $id = self::hashIdFromDlgId($raw);
                if ($id !== '') {
                    $out[] = $id;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[ownswitch] dialogHashIdsFromDb: ' . $e->getMessage());
            return [];
        }
    }

    /** @param mixed $raw */
    public static function hashIdFromDlgId(mixed $raw): string
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return '';
        }
        $s = (string) $raw;
        if (function_exists('bcmod') && function_exists('bcdiv')) {
            $entry = bcdiv($s, '4294967296', 0);
            $hid = bcmod($s, '4294967296');
            if ($entry !== '' && $hid !== '') {
                return $entry . ':' . $hid;
            }
        }
        $n = (int) $s;
        if ($n <= 0) {
            return '';
        }
        return (($n >> 32) & 0xFFFFFFFF) . ':' . ($n & 0xFFFFFFFF);
    }

    /**
     * JSON-RPC write straight to the MI fifo (works when HTTP :8888 is down).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function miFifoJson(string $method, array $params): ?array
    {
        $fifos = [];
        if ($this->miFifo !== '') {
            $fifos[] = $this->miFifo;
        }
        foreach (['/run/opensips/opensips.fifo', '/tmp/opensips_fifo'] as $p) {
            if (!in_array($p, $fifos, true)) {
                $fifos[] = $p;
            }
        }
        foreach ($fifos as $fifo) {
            if (!is_file($fifo) && !file_exists($fifo)) {
                continue;
            }
            $reply = sys_get_temp_dir() . '/os_mi_' . getmypid() . '_' . bin2hex(random_bytes(3));
            @unlink($reply);
            if (function_exists('posix_mkfifo')) {
                @posix_mkfifo($reply, 0666);
            }
            $req = json_encode([
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
                'reply_fifo' => $reply,
            ], JSON_THROW_ON_ERROR);
            $fp = @fopen($fifo, 'w');
            if ($fp === false) {
                @unlink($reply);
                continue;
            }
            stream_set_timeout($fp, 2);
            $written = @fwrite($fp, $req . "\n");
            fclose($fp);
            if ($written === false || $written < 1) {
                @unlink($reply);
                continue;
            }
            $this->lastMiReachable = true;
            $out = '';
            $rf = @fopen($reply, 'r');
            if ($rf !== false) {
                stream_set_timeout($rf, 2);
                $out = (string) stream_get_contents($rf);
                fclose($rf);
            }
            @unlink($reply);
            if ($out !== '') {
                $decoded = json_decode($out, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * Collect Call-ID + internal dlg IDs for hangup.
     *
     * @return list<string>
     */
    private function resolveDialogIdsForHangup(string $callId, ?string $fromTag): array
    {
        $out = [];
        foreach ($this->listDialogs() as $d) {
            if (!is_array($d)) {
                continue;
            }
            $cid = (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? $d['CallId'] ?? $d['ci'] ?? '');
            if ($callId !== '' && $cid !== '' && strcasecmp($cid, $callId) !== 0) {
                continue;
            }
            if ($callId === '' && $cid === '') {
                continue;
            }
            if ($fromTag !== null && $fromTag !== '') {
                $ft = (string) ($d['from_tag'] ?? $d['caller_tag'] ?? $d['ftag'] ?? '');
                if ($ft !== '' && strcasecmp($ft, $fromTag) !== 0) {
                    continue;
                }
            }
            $did = self::extractDialogId($d);
            if ($did !== '') {
                $out[] = $did;
            }
            if ($cid !== '') {
                $out[] = $cid;
            }
        }
        return array_values(array_unique($out));
    }

    private function dialogStillLive(string $callId, ?string $fromTag): bool
    {
        foreach ($this->listDialogs() as $d) {
            if (!is_array($d)) {
                continue;
            }
            $cid = (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? $d['CallId'] ?? $d['ci'] ?? '');
            if (strcasecmp($cid, $callId) !== 0) {
                continue;
            }
            if ($fromTag !== null && $fromTag !== '') {
                $ft = (string) ($d['from_tag'] ?? $d['caller_tag'] ?? $d['ftag'] ?? '');
                if ($ft !== '' && strcasecmp($ft, $fromTag) !== 0) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $d */
    public static function extractDialogId(array $d): string
    {
        // OpenSIPS 3.6 dlg_list "ID" is the DID, e.g. 6ae.4b38d013 — that is what dlg_end_dlg wants.
        foreach (['ID', 'id', 'DID', 'did', 'dialog_id', 'dlg_id'] as $k) {
            if (!isset($d[$k]) || is_array($d[$k])) {
                continue;
            }
            $v = trim((string) $d[$k]);
            if ($v !== '' && preg_match('/^[0-9a-fA-F]+\.[0-9a-fA-F]+$/', $v)) {
                return $v;
            }
        }
        $entry = $d['h_entry'] ?? $d['hash_entry'] ?? $d['entry'] ?? null;
        $hid = $d['h_id'] ?? $d['hash_id'] ?? null;
        if ($entry !== null && $hid !== null && is_numeric($entry) && is_numeric($hid)) {
            return ((int) $entry) . ':' . ((int) $hid);
        }
        foreach (['ID', 'id', 'dialog_id', 'dialog', 'dlg_id', 'hash', 'did'] as $k) {
            if (!isset($d[$k])) {
                continue;
            }
            $v = $d[$k];
            if (is_array($v)) {
                $nested = self::extractDialogId($v);
                if ($nested !== '') {
                    return $nested;
                }
                continue;
            }
            if (is_int($v) || is_float($v)) {
                return (string) (int) $v;
            }
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            // OpenSIPS: "3261:1097" or "6ae.4b38d013"
            if ($v !== '' && preg_match('/^[0-9a-fA-F]+[:.][0-9a-fA-F]+$/', $v)) {
                return $v;
            }
            if ($v !== '' && ctype_digit($v)) {
                return $v;
            }
            if (preg_match('/(?:h_entry|hash)[=:\s]+(\d+)[^\d]+(?:h_id|id)[=:\s]+(\d+)/i', $v, $m)) {
                return $m[1] . ':' . $m[2];
            }
            // "ID: 3261:1097" / "dialog=3261:1097"
            if (preg_match('/(\d+:\d+)/', $v, $m)) {
                return $m[1];
            }
        }
        if (isset($d['dialog']) && is_array($d['dialog'])) {
            return self::extractDialogId($d['dialog']);
        }
        return '';
    }

    private function ensureDbAliases(): void
    {
        if ($this->tableExists('dbaliases')) {
            return;
        }
        $this->db()->exec(
            'CREATE TABLE IF NOT EXISTS dbaliases (
                id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
                alias_username VARCHAR(64) NOT NULL DEFAULT \'\',
                alias_domain VARCHAR(64) NOT NULL DEFAULT \'\',
                username VARCHAR(64) NOT NULL DEFAULT \'\',
                domain VARCHAR(64) NOT NULL DEFAULT \'\',
                KEY alias_user_idx (alias_username, alias_domain),
                KEY user_idx (username, domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function ensureSubscriberExtraColumns(): void
    {
        static $done = false;
        if ($done || !$this->tableExists('subscriber')) {
            return;
        }
        foreach ([
            'media_policy VARCHAR(16) NOT NULL DEFAULT \'relay\'',
            'dtmf_pass VARCHAR(8) NOT NULL DEFAULT \'yes\'',
            'cid_mode VARCHAR(16) NOT NULL DEFAULT \'passthrough\'',
            'cid_min_len INT UNSIGNED NOT NULL DEFAULT 0',
            'cid_max_len INT UNSIGNED NOT NULL DEFAULT 0',
            'allow_calls TINYINT(1) NOT NULL DEFAULT 1',
            'dst_format VARCHAR(16) NOT NULL DEFAULT \'as_is\'',
            'dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0',
        ] as $colDef) {
            $col = explode(' ', $colDef, 2)[0];
            $cols = $this->columns('subscriber');
            if (in_array($col, $cols, true)) {
                continue;
            }
            try {
                $this->db()->exec("ALTER TABLE subscriber ADD COLUMN {$colDef}");
                $this->columns('subscriber', true);
            } catch (\Throwable) {
                // concurrent alter or no DDL rights
            }
        }
        $done = true;
    }

    /** @deprecated use ensureSubscriberExtraColumns */
    private function ensureSubscriberMediaColumn(): void
    {
        $this->ensureSubscriberExtraColumns();
    }

    /**
     * Registered contacts keyed by username (best contact prefers `received` for NAT).
     * Prefers live MI usrloc dump (memory); falls back to SQL location table.
     *
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    public function registeredContacts(): array
    {
        $fromMi = $this->registeredContactsFromMi();
        $fromSql = $this->registeredContactsFromSql();
        // Union of SQL write-through + live MI — either source with a future expiry counts.
        // (Asterisk/ViciDial often present only in one view briefly after REGISTER.)
        $out = $fromSql;
        foreach ($fromMi as $u => $info) {
            if (!isset($out[$u]) || (int) ($info['expires'] ?? 0) > (int) ($out[$u]['expires'] ?? 0)) {
                $prevMs = (int) ($out[$u]['latency_ms'] ?? 0);
                $out[$u] = $info;
                if ((int) ($out[$u]['latency_ms'] ?? 0) <= 0 && $prevMs > 0) {
                    $out[$u]['latency_ms'] = $prevMs;
                }
            } elseif ((int) ($info['latency_ms'] ?? 0) > 0) {
                $out[$u]['latency_ms'] = (int) $info['latency_ms'];
            }
        }
        return $out;
    }

    /** OpenSIPS contact ping latency is microseconds. */
    public static function latencyToMs(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return 0;
        }
        return max(1, (int) round($n / 1000));
    }

    /** Drop a dead/stale registration so the portal stops showing green. */
    public function purgeRegistration(string $username): void
    {
        $username = trim($username);
        if ($username === '' || !$this->enabled()) {
            return;
        }
        try {
            if ($this->tableExists('location')) {
                $this->db()->prepare('DELETE FROM location WHERE username = ?')->execute([$username]);
            }
        } catch (\Throwable) {
        }
        if ($this->hasMi()) {
            $this->miCall('ul_rm', ['AOR' => $username]);
            $this->miCall('ul_rm', [$username]);
        }
    }

    /**
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    private function registeredContactsFromMi(): array
    {
        if (!$this->hasMi()) {
            return [];
        }
        foreach (['ul_dump', 'reg_list', 'usrloc:dump'] as $method) {
            $r = $this->miCall($method);
            if ($r === null || isset($r['error'])) {
                continue;
            }
            $out = [];
            $walk = static function ($node, ?string $aor) use (&$walk, &$out): void {
                if (!is_array($node)) {
                    return;
                }
                $user = $node['username'] ?? $node['AOR'] ?? $node['aor'] ?? $node['User'] ?? null;
                if (is_string($user) && $user !== '') {
                    if (str_contains($user, '@')) {
                        $user = explode('@', $user, 2)[0];
                    }
                    $aor = strtolower($user);
                }
                $contact = $node['Contact'] ?? $node['contact'] ?? null;
                $received = $node['Received'] ?? $node['received'] ?? '';
                $expires = $node['Expires'] ?? $node['expires'] ?? $node['Expiry'] ?? null;
                $latency = $node['latency'] ?? $node['Latency'] ?? null;
                if (is_array($contact) && !array_is_list($contact)) {
                    $received = (string) ($contact['received'] ?? $contact['Received'] ?? $received);
                    $expires = $contact['Expires'] ?? $contact['expires'] ?? $expires;
                    $latency = $contact['latency'] ?? $contact['Latency'] ?? $latency;
                    $contact = (string) ($contact['Contact'] ?? $contact['contact'] ?? $contact['address'] ?? '');
                }
                if (is_string($contact) && $contact !== '' && is_string($aor) && $aor !== '') {
                    $expTs = null;
                    if (is_numeric($expires)) {
                        $expTs = (int) $expires;
                        if ($expTs > 0 && $expTs < 86400 * 10) {
                            $expTs = time() + $expTs;
                        }
                    } elseif (is_string($expires) && $expires !== '') {
                        $ts = strtotime($expires);
                        if ($ts !== false) {
                            $expTs = $ts;
                        }
                    }
                    $ms = self::latencyToMs($latency);
                    if ($expTs !== null && $expTs > time() && !isset($out[$aor])) {
                        $out[$aor] = [
                            'contact' => $contact,
                            'received' => is_string($received) ? $received : '',
                            'expires' => $expTs,
                            'latency_ms' => $ms,
                        ];
                    } elseif (isset($out[$aor]) && $ms > (int) ($out[$aor]['latency_ms'] ?? 0)) {
                        $out[$aor]['latency_ms'] = $ms;
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $walk($v, $aor);
                    }
                }
            };
            $walk($r['result'] ?? $r, null);
            if ($out !== []) {
                return $out;
            }
        }
        return [];
    }

    /**
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    private function registeredContactsFromSql(): array
    {
        if (!$this->enabled() || !$this->tableExists('location')) {
            return [];
        }
        $cols = $this->columns('location');
        $need = ['username', 'contact'];
        foreach ($need as $c) {
            if (!in_array($c, $cols, true)) {
                return [];
            }
        }
        $hasRecv = in_array('received', $cols, true);
        $hasExp = in_array('expires', $cols, true);
        $hasLat = in_array('latency', $cols, true);
        // OpenSIPS 3.6 location.expires is INT unix timestamp (not DATETIME).
        // Only live contacts: expires must be in the future. Never fall back to unfiltered SELECT.
        $sql = 'SELECT username, contact'
            . ($hasRecv ? ', received' : '')
            . ($hasExp ? ', expires' : '')
            . ($hasLat ? ', latency' : '')
            . ' FROM location';
        if ($hasExp) {
            $sql .= ' WHERE expires IS NOT NULL AND expires > UNIX_TIMESTAMP()';
        } else {
            // No expires column → cannot prove registration is live
            return [];
        }
        $out = [];
        try {
            $rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $now = time();
        foreach ($rows as $row) {
            $u = strtolower((string) ($row['username'] ?? ''));
            if ($u === '' || isset($out[$u])) {
                continue;
            }
            $contact = (string) ($row['contact'] ?? '');
            if ($contact === '') {
                continue;
            }
            $expires = $this->parseLocationExpires($row['expires'] ?? null);
            if ($expires === null || $expires <= $now) {
                continue;
            }
            $out[$u] = [
                'contact' => $contact,
                'received' => $hasRecv ? (string) ($row['received'] ?? '') : '',
                'expires' => $expires,
                'latency_ms' => $hasLat ? self::latencyToMs($row['latency'] ?? null) : 0,
            ];
        }
        return $out;
    }

    /** OpenSIPS DB stores absolute unix INT (or DATETIME on some schemas). */
    private function parseLocationExpires(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            $n = (int) $raw;
            return $n > 0 ? $n : null;
        }
        if (is_string($raw)) {
            $ts = strtotime($raw);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    /**
     * Gateway probe state from MI dr_gw_status (gwid => Active|Inactive|…).
     *
     * @return array<string, string>
     */
    public function gatewayStatuses(): array
    {
        $r = $this->miCall('dr_gw_status');
        if ($r === null || isset($r['error'])) {
            $r = $this->miCall('drouting:gw_status');
        }
        if ($r === null || isset($r['error']) || !is_array($r['result'] ?? null)) {
            return [];
        }
        $out = [];
        $walk = static function ($node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            $gwid = $node['gwid'] ?? $node['GW_ID'] ?? $node['ID'] ?? null;
            $state = $node['State'] ?? $node['state'] ?? $node['Enabled'] ?? $node['enabled'] ?? $node['Status'] ?? $node['status'] ?? null;
            if (is_string($gwid) && $gwid !== '' && $state !== null && $state !== '') {
                $out[$gwid] = is_string($state) ? $state : (string) $state;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($r['result']);
        return $out;
    }

    /** @return list<string> */
    private function columns(string $table, bool $fresh = false): array
    {
        static $cache = [];
        if ($fresh) {
            unset($cache[$table]);
        }
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $rows = $this->db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $cache[$table] = array_values(array_map(static fn ($r) => (string) $r['Field'], $rows));
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }

    private function db(): PDO
    {
        if ($this->osDb === null) {
            throw new \RuntimeException('OpenSIPS database is not configured');
        }
        return $this->osDb;
    }
}
