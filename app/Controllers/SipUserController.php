<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\SipAccountRepository;
use Ownswitch\Services\Dnc\DncService;
use Ownswitch\Services\Sip\SipOptionsProbe;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\MediaPolicy;
use Ownswitch\Support\SipCodecs;
use Ownswitch\Support\SipHosts;
use Ownswitch\Support\SipPeerOptions;

/**
 * SIP Users — FreeSWITCH directory auth, fixed-host port, media/record, and peer signalling params.
 */
final class SipUserController extends CrudController
{
    protected string $base = '/sip-users';
    protected string $singular = 'SIP User';
    protected string $plural = 'SIP Users';
    /** Customers may view SIP users only — staff/reseller create and edit. */
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No SIP users. Create an account so a phone or PBX can register to FreeSWITCH.';

    private ?string $plainPassword = null;

    protected function repo(): BaseRepository
    {
        return new SipAccountRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    /** @return array<string,string> */
    private function dncGroupOptions(): array
    {
        try {
            return (new DncService($this->db()))->groupOptions(false);
        } catch (\Throwable) {
            return ['' => '— None —'];
        }
    }

    /**
     * Active CallerID groups the actor may assign: global groups, plus groups bound to customers in scope.
     *
     * @return array<string,string>
     */
    private function callerIdGroupOptions(Request $r): array
    {
        $out = ['' => '— None —'];
        try {
            $scope = $this->scope($r);
            if ($scope->isGlobal()) {
                $st = $this->db()->query("SELECT id, name FROM callerid_groups WHERE status = 'active' ORDER BY name LIMIT 500");
            } else {
                $st = $this->db()->prepare(
                    "SELECT g.id, g.name FROM callerid_groups g
                     LEFT JOIN customers c ON c.id = g.customer_id
                     WHERE g.status = 'active'
                       AND (g.customer_id IS NULL OR g.customer_id = ? OR c.reseller_id = ?)
                     ORDER BY g.name LIMIT 500"
                );
                $resellerId = $scope->role === 'reseller' ? ($scope->resellerId ?? 0) : 0;
                $st->execute([$scope->isCustomerRole() ? ($scope->customerId ?? 0) : 0, $resellerId]);
            }
            foreach ($st->fetchAll() as $g) {
                $out[(string) $g['id']] = (string) $g['name'];
            }
        } catch (\Throwable) {
            // table not migrated yet
        }
        return $out;
    }

    private function ensureSipDncColumn(): void
    {
        try {
            $st = $this->db()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dnc_group_id'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->db()->exec(
                    'ALTER TABLE sip_accounts ADD COLUMN dnc_group_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_sip_accounts_dnc (dnc_group_id)'
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] sip dnc_group_id ensure: ' . $e->getMessage());
        }
    }

    protected function fields(Request $r): array
    {
        DstFormat::ensureColumns($this->db());
        $this->ensureSipDncColumn();
        $f = [];
        if (!$this->scope($r)->isCustomerRole()) {
            $f[] = [
                'name' => 'customer_id',
                'label' => 'User',
                'type' => 'select',
                'required' => true,
                'options' => $this->customerOptions($r),
                'readonly_on_edit' => true,
                'list' => true,
                'section' => 'Account',
            ];
        }
        $routes = ['' => '— use Tariff trunk group —'];
        try {
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = $p['name'];
            }
        } catch (\Throwable) {
        }

