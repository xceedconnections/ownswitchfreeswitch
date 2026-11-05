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
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = (array) App::config('app.integrations.freeswitch', []);
        $host = trim((string) ($cfg['host'] ?? ''));
        $password = (string) ($cfg['password'] ?? '');
        $conf = rtrim((string) ($cfg['conf_dir'] ?? '/usr/local/freeswitch/conf'), '/');
        if ($host === '' || $password === '') {
            return new self('', 8021, '', $conf, 'FREESWITCH_ESL_HOST / FREESWITCH_ESL_PASSWORD are not set');
        }
        return new self($host, (int) ($cfg['port'] ?? 8021), $password, $conf, '');
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
        if (file_put_contents($path, $xml) === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        @chmod($path, 0660);
        $this->rescan();
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
        $this->rescan();
    }

    /**
     * @return array<string,array{contact:string,received:string,expires:int,latency_ms:int}>
     */
    public function registeredContacts(): array
    {
        try {
            $body = $this->esl()->api('sofia xmlstatus profile external reg');
        } catch (\Throwable $e) {
            error_log('[ownswitch] sofia reg: ' . $e->getMessage());
            return [];
        }
        $sx = @simplexml_load_string($body);
        if ($sx === false) {
            return [];
        }
        $out = [];
        foreach ($sx->xpath('//registration') ?: [] as $reg) {
            $user = (string) ($reg->user ?? '');
            $name = strtolower(trim(explode('@', $user, 2)[0] ?? ''));
            if ($name === '') {
                continue;
            }
            $contact = (string) ($reg->contact ?? '');
            $ip = (string) ($reg->{'network-ip'} ?? '');
            $ms = $this->pingToMs((string) ($reg->{'ping-time'} ?? ''));
            $out[$name] = [
                'contact' => $contact,
                'received' => $ip,
                'expires' => 3600,
                'latency_ms' => $ms,
            ];
        }
        return $out;
    }

    public function usernameBySourceIp(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        foreach ($this->registeredContacts() as $user => $row) {
            if (($row['received'] ?? '') === $ip) {
                return $user;
            }
            if (str_contains((string) ($row['contact'] ?? ''), $ip)) {
                return $user;
            }
        }
        try {
            $st = App::db()->prepare(
                "SELECT username FROM sip_accounts WHERE status = 'active' AND sip_host = ? LIMIT 1"
            );
            $st->execute([$ip]);
            $u = $st->fetchColumn();
            return is_string($u) && $u !== '' ? $u : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listDialogs(): array
    {
        $out = [];
        try {
            $rows = $this->esl()->channels();
        } catch (\Throwable $e) {
            throw new \RuntimeException('FreeSWITCH ESL: ' . $e->getMessage(), 0, $e);
        }
        foreach ($rows as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '' || strtolower((string) ($row['direction'] ?? '')) === 'outbound') {
                continue;
            }
            $callstate = strtoupper((string) ($row['callstate'] ?? ''));
            $state = match (true) {
                str_contains($callstate, 'RING'), str_contains($callstate, 'EARLY') => 'EARLY',
                str_contains($callstate, 'ACTIVE'), str_contains($callstate, 'HELD') => 'CONFIRMED',
                default => 'EARLY',
            };
            $presence = (string) ($row['presence_id'] ?? '');
            $sipUser = trim(explode('@', $presence, 2)[0] ?? '');
            if ($sipUser === '') {
                $sipUser = (string) ($row['cid_num'] ?? '');
            }
            $dest = (string) ($row['dest'] ?? $row['callee_num'] ?? '');
            $ip = (string) ($row['ip_addr'] ?? '');
            $start = (int) ($row['created_epoch'] ?? 0);
            $out[] = [
                'uuid' => $uuid,
                'callid' => (string) ($row['call_uuid'] ?? $uuid),
                'from_uri' => $sipUser !== '' ? $sipUser : (string) ($row['cid_num'] ?? ''),
                'to_uri' => $dest,
                'state' => $state,
                'start_time' => $start,
                'duration' => $start > 0 ? max(0, time() - $start) : 0,
                'os_src' => $sipUser,
                'os_dst' => $dest,
                'caller_contact' => $ip,
                'from_tag' => '',
            ];
        }
        return $out;
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
     * Sofia external profile includes external/*.xml as gateways.
     * Create the directory when missing (common if stage 75 was skipped / partial).
     */
    private function gatewayDir(): string
    {
        $dir = $this->resolveGatewayDir();
        if (!is_dir($dir)) {
            $parent = dirname($dir);
            if (!is_dir($parent)) {
                if (!@mkdir($parent, 02775, true) && !is_dir($parent)) {
                    throw new \RuntimeException(
                        'FreeSWITCH sip_profiles missing: ' . $parent
                        . '. On the server run: sudo bash /opt/ownswitch/app/deploy/fix-fs-gateway-dir.sh'
                    );
                }
            }
            if (!@mkdir($dir, 02770, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    'FreeSWITCH gateway directory missing: ' . $dir
                    . '. On the server run: sudo bash /opt/ownswitch/app/deploy/fix-fs-gateway-dir.sh'
                );
            }
            @chmod($dir, 02770);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                'FreeSWITCH gateway directory is not writable: ' . $dir
                . ' (chown ownswitch:freeswitch and chmod 2770 — or run fix-fs-gateway-dir.sh)'
            );
        }
        return $dir;
    }

    /** Prefer configured conf; fall back to prefix/etc/freeswitch if that is where samples live. */
    private function resolveGatewayDir(): string
    {
        $primary = $this->confDir . '/sip_profiles/external';
        if (is_dir($primary)) {
            return $primary;
        }
        $altRoot = '';
        if (str_ends_with($this->confDir, '/conf')) {
            $altRoot = substr($this->confDir, 0, -5) . '/etc/freeswitch';
        } elseif (is_dir('/usr/local/freeswitch/etc/freeswitch/sip_profiles')) {
            $altRoot = '/usr/local/freeswitch/etc/freeswitch';
        }
        if ($altRoot !== '') {
            $alt = $altRoot . '/sip_profiles/external';
            if (is_dir($alt)) {
                return $alt;
            }
            // Prefer writing under the live profile tree when only sip_profiles exists there.
            if (is_dir($altRoot . '/sip_profiles') && !is_dir($this->confDir . '/sip_profiles')) {
                return $alt;
            }
        }
        return $primary;
    }

    /** @param array<string,mixed> $trunk */
    private function gatewayXml(array $trunk): string
    {
        $id = (int) $trunk['id'];
        $host = trim((string) ($trunk['host'] ?? ''));
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
        // register = REGISTER to carrier; invite = auth on outbound INVITE only; ip = no auth.
        $register = $mode === 'register' ? 'true' : 'false';
        $user = trim((string) ($trunk['auth_username'] ?? ''));
        $fromUser = trim((string) ($trunk['from_user'] ?? ''));
        if ($fromUser === '') {
            $fromUser = $user;
        }
        $pass = '';
        if (!empty($trunk['auth_secret_enc'])) {
            try {
                $pass = Crypto::decrypt((string) $trunk['auth_secret_enc']);
            } catch (\Throwable) {
                $pass = '';
            }
        }
        $ping = ((string) ($trunk['qualify'] ?? 'yes') === 'no') ? '0' : '30';
        $params = '<param name="realm" value="' . $this->x($host) . '"/>'
            . '<param name="proxy" value="' . $this->x($proxy) . '"/>'
            . '<param name="register" value="' . $register . '"/>'
            . '<param name="register-transport" value="' . $this->x($transport) . '"/>'
            . '<param name="retry-seconds" value="30"/>'
            . '<param name="expire-seconds" value="600"/>'
            . '<param name="caller-id-in-from" value="true"/>'
            . '<param name="ping" value="' . $ping . '"/>';
        if ($user !== '' && in_array($mode, ['register', 'invite'], true)) {
            $params .= '<param name="username" value="' . $this->x($user) . '"/>';
        }
        if ($fromUser !== '') {
            $params .= '<param name="from-user" value="' . $this->x($fromUser) . '"/>';
        }
        $fromDomain = trim((string) ($trunk['from_domain'] ?? ''));
        if ($fromDomain !== '') {
            $params .= '<param name="from-domain" value="' . $this->x($fromDomain) . '"/>';
        }
        if ($pass !== '' && in_array($mode, ['register', 'invite'], true)) {
            $params .= '<param name="password" value="' . $this->x($pass) . '"/>';
        }
        return '<include><gateway name="gw-' . $id . '">' . $params . '</gateway></include>' . "\n";
    }

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
