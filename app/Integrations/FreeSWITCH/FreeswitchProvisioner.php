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
            $gw = '/var/lib/ownswitch/freeswitch/gateways';
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
        if (file_put_contents($path, $xml) === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        @chmod($path, 0660);
        // New/changed gateways need killgw + reloadxml + rescan (rescan alone often keeps old params).
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
     * @return array{state:string,status:string,label:string}
     */
    public function gatewayStatus(int $trunkId): array
    {
        $name = 'gw-' . $trunkId;
        $empty = ['state' => '', 'status' => '', 'label' => 'n/a'];
        if (!$this->enabled() || $trunkId <= 0) {
            return $empty;
        }
        try {
            $body = $this->esl()->api('sofia status gateway ' . $name);
        } catch (\Throwable $e) {
            error_log('[ownswitch] sofia gateway status: ' . $e->getMessage());
            return ['state' => '', 'status' => '', 'label' => 'err'];
        }
        if ($body === '' || stripos($body, 'Invalid Gateway') !== false || stripos($body, 'not found') !== false) {
            return ['state' => 'NOGW', 'status' => '', 'label' => 'not loaded'];
        }
        $state = '';
        $status = '';
        if (preg_match('/^\s*State\s+(\S+)/mi', $body, $m)) {
            $state = strtoupper(trim($m[1]));
        }
        if (preg_match('/^\s*Status\s+(\S+)/mi', $body, $m)) {
            $status = strtoupper(trim($m[1]));
        }
        $label = $state !== '' ? $state : ($status !== '' ? $status : 'unknown');
        // REGED = registered; NOREG = IP peer (no register); FAIL_WAIT / UNREGED = problem
        return ['state' => $state, 'status' => $status, 'label' => $label];
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
     * Write gateways under OWN_VAR (inside PHP open_basedir), not under /usr/local/freeswitch.
     * Deploy links that directory to sip_profiles/external for Sofia includes.
     */
    private function gatewayDir(): string
    {
        $dir = $this->gatewayDirPath !== ''
            ? $this->gatewayDirPath
            : '/var/lib/ownswitch/freeswitch/gateways';
        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            $parent = dirname($dir);
            if (!is_dir($parent) && !@mkdir($parent, 02775, true) && !is_dir($parent)) {
                throw new \RuntimeException(
                    'Cannot create FreeSWITCH gateway parent: ' . $parent
                    . '. Run: curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-sync.sh | sudo bash'
                );
            }
            if (!@mkdir($dir, 02770, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    'Cannot create FreeSWITCH gateway directory: ' . $dir
                    . '. Run: curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-fs-trunk-sync.sh | sudo bash'
                );
            }
            @chmod($dir, 02770);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                'FreeSWITCH gateway directory not writable by portal: ' . $dir
                . ' (chown ownswitch:freeswitch, chmod 2770)'
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
        $params = '<param name="realm" value="' . $this->x($realm) . '"/>'
            . '<param name="proxy" value="' . $this->x($proxy) . '"/>'
            . '<param name="register-proxy" value="' . $this->x($proxy) . '"/>'
            . '<param name="register" value="' . $register . '"/>'
            . '<param name="register-transport" value="' . $this->x($transport) . '"/>'
            . '<param name="retry-seconds" value="30"/>'
            . '<param name="expire-seconds" value="3600"/>'
            . '<param name="caller-id-in-from" value="true"/>'
            . '<param name="ping" value="' . $ping . '"/>';

        if ($user !== '' && in_array($mode, ['register', 'invite'], true)) {
            $params .= '<param name="username" value="' . $this->x($user) . '"/>'
                . '<param name="password" value="' . $this->x($pass) . '"/>'
                . '<param name="extension" value="' . $this->x($user) . '"/>'
                . '<param name="extension-in-contact" value="true"/>';
        }
        if ($gwFromUser !== '') {
            $params .= '<param name="from-user" value="' . $this->x($gwFromUser) . '"/>';
        }
        if ($fromDomain !== '') {
            $params .= '<param name="from-domain" value="' . $this->x($fromDomain) . '"/>';
        } elseif ($mode === 'register') {
            $params .= '<param name="from-domain" value="' . $this->x($host) . '"/>';
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