        array_push($f,
            [
                'name' => 'username',
                'label' => 'SIP user',
                'required' => true,
                'max' => 64,
                'pattern' => '/^[A-Za-z0-9._-]{3,64}$/',
                'readonly_on_edit' => true,
                'list' => true,
                'section' => 'Account',
            ],
            [
                'name' => 'password',
                'label' => 'SIP password',
                'type' => 'password',
                'max' => 128,
                'help' => 'Required for Dynamic mode. Blank = auto-generate (create) or keep current (edit). Min 12 if typed. Hidden for IP based.',
                'section' => 'Account',
            ],
            [
                'name' => 'sip_host',
                'label' => 'Allowed IP(s)',
                'type' => 'textarea',
                'rows' => 3,
                'max' => 1000,
                'default' => 'dynamic',
                'wide' => true,
                'help' => 'Dynamic: leave as “dynamic”. IP based: one IPv4/IPv6 per line (or comma-separated). All listed IPs are authorized for this SIP user (FreeSWITCH cidr + dialplan + firewall).',
                'placeholder' => "192.0.2.10\n203.0.113.20",
                'section' => 'Account',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['active' => 'Active', 'disabled' => 'Inactive'],
                'list' => true,
                'default' => 'active',
                'section' => 'Account',
            ],
            [
                'name' => 'caller_id',
                'label' => 'CallerID number',
                'max' => 32,
                'pattern' => '/^\+?[0-9]{3,30}$/',
                'help' => 'Leave blank for passthrough (ViciDial / softphone From goes out). Used when mode = Fixed. Trunk From user still wins if set.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'caller_id_mode',
                'label' => 'CallerID mode',
                'type' => 'select',
                'options' => SipPeerOptions::CALLER_ID_MODE,
                'default' => 'passthrough',
                'help' => 'Passthrough = whatever ViciDial/Eyebeam sends. Fixed = portal CallerID number. Display Name = softphone Display Name as CLI (digits). RPID = take the CLI from the Remote-Party-ID header. PAI = take the CLI from the P-Asserted-Identity header.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'callerid_group_id',
                'label' => 'CallerID group',
                'type' => 'select',
                'options' => $this->callerIdGroupOptions($r),
                'help' => 'Optional. Picks the outbound CLI from the group by dialled prefix (see Clients → CallerID). Leave empty to use the CallerID mode above.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'cid_min_len',
                'label' => 'Min CallerID length (digits)',
                'type' => 'number',
                'min' => 0,
                'max' => 32,
                'placeholder' => '11',
                'help' => 'USA with country code = 11. Blank/0 = off. Shorter CLI → 484 Wrong CallerID Length.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'cid_max_len',
                'label' => 'Max CallerID length (digits)',
                'type' => 'number',
                'min' => 0,
                'max' => 32,
                'placeholder' => '11',
                'help' => 'USA = 11. Blank/0 = off. Longer CLI → 484 Wrong CallerID Length.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'dst_format',
                'label' => 'Destination format',
                'type' => 'select',
                'options' => \Ownswitch\Support\DstFormat::OPTIONS,
                'default' => \Ownswitch\Support\DstFormat::AS_IS,
                'list' => true,
                'help' => 'Rewrite the dialled number before the trunk. E.164 with + : 12135550123 → +12135550123. Trunk Destination format is applied after this.',
                'section' => 'CLI and dial format',
            ],
            [
                'name' => 'notes',
                'label' => 'Description',
                'max' => 255,
                'section' => 'Account',
            ],

            [
                'name' => 'connection_mode',
                'label' => 'Type',
                'type' => 'select',
                'options' => SipPeerOptions::CONNECTION_MODE,
                'default' => 'dynamic',
                'help' => 'Dynamic = username/password. Several users may share one server IP (ViciDial carriers); the switch matches the Authorization username, not the source IP. IP based = one fixed IP and no password. Do not put the same IP on more than one IP-based user.',
                'section' => 'Network',
            ],
            [
                'name' => 'sip_port',
                'label' => 'SIP port',
                'type' => 'int',
                'min' => 0,
                'max' => 65535,
                'default' => '5060',
                'help' => 'IP based: peer signalling port (e.g. 7777 for ViciDial). Opened on the firewall for that IP. 0 = any port.',
                'section' => 'Network',
            ],
            [
                'name' => 'qualify',
                'label' => 'Qualify',
                'type' => 'select',
                'options' => SipPeerOptions::YES_NO,
                'default' => 'no',
                'help' => 'Dynamic users: Live status from FreeSWITCH Sofia REGISTER. IP peers: Yes enables OPTIONS RTT on the list page.',
                'section' => 'Network',
            ],

            [
                'name' => 'routing_plan_id',
                'label' => 'Trunk group',
                'type' => 'select',
                'options' => $routes,
                'list' => true,
                'help' => 'Overrides the Tariff trunk group for this SIP user when set.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'dnc_group_id',
                'label' => 'DNC group',
                'type' => 'select',
                'options' => $this->dncGroupOptions(),
                'list' => true,
                'help' => 'Block destinations in this Do Not Call list before any trunk/vendor is contacted (403). Trunk DNC is an extra check after routing.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'media_policy',
                'label' => 'Media / record',
                'type' => 'select',
                'options' => [
                    '' => 'Default (Settings)',
                    'direct' => 'Direct (bypass_media — peer RTP)',
                    'relay' => 'Relay (media via FreeSWITCH)',
                    'record' => 'Record (relay + record_session)',
                ],
                'list_options' => [
                    '' => 'Default',
                    'direct' => 'Direct',
                    'relay' => 'Relay',
                    'record' => 'Record',
                ],
                'default' => '',
                'list' => true,
                'help' => 'FreeSWITCH: Direct = bypass_media (phone↔carrier RTP). Relay = RTP through this switch. Record = Relay + wav under recordings/. Use Direct to keep the switch out of SDP; use Relay if you get one-way audio.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'codecs',
                'label' => 'Codecs',
                'type' => 'multiselect',
                'options' => SipCodecs::formOptions(),
                'add_label' => '+ Add codec…',
                'wide' => true,
                'default' => SipCodecs::defaultStorage($this->db()),
                'help' => 'Offer order for FreeSWITCH absolute_codec_string (G729, G711/ulaw/alaw, GSM, Opus, …). Blank on save keeps Settings → Default codecs. Does not affect REGISTER.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'max_channels',
                'label' => 'Call limit (CC)',
                'type' => 'int',
                'min' => -1,
                'max' => 10000,
                'required' => true,
                'default' => '2',
                'list' => true,
                'help' => 'Max concurrent calls for this SIP user (FreeSWITCH limit hash). -1 = unlimited.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'max_cps',
                'label' => 'CPS (calls/sec)',
                'type' => 'int',
                'min' => -1,
                'max' => 1000,
                'required' => true,
                'default' => '1',
                'list' => true,
                'help' => 'Max new calls per second (FreeSWITCH limit hash cps user N/1). -1 = unlimited. Typical PBX: 1–10. 0 is treated as 1.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'max_call_duration',
                'label' => 'Max call duration (s)',
                'type' => 'int',
                'min' => 0,
                'max' => 604800,
                'default' => '0',
                'list' => true,
                'help' => '0 = use Settings → Prepaid hard max call duration (system default). Or set a custom max in seconds (e.g. 3600 = 1 hour). Applied via sched_hangup.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'rate_cap',
                'label' => 'CAP (max tariff $/min)',
                'type' => 'number',
                'min' => 0,
                'placeholder' => '0.006',
                'list' => true,
                'help' => 'Compared to this customer’s tariff (sell) rate for the dialled prefix — independent of trunk/vendor CAP. Example: CAP 0.006 allows tariff 1213@0.0055, blocks 1204@0.007 and 1456@0.01. Blank = no CAP.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'dtmfmode',
                'label' => 'DTMF mode',
                'type' => 'select',
                'options' => SipPeerOptions::DTMF,
                'default' => 'RFC2833',
                'help' => 'FreeSWITCH dtmf_type: RFC2833 = telephone-event (recommended), INFO = SIP INFO, Inband = audio tones (+ start_dtmf), Auto = FreeSWITCH default.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'dtmf_pass',
                'label' => 'Allow DTMF (press 1)',
                'type' => 'select',
                'options' => SipPeerOptions::DTMF_PASS,
                'default' => 'yes',
                'help' => 'No = FreeSWITCH drop_dtmf (keypad digits are not passed to the vendor/B-leg). Yes = pass through.',
                'section' => 'Routing & media',
            ],

            // Single list column: green/red dot + latency ms
            ['name' => 'presence', 'label' => 'Live', 'list' => true, 'list_only' => true, 'section' => 'Account'],
        );
        return $f;
    }

    protected function extra(Request $r): array
    {
        return ['hideIdColumn' => true];
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            App::session()->flash(
                'warning',
                'Cannot read FreeSWITCH registrations: '
                . ($prov->initError() !== '' ? $prov->initError() : 'ESL host or password missing in ownswitch.env')
            );
        }
        $regs = $prov->enabled() ? $prov->registeredContacts() : [];
        foreach ($rows as &$row) {
            $user = strtolower(trim((string) ($row['username'] ?? '')));
            $qualify = strtolower((string) ($row['qualify'] ?? 'yes')) === 'yes';
            $reg = $regs[$user] ?? null;
            if ($reg === null) {
                foreach ($regs as $k => $info) {
                    if ($k === $user || str_starts_with((string) $k, $user . '@') || str_ends_with((string) $k, '@' . $user)) {
                        $reg = $info;
                        break;
                    }
                    $c = strtolower((string) ($info['contact'] ?? ''));
                    if ($c !== '' && str_contains($c, 'sip:' . $user . '@')) {
                        $reg = $info;
                        break;
                    }
                }
            }
            $hostRaw = (string) ($row['sip_host'] ?? 'dynamic');
            $peerIps = SipHosts::parse($hostRaw);
            $port = (int) ($row['sip_port'] ?? 5060);
            if ($port <= 0) {
                $port = 5060;
            }
            // List column: show first IP or +N more
            if ($peerIps !== []) {
                $row['sip_host'] = $peerIps[0] . (count($peerIps) > 1 ? ' +' . (count($peerIps) - 1) : '');
            }

            $online = false;
            $ms = null;
            $state = '';

            if ($reg !== null) {
                // expires is unix expiry from Sofia expsecs(); 0 means unknown → keep.
                $exp = (int) ($reg['expires'] ?? 0);
                if ($exp > 0 && $exp <= time()) {
                    $reg = null;
                }
            }

            $isDynamic = $peerIps === [];
            if ($reg !== null) {
                // Sofia REGISTER = live (same idea as trunk REGED).
                $online = true;
                $state = (string) ($reg['state'] ?? 'REGED');
                if ($state === '') {
                    $state = 'REGED';
                }
                $fromFs = (int) ($reg['latency_ms'] ?? 0);
                if ($fromFs > 0) {
                    $ms = $fromFs;
                }
            } elseif ($qualify && !$isDynamic) {
                // Fixed-IP peer(s): OPTIONS the first IP for Live column.
                $ms = SipOptionsProbe::rttMs($peerIps[0], $port, 1.5, $user);
                $online = $ms !== null;
                $state = $online ? 'REACHABLE' : '';
            }

            // Same shape as trunk Live column: green/red + REGED / ms.
            $row['presence'] = [
                'online' => $online,
                'ms' => $ms,
                'state' => $state,
                'contact' => $reg['contact'] ?? '',
                'received' => $reg['received'] ?? '',
            ];
            $row['reg_status'] = $online ? 'registered' : 'offline';
            $row['latency_ms'] = [
                'online' => $online,
                'ms' => $ms,
                'state' => $state !== '' ? $state : ($online ? 'REGED' : 'offline'),
            ];
        }
        unset($row);
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $domain = (string) App::config('app.integrations.opensips.sip_domain', 'sip.example.test');
        $this->plainPassword = null;
        $pw = $data['password'] ?? null;
        unset($data['password']);

        $prevMode = $this->resolveConnectionMode($existing ?? []);
        $mode = strtolower(trim((string) ($data['connection_mode'] ?? '')));
        if (!isset(SipPeerOptions::CONNECTION_MODE[$mode])) {
            $hostGuess = trim((string) ($data['sip_host'] ?? ($existing['sip_host'] ?? 'dynamic')));
            $mode = SipHosts::parse($hostGuess) !== [] ? 'ip' : 'dynamic';
        }
        $data['connection_mode'] = $mode;

        if (array_key_exists('sip_port', $data)) {
            $p = (int) $data['sip_port'];
            $data['sip_port'] = ($p < 0 || $p > 65535) ? 5060 : $p;
        }

        if ($mode === 'ip') {
            $ips = SipHosts::parse(isset($data['sip_host']) ? (string) $data['sip_host'] : '');
            if ($ips === []) {
                throw new \DomainException(
                    'IP based Type requires at least one valid IP on Account → Allowed IP(s) (one per line).'
                );
            }
            $data['sip_host'] = implode(',', $ips);
            $port = (int) ($data['sip_port'] ?? ($existing['sip_port'] ?? 5060));
            if ($port < 0 || $port > 65535) {
                throw new \DomainException('SIP port must be 0–65535 (0 = any port).');
            }
            $data['sip_port'] = $port;
            // IP peers: clear digest secrets from portal + OpenSIPS
            $data['password_enc'] = null;
            $data['ha1'] = str_repeat('0', 32);
            $data['ha1b'] = str_repeat('0', 32);
            $data['password_changed_at'] = gmdate('Y-m-d H:i:s');
            $data['_clear_sip_secret'] = true;
            $pw = null; // ignore any posted password in IP mode
        } else {
            $data['sip_host'] = 'dynamic';
            $switchingFromIp = $existing !== null && $prevMode === 'ip';
            if ($pw !== null && $pw !== '' && mb_strlen((string) $pw) < 12) {
                throw new \DomainException('SIP password must be at least 12 characters.');
            }
            if ($switchingFromIp && ($pw === null || $pw === '')) {
                throw new \DomainException('Switching to Dynamic mode requires a new SIP password (min 12 characters).');
            }
            if ($existing === null) {
                $data['domain'] = $domain;
                $pw = ($pw === null || $pw === '') ? self::generatePassword() : $pw;
                $username = (string) $data['username'];
            } else {
                $username = (string) $existing['username'];
                if ($pw !== null && $pw !== '') {
                    $data['domain'] = $domain;
                } else {
                    $domain = (string) $existing['domain'];
                }
            }
            if ($pw !== null && $pw !== '') {
                [$ha1, $ha1b] = FreeswitchProvisioner::ha1($username, $domain, (string) $pw);
                $data['ha1'] = $ha1;
                $data['ha1b'] = $ha1b;
                $data['password_changed_at'] = gmdate('Y-m-d H:i:s');
                $this->plainPassword = (string) $pw;
                try {
                    $data['password_enc'] = Crypto::encrypt((string) $pw);
                } catch (\Throwable $e) {
                    throw new \DomainException('Could not store SIP password: ' . $e->getMessage());
                }
            }
        }

        if ($mode === 'ip') {
            if ($existing === null) {
                $data['domain'] = $domain;
            } else {
                $data['domain'] = (string) ($existing['domain'] ?? $domain);
            }
        }

        if (array_key_exists('media_policy', $data)) {
            $data['media_policy'] = MediaPolicy::normalizeStored(
                isset($data['media_policy']) ? (string) $data['media_policy'] : null
            );
        }
        if (array_key_exists('codecs', $data)) {
            $stored = SipCodecs::toStorage($data['codecs'] ?? null);
            if ($stored === null) {
                // Create → Settings default. Edit with blank chips → keep previous (avoid gateway/XML churn).
                $data['codecs'] = $existing === null
                    ? SipCodecs::defaultStorage($this->db())
                    : ($existing['codecs'] ?? null);
            } else {
                $data['codecs'] = $stored;
            }
        } elseif ($existing === null) {
            $data['codecs'] = SipCodecs::defaultStorage($this->db());
        }
        $data['dst_format'] = DstFormat::normalize(
            isset($data['dst_format']) ? (string) $data['dst_format'] : null
        );
        $tg = $data['routing_plan_id'] ?? null;
        $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;
        if (!array_key_exists('dnc_group_id', $data) || $data['dnc_group_id'] === '' || $data['dnc_group_id'] === null) {
            $data['dnc_group_id'] = null;
        } else {
            $data['dnc_group_id'] = (int) $data['dnc_group_id'];
            if ($data['dnc_group_id'] <= 0) {
                $data['dnc_group_id'] = null;
            } else {
                $opts = $this->dncGroupOptions();
                if (!isset($opts[(string) $data['dnc_group_id']])) {
                    throw new \DomainException('Select a valid DNC group.');
                }
            }
        }

        $gid = $data['callerid_group_id'] ?? null;
        if ($gid === null || $gid === '' || (int) $gid <= 0) {
            $data['callerid_group_id'] = null;
        } else {
            $data['callerid_group_id'] = (int) $gid;
            if (!isset($this->callerIdGroupOptions($r)[(string) $data['callerid_group_id']])) {
                throw new \DomainException('Select a valid CallerID group.');
            }
            $gst = $this->db()->prepare('SELECT customer_id FROM callerid_groups WHERE id = ?');
            $gst->execute([$data['callerid_group_id']]);
            $groupCustomer = $gst->fetchColumn();
            $ownerCustomer = (int) ($data['customer_id'] ?? ($existing['customer_id'] ?? 0));
            if ($groupCustomer !== false && $groupCustomer !== null && (int) $groupCustomer !== $ownerCustomer) {
                throw new \DomainException('This CallerID group belongs to a different customer.');
            }
        }

        $cid = trim((string) ($data['caller_id'] ?? ''));
        $data['caller_id'] = $cid !== '' ? $cid : null;
        $cidMode = strtolower(trim((string) ($data['caller_id_mode'] ?? 'passthrough')));
        if (!isset(SipPeerOptions::CALLER_ID_MODE[$cidMode])) {
            $cidMode = $cid !== '' ? 'fixed' : 'passthrough';
        }
        // Fixed mode with empty number → treat as passthrough so ViciDial CLI is kept
        if ($cidMode === 'fixed' && $cid === '') {
            $cidMode = 'passthrough';
        }
        $data['caller_id_mode'] = $cidMode;
        foreach (['cid_min_len', 'cid_max_len'] as $k) {
            if (!array_key_exists($k, $data) || $data[$k] === '' || $data[$k] === null) {
                $data[$k] = null;
            } else {
                $data[$k] = max(0, (int) $data[$k]);
                if ($data[$k] === 0) {
                    $data[$k] = null;
                }
            }
        }
        $dp = strtolower(trim((string) ($data['dtmf_pass'] ?? 'yes')));
        $data['dtmf_pass'] = $dp === 'no' ? 'no' : 'yes';
        $dtmf = (string) ($data['dtmfmode'] ?? 'RFC2833');
        if (!isset(SipPeerOptions::DTMF[$dtmf])) {
            $dtmf = 'RFC2833';
        }
        $data['dtmfmode'] = $dtmf;
        if (array_key_exists('max_cps', $data)) {
            $cps = (int) $data['max_cps'];
            if ($cps < -1) {
                $cps = -1;
            }
            if ($cps > 1000) {
                $cps = 1000;
            }
            // 0 is meaningless for rate limit → treat as 1
            $data['max_cps'] = $cps === 0 ? 1 : $cps;
        }
        if (array_key_exists('max_call_duration', $data)) {
            $mcd = (int) $data['max_call_duration'];
            if ($mcd < 0) {
                $mcd = 0;
            }
            if ($mcd > 604800) {
                $mcd = 604800;
            }
            // 1–59 → treat as mis-entry; bump to 60 when non-zero
            if ($mcd > 0 && $mcd < 60) {
                $mcd = 60;
            }
            $data['max_call_duration'] = $mcd;
        }
        if (!array_key_exists('rate_cap', $data) || $data['rate_cap'] === '' || $data['rate_cap'] === null) {
            $data['rate_cap'] = null;
        } else {
            $cap = (float) $data['rate_cap'];
            $data['rate_cap'] = $cap > 0 ? number_format($cap, 8, '.', '') : null;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $row = $this->repo()->findInternal($id);
        $prov = FreeswitchProvisioner::fromConfig();
        $fw = new \Ownswitch\Services\System\SipPeerFirewallService();
        $connMode = $this->resolveConnectionMode($row ?? $data);
        if ($row) {
            if (!$prov->enabled()) {
                App::session()->flash('warning', 'Saved in portal, but FreeSWITCH is not available: '
                    . ($prov->initError() !== '' ? $prov->initError() : 'ESL password missing'));
            } else {
                try {
                    if ($row['status'] === 'active') {
                        $effective = MediaPolicy::resolve(
                            isset($row['media_policy']) ? (string) $row['media_policy'] : null,
                            $this->db()
                        );
                        $row['_effective_media'] = $effective;
                        if ($connMode === 'ip') {
                            $row['_clear_sip_secret'] = true;
                            $row['connection_mode'] = 'ip';
                        } elseif ($this->plainPassword !== null) {
                            $row['_plain_password'] = $this->plainPassword;
                        }
                        $prov->upsertSubscriber(
                            (string) $row['username'],
                            (string) $row['domain'],
                            (string) $row['ha1'],
                            (string) $row['ha1b'],
                            $row
                        );
                        $prov->syncSipPeerProfile(
                            (string) $row['username'],
                            $effective === 'record' ? 'record' : $effective,
                            (int) ($row['max_channels'] ?? 2),
                            $row
                        );
                        $prov->reloadAddresses();
                    } else {
                        $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
                        $prov->removeSipPeerProfile((string) $row['username']);
                        $prov->reloadAddresses();
                    }
                } catch (\Throwable $e) {
                    error_log('[ownswitch] FreeSWITCH provision SIP user: ' . $e->getMessage());
                    App::session()->flash('warning', 'Saved, but FreeSWITCH provisioning failed: ' . mb_substr($e->getMessage(), 0, 240));
                }
            }

            // Firewall: IP peers get allow rules for every listed IP; dynamic / disabled remove them
            try {
                if ($row['status'] === 'active' && $connMode === 'ip') {
                    $peerPort = (int) ($row['sip_port'] ?? 5060);
                    $res = $fw->syncMany(
                        (string) $row['username'],
                        SipHosts::parse((string) ($row['sip_host'] ?? '')),
                        $peerPort
                    );
                    if (!$res['ok']) {
                        App::session()->flash('warning', 'SIP saved, but firewall sync failed: ' . mb_substr($res['detail'], 0, 200));
                    }
                } else {
                    $fw->remove((string) $row['username']);
                }
            } catch (\Throwable $e) {
                error_log('[ownswitch] SIP peer firewall: ' . $e->getMessage());
                App::session()->flash('warning', 'SIP saved, but firewall update failed.');
            }
        }
        if ($this->plainPassword !== null && $row && $connMode === 'dynamic') {
            $realm = (string) App::config('app.integrations.opensips.sip_domain', (string) $row['domain']);
            $proxy = trim((string) App::config('app.integrations.opensips.public_ip', ''));
            if ($proxy === '') {
                $proxy = $realm;
            }
            $port = (int) ($row['sip_port'] ?? 5060);
            if ($port <= 0) {
                $port = 5060;
            }
            return "SIP user saved. Softphone (no Proxy): User={$row['username']}  Pass={$this->plainPassword}  "
                . "Domain={$proxy} OR {$realm} OR any FQDN→{$proxy}  Port={$port} UDP. "
                . "Magnus-style: username+password+IP/FQDN — leave Outbound Proxy blank.";
        }
        if ($row && $connMode === 'ip' && $row['status'] === 'active') {
            $ips = SipHosts::parse((string) ($row['sip_host'] ?? ''));
            $peerPort = (int) ($row['sip_port'] ?? 5060);
            return 'IP based SIP peer saved. Allowed IPs=' . implode(', ', $ips)
                . " port={$peerPort}. Firewall updated for each IP.";
        }
        if ($row && $connMode === 'dynamic' && empty($row['password_enc'])) {
            App::session()->flash(
                'warning',
                'Re-save this SIP user with a password once so FreeSWITCH can accept Domain=FQDN and Domain=IP.'
            );
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
                $prov->removeSipPeerProfile((string) $row['username']);
                $prov->reloadAddresses();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but FreeSWITCH deprovisioning failed.');
            }
        }
        try {
            (new \Ownswitch\Services\System\SipPeerFirewallService())->remove((string) $row['username']);
        } catch (\Throwable) {
        }
    }

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if (MediaPolicy::isDefault(isset($values['media_policy']) ? (string) $values['media_policy'] : null)) {
            $values['media_policy'] = '';
        }
        $values['connection_mode'] = $this->resolveConnectionMode($values + ($existing ?? []));
        if (($values['connection_mode'] ?? '') === 'dynamic') {
            $values['sip_host'] = 'dynamic';
        } else {
            $values['sip_host'] = SipHosts::forForm((string) ($values['sip_host'] ?? ''));
        }
        if (!isset($values['max_cps']) || $values['max_cps'] === '' || $values['max_cps'] === null) {
            $values['max_cps'] = $existing['max_cps'] ?? '1';
        }
        if ($existing === null && (trim((string) ($values['codecs'] ?? '')) === '')) {
            $values['codecs'] = SipCodecs::defaultStorage($this->db());
        }
        return $values;
    }

    /** @param array<string,mixed> $row */
    private function resolveConnectionMode(array $row): string
    {
        $mode = strtolower(trim((string) ($row['connection_mode'] ?? '')));
        if (isset(SipPeerOptions::CONNECTION_MODE[$mode])) {
            return $mode;
        }
        return SipHosts::parse((string) ($row['sip_host'] ?? '')) !== [] ? 'ip' : 'dynamic';
    }

    public static function generatePassword(int $bytes = 15): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Ab'), '=') . '7';
    }
}
