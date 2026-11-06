<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\FreeSWITCH;

use Ownswitch\App;
use Ownswitch\Support\Crypto;

/**
 * Portal side of FreeSWITCH. SIP users and routes stay in the Ownswitch database
 * (xml_curl reads them). Trunks are Sofia gateways. Live calls and hangup are ESL.
 */
final class FreeswitchProvisioner
{
    public const GRP_CUSTOMER = 1;
    public const GRP_CARRIER = 2;

    public function __construct(
        private string $host,
        private int $port,
        private string $password,
        private string $confDir,
        private string $initError = '',
        private string $gatewayDirPath = '',
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = (array) App::config('app.integrations.freeswitch', []);
        $host = trim((string) ($cfg['host'] ?? ''));
        $password = (string) ($cfg['password'] ?? '');
        $conf = rtrim((string) ($cfg['conf_dir'] ?? '/usr/local/freeswitch/conf'), '/');
        $gw = rtrim((string) ($cfg['gateway_dir'] ?? ''), '/');
        if ($gw === '') {
            $gw = $conf . '/sip_profiles/external';
        }
        if ($host === '' || $password === '') {
            return new self('', 8021, '', $conf, 'FREESWITCH_ESL_HOST / FREESWITCH_ESL_PASSWORD are not set', $gw);
        }
        return new self($host, (int) ($cfg['port'] ?? 8021), $password, $conf, '', $gw);
    }

    public function enabled(): bool
    {
        return $this->host !== '' && $this->password !== '';
    }

    public function initError(): string
    {
        return $this->initError;
    }

    public function hasMi(): bool
    {
        return $this->enabled();
    }

    /** @return array{0:string,1:string} */
    public static function ha1(string $username, string $realm, string $password): array
    {
        return [md5("{$username}:{$realm}:{$password}"), md5("{$username}@{$realm}:{$realm}:{$password}")];
    }

    public function pdo(): null
    {
        return null;
    }

    /**
     * Directory is live xml_curl, so a SIP user save does not push a subscriber row.
     *
     * @param array<string,mixed>|null $account
     */
    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b, ?array $account = null): void
    {
        unset($username, $domain, $ha1, $ha1b, $account);
    }

    public function removeSubscriber(string $username, string $domain): void
    {
        unset($username, $domain);
    }

    /** @param array<string,mixed>|null $account */
    public function syncSipPeerProfile(string $username, string $mediaPolicy, int $maxChannels, ?array $account = null): void
    {
        unset($username, $mediaPolicy, $maxChannels, $account);
    }

    public function removeSipPeerProfile(string $username): void
    {
        unset($username);
    }

    public function refreshAllowCallsForCustomer(int $customerId): void
    {
        unset($customerId);
    }

    public function removeAddress(int $group, string $ip, int $mask, int $port): void
    {
        unset($group, $ip, $mask, $port);
    }

    public function upsertAddress(int $group, string $ip, int $mask, int $port, string $proto, string $context): void
    {
        unset($group, $ip, $mask, $port, $proto, $context);
    }

    public function syncDidAlias(string $didNorm, string $sipUsername, string $domain): void
    {
        unset($didNorm, $sipUsername, $domain);
    }

    public function removeDidAlias(string $didNorm): void
    {
        unset($didNorm);
    }

    /** @param list<array<string,mixed>> $rules */
    public function syncRoutingRules(array $rules): void
    {
        unset($rules);
    }

    public function reloadAddresses(): bool
    {
        return $this->rescan();
    }

    public function reloadRouting(): bool
    {
        return $this->rescan();
    }

    public function reloadRegistrant(): bool
    {
        return $this->rescan();
    }

    /** @param array<string,mixed> $trunk */
    public function syncTrunk(array $trunk): void
    {
        $id = (int) ($trunk['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Trunk id missing');
        }
        if (strtolower((string) ($trunk['status'] ?? '')) !== 'active') {
            $this->removeTrunk($id);
            return;
        }
        $dir = $this->gatewayDir();
        $xml = $this->gatewayXml($trunk);
        $path = $dir . '/gw-' . $id . '.xml';
        $prev = is_file($path) ? (string) @file_get_contents($path) : '';
        $unchanged = ($prev !== '' && hash('sha256', $prev) === hash('sha256', $xml));
        if (!$unchanged) {
            if (file_put_contents($path, $xml) === false) {
                throw new \RuntimeException('Cannot write ' . $path);
            }
            @chmod($path, 0660);
        }
        // Do not kill a healthy REGED gateway when XML did not change (avoids REGISTER flaps).
        if ($unchanged) {
            $st = $this->gatewayStatus($id);
            $state = strtoupper((string) ($st['state'] ?? ''));
            if (in_array($state, ['REGED', 'NOREG', 'UP'], true) || strtoupper((string) ($st['status'] ?? '')) === 'UP') {
                return;
            }
        }
        // New/changed gateways (or not loaded) need killgw + reloadxml + rescan.
        $this->reloadGateway($id);
    }

    public function removeTrunk(int $trunkId): void
    {
        $path = $this->gatewayDir() . '/gw-' . $trunkId . '.xml';
        if (is_file($path)) {
            unlink($path);
        }
        try {
            $this->esl()->api('sofia profile external killgw gw-' . $trunkId);
        } catch (\Throwable) {
        }
        try {
            $this->esl()->api('reloadxml');
        } catch (\Throwable) {
        }
        $this->rescan();
    }

    /**
     * Sofia outbound REGISTER / gateway state for portal trunk list.
     *
     * @return array{state:string,status:string,label:string,latency_ms:int}
     */
    public function gatewayStatus(int $trunkId): array
    {
        $name = 'gw-' . $trunkId;
        $empty = ['state' => '', 'status' => '', 'label' => 'n/a', 'latency_ms' => 0];
        if (!$this->enabled() || $trunkId <= 0) {
            return $empty;
        }
        try {
            $body = $this->esl()->api('sofia status gateway ' . $name);
        } catch (\Throwable $e) {
            error_log('[ownswitch] sofia gateway status: ' . $e->getMessage());
            return ['state' => '', 'status' => '', 'label' => 'err', 'latency_ms' => 0];
        }
        if ($body === '' || stripos($body, 'Invalid Gateway') !== false || stripos($body, 'not found') !== false) {
            return ['state' => 'NOGW', 'status' => '', 'label' => 'not loaded', 'latency_ms' => 0];
        }
        $state = '';
        $status = '';
        $pingRaw = '';
        if (preg_match('/^\s*State\s+(\S+)/mi', $body, $m)) {
            $state = strtoupper(trim($m[1]));
        }
        if (preg_match('/^\s*Status\s+(\S+)/mi', $body, $m)) {
            $status = strtoupper(trim($m[1]));
        }
        // Sofia gateway PingTime is already milliseconds (float), e.g. "5.35".
        if (preg_match('/^\s*PingTime\s+(\S+)/mi', $body, $m)) {
            $pingRaw = trim($m[1]);
        }
        $latencyMs = $this->gatewayPingToMs($pingRaw);
        $label = $state !== '' ? $state : ($status !== '' ? $status : 'unknown');
        // REGED = registered; NOREG = IP peer (no register); FAIL_WAIT / UNREGED = problem
        return ['state' => $state, 'status' => $status, 'label' => $label, 'latency_ms' => $latencyMs];
    }

    private function reloadGateway(int $trunkId): void
    {
        $name = 'gw-' . $trunkId;
        try {
            $this->esl()->api('sofia profile external killgw ' . $name);
        } catch (\Throwable $e) {
            error_log('[ownswitch] killgw ' . $name . ': ' . $e->getMessage());
        }
        try {
            $this->esl()->api('reloadxml');
        } catch (\Throwable $e) {
            error_log('[ownswitch] reloadxml: ' . $e->getMessage());
        }
        // Brief pause so Sofia finishes deleting the old gateway before rescan.
        usleep(250000);
        $this->rescan();
        try {
            // Force REGISTER attempt for register=true gateways.
            $this->esl()->api('sofia profile external register ' . $name);
        } catch (\Throwable) {
        }
    }

    /**
     * Softphone/user REGISTERs on Sofia profiles (external hosts softphones here).
     *
     * @return array<string,array{contact:string,received:string,expires:int,latency_ms:int,state:string}>
     */
    public function registeredContacts(): array
    {
        $out = [];
        foreach (['external', 'internal'] as $profile) {
            try {
                $body = $this->esl()->api('sofia xmlstatus profile ' . $profile . ' reg');
            } catch (\Throwable $e) {
                error_log('[ownswitch] sofia reg ' . $profile . ': ' . $e->getMessage());
                continue;
            }
            if ($body === '' || stripos($body, 'Invalid Profile') !== false) {
                continue;
            }
            $sx = @simplexml_load_string($body);
            if ($sx === false) {
                continue;
            }
            foreach ($sx->xpath('//registration') ?: [] as $reg) {
                $user = (string) ($reg->user ?? $reg->{'sip-auth-user'} ?? '');
                $name = strtolower(trim(explode('@', $user, 2)[0] ?? ''));
                if ($name === '') {
                    continue;
                }
                $status = (string) ($reg->status ?? '');
                $expSecs = 0;
                if (preg_match('/expsecs?\((\d+)\)/i', $status, $m)) {
                    $expSecs = (int) $m[1];
                }
                // expires = unix time when registration ends (0 = unknown / treat as live)
                $expiresAt = $expSecs > 0 ? time() + $expSecs : 0;
                $pingStatus = strtolower(trim((string) ($reg->{'ping-status'} ?? '')));
                $ms = $this->pingToMs((string) ($reg->{'ping-time'} ?? ''));
                $out[$name] = [
                    'contact' => (string) ($reg->contact ?? ''),
                    'received' => (string) ($reg->{'network-ip'} ?? ''),
                    'expires' => $expiresAt,
                    'latency_ms' => $ms,
                    'state' => ($pingStatus === '' || $pingStatus === 'reachable') ? 'REGED' : strtoupper($pingStatus),
                ];
            }
        }
        return $out;
    }

    public function usernameBySourceIp(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $hits = [];
        foreach ($this->registeredContacts() as $user => $row) {
            if (($row['received'] ?? '') === $ip || str_contains((string) ($row['contact'] ?? ''), $ip)) {
                $hits[] = $user;
            }
        }
        $hits = array_values(array_unique($hits));
        if (count($hits) === 1) {
            return $hits[0];
        }
        if ($hits !== []) {
            return null;
        }
        try {
            $st = App::db()->prepare(
                "SELECT username FROM sip_accounts
                 WHERE status = 'active' AND connection_mode = 'ip'
                   AND (sip_host = ? OR FIND_IN_SET(?, sip_host) > 0)
                 LIMIT 2"
            );
            $st->execute([$ip, $ip]);
            $names = $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            return count($names) === 1 && is_string($names[0]) ? $names[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listDialogs(): array
    {
        $out = [];
        $esl = $this->esl();
        try {
            $rows = $esl->channels();
            foreach ($rows as $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                if ($uuid === '' || strtolower((string) ($row['direction'] ?? '')) === 'outbound') {
                    continue;
                }
                $dump = [];
                try {
                    $dump = self::parseUuidDump($esl->uuidDump($uuid));
                } catch (\Throwable) {
                    $dump = [];
                }
                $callstate = strtoupper((string) ($row['callstate'] ?? ''));
                $state = match (true) {
                    str_contains($callstate, 'RING'), str_contains($callstate, 'EARLY') => 'EARLY',
                    str_contains($callstate, 'ACTIVE'), str_contains($callstate, 'HELD') => 'CONFIRMED',
                    default => 'EARLY',
                };
                $presence = (string) ($row['presence_id'] ?? '');
                $sipUser = trim((string) ($dump['variable_os_sip_user'] ?? ''));
                if ($sipUser === '') {
                    $sipUser = trim(explode('@', $presence, 2)[0] ?? '');
                }
                $cidNum = trim((string) ($dump['variable_sip_from_user'] ?? $dump['Caller-Caller-ID-Number'] ?? $row['cid_num'] ?? $row['initial_cid_num'] ?? ''));
                $cidName = trim((string) ($dump['Caller-Caller-ID-Name'] ?? $row['cid_name'] ?? $row['initial_cid_name'] ?? ''));
                if ($sipUser === '') {
                    $sipUser = $cidNum;
                }
                $dest = (string) ($row['dest'] ?? $row['callee_num'] ?? '');
                $ip = (string) ($row['ip_addr'] ?? '');
                $start = (int) ($row['created_epoch'] ?? 0);
                $answered = (int) ($dump['variable_answered_epoch'] ?? 0);
                if ($answered <= 0) {
                    $us = (int) ($dump['Caller-Channel-Answered-Time'] ?? 0);
                    if ($us > 1_000_000_000_000_000) {
                        $answered = intdiv($us, 1_000_000);
                    }
                }
                $talk = $answered > 1_000_000_000 ? max(0, time() - $answered) : 0;
                $out[] = [
                    'uuid' => $uuid,
                    'callid' => (string) ($row['call_uuid'] ?? $uuid),
                    'from_uri' => $cidNum !== '' ? $cidNum : $sipUser,
                    'to_uri' => $dest,
                    'state' => $state,
                    'start_time' => $start,
                    'talk_seconds' => $talk,
                    'duration' => $talk,
                    'os_src' => $sipUser,
                    'os_dst' => $dest,
                    'caller_id' => self::callerIdNumber($cidNum, $cidName, $sipUser),
                    'caller_contact' => $ip,
                    'from_tag' => '',
                ];
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('FreeSWITCH ESL: ' . $e->getMessage(), 0, $e);
        } finally {
            $esl->close();
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function parseUuidDump(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim($v);
            }
        }
        return $out;
    }

    /** Phone-like caller ID. Skips the SIP username and a display name when a real number exists. */
    private static function callerIdNumber(string $number, string $name, string $sipUser): string
    {
        $num = self::phoneDigits($number);
        if ($num !== '' && strcasecmp($number, $sipUser) !== 0) {
            return $num;
        }
        $fromName = self::phoneDigits($name);
        if ($fromName !== '' && $fromName !== $num) {
            return $fromName;
        }
        return $num;
    }

    private static function phoneDigits(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        $len = strlen($d);
        return ($len >= 6 && $len <= 18) ? $d : '';
    }

    /**
     * Admin Spy / Whisper / Barge via FreeSWITCH eavesdrop.
     * $monitorExtension is a dialable user (e.g. 1001) or full dialstring (user/1001@domain).
     *
     * @return array{ok:bool,detail:string}
     */
    public function eavesdropCall(string $targetUuid, string $monitorExtension, string $mode = 'spy'): array
    {
        $uuid = trim($targetUuid);
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return ['ok' => false, 'detail' => 'Invalid channel UUID'];
        }
        $ext = trim($monitorExtension);
        if ($ext === '') {
            return ['ok' => false, 'detail' => 'Monitor extension required'];
        }
        $mode = strtolower($mode);
        if (!in_array($mode, ['spy', 'whisper', 'barge'], true)) {
            return ['ok' => false, 'detail' => 'Mode must be spy, whisper, or barge'];
        }
        // Accept "1001", "user/1001", or "sofia/internal/1001@domain"
        if (!str_contains($ext, '/')) {
            $ext = 'user/' . preg_replace('/[^0-9A-Za-z_\-.]/', '', $ext);
        }
        try {
            $reply = $this->esl()->eavesdrop($uuid, $ext, $mode);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
        $ok = $reply === '' || str_contains($reply, '+OK') || str_contains(strtolower($reply), 'job-uuid');
        $label = match ($mode) {
            'whisper' => 'Whisper',
            'barge' => 'Barge',
            default => 'Spy',
        };
        return ['ok' => $ok, 'detail' => $ok ? "{$label} started" : ($reply !== '' ? $reply : 'eavesdrop failed')];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    public function endDialog(string $callId, ?string $fromTag = null, ?string $dialogId = null): array
    {
        unset($fromTag);
        $uuid = '';
        foreach ([$dialogId, $callId] as $cand) {
            $cand = trim((string) $cand);
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $cand)) {
                $uuid = $cand;
                break;
            }
        }
        if ($uuid === '') {
            foreach ($this->listDialogs() as $row) {
                if ($callId !== '' && ((string) ($row['callid'] ?? '') === $callId || (string) ($row['uuid'] ?? '') === $callId)) {
                    $uuid = (string) $row['uuid'];
                    break;
                }
            }
        }
        if ($uuid === '') {
            return ['ok' => false, 'detail' => 'No FreeSWITCH channel for this call'];
        }
        try {
            $reply = $this->esl()->uuidKill($uuid);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
        $ok = $reply === '' || str_starts_with($reply, '+OK');
        return ['ok' => $ok, 'detail' => $ok ? 'Hung up' : ($reply !== '' ? $reply : 'uuid_kill failed')];
    }

    /** @param array<string,mixed> $d */
    public static function extractDialogId(array $d): string
    {
        $uuid = trim((string) ($d['uuid'] ?? ''));
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return $uuid;
        }
        return '';
    }

    private function esl(): EslClient
    {
        $c = new EslClient($this->host, $this->port, $this->password, 5.0);
        $c->connect();
        return $c;
    }

    private function rescan(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        try {
            $reply = $this->esl()->api('sofia profile external rescan');
            return str_contains($reply, '+OK') || str_contains($reply, 'Reload XML');
        } catch (\Throwable $e) {
            error_log('[ownswitch] sofia rescan: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Write gateways under OWN_VAR (inside PHP open_basedir), not under /usr/local/freeswitch.
     * Deploy links that directory to sip_profiles/external for Sofia includes.
     */
    private function gatewayDir(): string
    {
        $dir = $this->gatewayDirPath !== ''
            ? $this->gatewayDirPath
            : ($this->confDir . '/sip_profiles/external');
        // Resolve symlink targets so open_basedir / permission errors are clearer.
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            $resolved = realpath($dir);
            if ($resolved !== false) {
                $dir = $resolved;
            }
        }
        if (!is_dir($dir)) {
            $parent = dirname($dir);
            if (!is_dir($parent) && !@mkdir($parent, 02775, true) && !is_dir($parent)) {
                throw new \RuntimeException(
                    'Cannot create FreeSWITCH sip_profiles: ' . $parent
                    . '. Run: curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-register.sh | sudo bash'
                );
            }
            if (!@mkdir($dir, 02770, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    'Cannot create FreeSWITCH gateway directory: ' . $dir
                    . '. Run: curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-register.sh | sudo bash'
                );
            }
            @chmod($dir, 02770);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                'FreeSWITCH gateway directory not writable by portal: ' . $dir
                . ' (chown ownswitch:freeswitch && chmod 2770 — and allow /usr/local/freeswitch in PHP open_basedir)'
            );
        }
        return $dir;
    }

    /** @param array<string,mixed> $trunk */
    private function gatewayXml(array $trunk): string
    {
        $id = (int) $trunk['id'];
        $host = trim((string) ($trunk['host'] ?? ''));
        if ($host === '') {
            throw new \RuntimeException('Trunk host is required for FreeSWITCH gateway');
        }
        $port = (int) ($trunk['port'] ?? 5060);
        if ($port <= 0) {
            $port = 5060;
        }
        $proxy = $host . ':' . $port;
        $transport = strtolower((string) ($trunk['transport'] ?? 'udp'));
        if (!in_array($transport, ['udp', 'tcp', 'tls'], true)) {
            $transport = 'udp';
        }
        $mode = strtolower((string) ($trunk['auth_mode'] ?? 'ip'));
        // register = Sofia REGISTER to vendor (Magnus/Asterisk); invite = digest on INVITE only; ip = no auth.
        $register = $mode === 'register' ? 'true' : 'false';
        $user = trim((string) ($trunk['auth_username'] ?? ''));
        $fromDomain = trim((string) ($trunk['from_domain'] ?? ''));
        $realm = $fromDomain !== '' ? $fromDomain : $host;
        $pass = '';
        if (!empty($trunk['auth_secret_enc'])) {
            try {
                $pass = Crypto::decrypt((string) $trunk['auth_secret_enc']);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Cannot decrypt trunk password: ' . $e->getMessage());
            }
        }
        if (in_array($mode, ['register', 'invite'], true)) {
            if ($user === '') {
                throw new \RuntimeException('Auth username is required for REGISTER / Invite digest trunks');
            }
            if ($pass === '') {
                throw new \RuntimeException('Auth password is required for REGISTER / Invite digest trunks');
            }
        }

        // REGISTER identity must match username for Asterisk/Magnus. Portal "From user" is applied on INVITE in dialplan.
        $gwFromUser = $user;
        if ($mode !== 'register') {
            $explicitFrom = trim((string) ($trunk['from_user'] ?? ''));
            if ($explicitFrom !== '') {
                $gwFromUser = $explicitFrom;
            }
        }

        $ping = ((string) ($trunk['qualify'] ?? 'yes') === 'no') ? '0' : '30';
        // Multi-line XML — Sofia reliably loads these; single-line blobs have failed to include.
        $lines = [
            '  <param name="realm" value="' . $this->x($realm) . '"/>',
            '  <param name="proxy" value="' . $this->x($proxy) . '"/>',
            '  <param name="register-proxy" value="' . $this->x($proxy) . '"/>',
            '  <param name="register" value="' . $register . '"/>',
            '  <param name="register-transport" value="' . $this->x($transport) . '"/>',
            '  <param name="retry-seconds" value="30"/>',
            '  <param name="expire-seconds" value="3600"/>',
            '  <param name="caller-id-in-from" value="true"/>',
            '  <param name="ping" value="' . $ping . '"/>',
        ];

        if ($user !== '' && in_array($mode, ['register', 'invite'], true)) {
            $lines[] = '  <param name="username" value="' . $this->x($user) . '"/>';
            $lines[] = '  <param name="password" value="' . $this->x($pass) . '"/>';
            $lines[] = '  <param name="extension" value="' . $this->x($user) . '"/>';
            $lines[] = '  <param name="extension-in-contact" value="true"/>';
        }
        if ($gwFromUser !== '') {
            $lines[] = '  <param name="from-user" value="' . $this->x($gwFromUser) . '"/>';
        }
        if ($fromDomain !== '') {
            $lines[] = '  <param name="from-domain" value="' . $this->x($fromDomain) . '"/>';
        } elseif ($mode === 'register') {
            $lines[] = '  <param name="from-domain" value="' . $this->x($host) . '"/>';
        }

        // Optional — only when portal has codecs set (avoids rewriting REGED gateways with NULL codecs).
        $codecStr = \Ownswitch\Support\SipCodecs::toAbsoluteCodecString(
            isset($trunk['codecs']) ? (string) $trunk['codecs'] : null
        );
        if ($codecStr !== '') {
            $lines[] = '  <param name="absolute_codec_string" value="' . $this->x($codecStr) . '"/>';
        }

        return "<include>\n"
            . '  <gateway name="gw-' . $id . "\">\n"
            . implode("\n", $lines) . "\n"
            . "  </gateway>\n"
            . "</include>\n";
    }

    /** Sofia gateway status PingTime (already ms as float). */
    private function gatewayPingToMs(string $raw): int
    {
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }
        $n = (float) $raw;
        if ($n <= 0) {
            return 0;
        }
        return max(1, (int) round($n));
    }

    /** Directory registration ping-time (often seconds if &lt; 20). */
    private function pingToMs(string $raw): int
    {
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }
        $n = (float) $raw;
        if ($n <= 0) {
            return 0;
        }
        if ($n < 20) {
            return (int) round($n * 1000);
        }
        return (int) round($n);
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
