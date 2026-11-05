<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CarrierRepository;
use Ownswitch\Services\Routing\OpensipsRoutingSync;
use Ownswitch\Services\Sip\SipOptionsProbe;
use Ownswitch\Services\Dnc\DncService;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\SipPeerOptions;

/**
 * Providers + Trunks — FreeSWITCH Sofia gateway fields (no Asterisk peer junk).
 */
final class CarrierController extends CrudController
{
    public function __construct(private string $kind = 'carrier')
    {
        $trunk = $kind === 'trunk';
        $this->base = $trunk ? '/trunks' : '/providers';
        $this->singular = $trunk ? 'Trunk' : 'Provider';
        $this->plural = $trunk ? 'Trunks' : 'Providers';
        $this->emptyHint = $trunk
            ? 'No trunks. FreeSWITCH: Host/port, optional digest REGISTER, strip/prefix, From rewrite, OPTIONS ping.'
            : 'No providers yet. Add the upstream carrier, then add Trunks.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'trunk' ? CarrierRepository::trunks($this->db()) : CarrierRepository::carriers($this->db());
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'trunk' ? ['carrier_id', 'status', 'auth_mode'] : ['status'];
    }

    protected function fields(Request $r): array
    {
        DstFormat::ensureColumns($this->db());
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true];
        if ($this->kind !== 'trunk') {
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'notes', 'label' => 'Description', 'type' => 'textarea', 'max' => 2000],
                $status,
            ];
        }
        $carriers = [];
        foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
            $carriers[$c['id']] = $c['name'];
        }
        return [
            ['name' => 'carrier_id', 'label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'host', 'label' => 'Host', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'default' => 'udp'],
            ['name' => 'auth_mode', 'label' => 'Auth', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'ip', 'options' => [
                'ip' => 'IP peer (vendor allowlists us)',
                'register' => 'Outbound REGISTER (user/pass)',
                'invite' => 'Invite digest only',
            ], 'list_options' => [
                'ip' => 'IP peer',
                'register' => 'REGISTER',
                'invite' => 'Invite digest',
            ], 'help' => 'FreeSWITCH: IP = no auth; Register = Sofia REGISTER; Invite = digest on outbound only'],
            ['name' => 'auth_username', 'label' => 'Username', 'max' => 120],
            ['name' => 'auth_secret', 'label' => 'Password', 'type' => 'password', 'max' => 190, 'help' => 'Leave blank on edit to keep current.'],
            ['name' => 'add_prefix', 'label' => 'Add prefix', 'max' => 32, 'help' => 'Prepended to the dialled number toward the vendor (e.g. 9 → 912138007447)'],
            ['name' => 'remove_prefix', 'label' => 'Remove prefixes', 'max' => 120, 'help' => 'Comma-separated tech prefixes to strip before send (e.g. 22,011,00). First match wins. Then Add prefix is applied.'],
            ['name' => 'from_user', 'label' => 'From user', 'max' => 64, 'help' => 'Forces outbound CallerID / From user to vendors. Overrides SIP user CallerID and anything the PBX (e.g. ViciDial) sends.'],
            ['name' => 'from_domain', 'label' => 'From domain', 'max' => 128],
            ['name' => 'cid_add_prefix', 'label' => 'CID add prefix', 'max' => 32, 'help' => 'Prepended to outbound CallerID digits after CID remove (FreeSWITCH origination_caller_id_*).'],
            ['name' => 'cid_remove_prefix', 'label' => 'CID remove prefix', 'max' => 32, 'help' => 'Strip this digit prefix from CallerID before CID add / From user.'],
            ['name' => 'cid_min_len', 'label' => 'Min CallerID length (digits)', 'type' => 'number', 'min' => 0, 'max' => 32, 'placeholder' => '11', 'help' => 'After trunk CID rewrite (and From user if set). USA+CC = 11. Blank/0 = off. Too short → 484.'],
            ['name' => 'cid_max_len', 'label' => 'Max CallerID length (digits)', 'type' => 'number', 'min' => 0, 'max' => 32, 'placeholder' => '11', 'help' => 'After trunk CID rewrite. USA = 11. Blank/0 = off. Too long → 484.'],
            ['name' => 'qualify', 'label' => 'Probe gateway', 'type' => 'select', 'options' => SipPeerOptions::YES_NO, 'default' => 'yes', 'help' => 'FreeSWITCH Sofia gateway ping=30s (OPTIONS). No = ping disabled.'],
            ['name' => 'max_channels', 'label' => 'Channels', 'type' => 'int', 'min' => 0, 'max' => 1000000, 'required' => true, 'default' => '100', 'list' => true, 'help' => 'Capacity hint for portal/reporting (not a hard FreeSWITCH gateway cap).'],
            ['name' => 'max_call_duration', 'label' => 'Max call duration (s)', 'type' => 'int', 'min' => 0, 'max' => 604800, 'default' => '0', 'list' => true, 'help' => '0 = no trunk leg timeout. >0 sets FreeSWITCH leg_timeout on the gateway bridge. SIP user / prepaid caps still apply.'],
            ['name' => 'rate_cap', 'label' => 'CAP (max buy $/min)', 'type' => 'number', 'min' => 0, 'placeholder' => '0.008', 'list' => true, 'help' => 'Compared to this provider’s buy rate for the dialled prefix — independent of SIP user CAP. Example: CAP 0.008 allows buy 1213@0.005, skips this trunk for 1456@0.009 (try next trunk). Blank = no CAP.'],
            ['name' => 'dnc_group_id', 'label' => 'DNC group', 'type' => 'select', 'options' => $this->dncGroupOptions(), 'list' => true, 'help' => 'Instant 403 before the vendor is INVITEd when the dialled number is on this list. Prefer also assigning the same group on the SIP user for a pre-routing block. Leave blank for no trunk DNC.'],
            ['name' => 'dst_format', 'label' => 'Destination format', 'type' => 'select', 'options' => DstFormat::OPTIONS, 'default' => DstFormat::AS_IS, 'list' => true, 'help' => 'How the dialled number is sent to this vendor after strip/add prefix. Applied after the SIP user’s Destination format. Example: E.164 with + turns 12135550123 into +12135550123.'],
            ['name' => 'latency_ms', 'label' => 'Reg / Latency', 'list' => true, 'list_only' => true],
            $status,
        ];
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

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($this->kind !== 'trunk' || $rows === []) {
            return $rows;
        }
        $prov = FreeswitchProvisioner::fromConfig();
        $fsOk = $prov->enabled();
        foreach ($rows as &$row) {
            if (($row['dnc_group_id'] ?? null) === null || $row['dnc_group_id'] === '') {
                $row['dnc_group_id'] = '';
            } else {
                $row['dnc_group_id'] = (string) $row['dnc_group_id'];
            }
            $host = (string) ($row['host'] ?? '');
            $port = (int) ($row['port'] ?? 5060);
            $active = ($row['status'] ?? '') === 'active';
            $mode = strtolower((string) ($row['auth_mode'] ?? 'ip'));
            if (!$active || $host === '') {
                $row['latency_ms'] = '—';
                continue;
            }
            // REGISTER trunks: Sofia state + OPTIONS PingTime (ms).
            if ($mode === 'register' && $fsOk) {
                $st = $prov->gatewayStatus((int) ($row['id'] ?? 0));
                $state = strtoupper((string) ($st['state'] ?? ''));
                $ms = (int) ($st['latency_ms'] ?? 0);
                $online = in_array($state, ['REGED', 'NOREG'], true)
                    || strtoupper((string) ($st['status'] ?? '')) === 'UP';
                // Presence-style cell: green/red dot + latency ms (falls back to state label).
                $row['latency_ms'] = [
                    'online' => $online,
                    'ms' => $ms > 0 ? $ms : null,
                    'state' => $st['label'] !== '' ? (string) $st['label'] : $state,
                ];
                $row['register_status'] = $state;
                continue;
            }
            $qualify = strtolower((string) ($row['qualify'] ?? 'yes')) === 'yes';
            if (!$qualify) {
                $row['latency_ms'] = '—';
                continue;
            }
            $ms = SipOptionsProbe::rttMs($host, $port);
            $row['latency_ms'] = ($ms !== null) ? $ms : 'n/a';
        }
        unset($row);
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'trunk') {
            $secret = $data['auth_secret'] ?? null;
            unset($data['auth_secret']);
            if ($secret !== null && $secret !== '') {
                try {
                    $data['auth_secret_enc'] = Crypto::encrypt((string) $secret);
                } catch (\RuntimeException $e) {
                    throw new \DomainException('Cannot store the secret: ' . $e->getMessage());
                }
            }
            if ($existing === null) {
                $data['priority'] = $data['priority'] ?? 100;
                $data['connect_fee'] = $data['connect_fee'] ?? '0';
                $data['initial_increment'] = $data['initial_increment'] ?? 6;
                $data['subsequent_increment'] = $data['subsequent_increment'] ?? 6;
                $data['media_policy'] = null;
            }
            foreach (['add_prefix', 'from_user', 'from_domain', 'cid_add_prefix', 'cid_remove_prefix'] as $k) {
                if (array_key_exists($k, $data) && ($data[$k] === null || $data[$k] === '')) {
                    $data[$k] = null;
                }
            }
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
            if (!array_key_exists('rate_cap', $data) || $data['rate_cap'] === '' || $data['rate_cap'] === null) {
                $data['rate_cap'] = null;
            } else {
                $cap = (float) $data['rate_cap'];
                $data['rate_cap'] = $cap > 0 ? number_format($cap, 8, '.', '') : null;
            }
            if (array_key_exists('max_call_duration', $data)) {
                $mcd = (int) $data['max_call_duration'];
                if ($mcd < 0) {
                    $mcd = 0;
                }
                if ($mcd > 604800) {
                    $mcd = 604800;
                }
                if ($mcd > 0 && $mcd < 60) {
                    $mcd = 60;
                }
                $data['max_call_duration'] = $mcd;
            }
            if (array_key_exists('remove_prefix', $data)) {
                $data['remove_prefix'] = self::normalizeRemovePrefixes((string) ($data['remove_prefix'] ?? ''));
            }
            if (!array_key_exists('dnc_group_id', $data) || $data['dnc_group_id'] === '' || $data['dnc_group_id'] === null) {
                $data['dnc_group_id'] = null;
            } else {
                $data['dnc_group_id'] = (int) $data['dnc_group_id'];
                if ($data['dnc_group_id'] <= 0) {
                    $data['dnc_group_id'] = null;
                } else {
                    $opts = (new DncService($this->db()))->groupOptions(false);
                    if (!isset($opts[(string) $data['dnc_group_id']])) {
                        throw new \DomainException('Select a valid DNC group.');
                    }
                }
            }
            $data['dst_format'] = DstFormat::normalize(isset($data['dst_format']) ? (string) $data['dst_format'] : null);
            $mode = (string) ($data['auth_mode'] ?? 'ip');
            if (in_array($mode, ['register', 'invite'], true)) {
                $user = trim((string) ($data['auth_username'] ?? ($existing['auth_username'] ?? '')));
                $hasSecret = !empty($data['auth_secret_enc']) || !empty($existing['auth_secret_enc'] ?? null);
                if ($user === '' || (!$hasSecret && $existing === null)) {
                    throw new \DomainException('Register / Invite digest modes require Username and Password.');
                }
            }
        } else {
            $data = $this->autoCode($data, $existing, 'carriers');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind !== 'trunk') {
            return null;
        }
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return 'Trunk saved in portal only — FreeSWITCH ESL is not configured.';
        }
        try {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                $prov->syncTrunk($row);
            }
            $sync = (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            if ($sync['warnings'] !== []) {
                App::session()->flash('warning', 'Trunk saved with warnings: ' . mb_substr(implode(' | ', $sync['warnings']), 0, 300));
            }
            $mode = strtolower((string) ((is_array($row) ? ($row['auth_mode'] ?? null) : null) ?? ($data['auth_mode'] ?? 'ip')));
            if ($mode === 'register') {
                $st = $prov->gatewayStatus($id);
                return 'Trunk pushed to FreeSWITCH. REGISTER state: '
                    . ($st['label'] !== '' ? $st['label'] : 'pending')
                    . ' (REGED = registered to vendor).';
            }
            return 'Trunk pushed to FreeSWITCH (Sofia gateway). Routes stay in the portal.';
        } catch (\Throwable $e) {
            error_log('[ownswitch] FreeSWITCH trunk sync: ' . $e->getMessage());
            App::session()->flash('warning', 'Saved, but FreeSWITCH sync failed: ' . mb_substr($e->getMessage(), 0, 280));
            return null;
        }
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind !== 'trunk') {
            return;
        }
        $prov = FreeswitchProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeTrunk((int) $row['id']);
                (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but FreeSWITCH deprovisioning failed.');
            }
        }
    }

    /** Comma/space/semicolon separated digit prefixes → "22,011,00" or null. */
    public static function normalizeRemovePrefixes(string $raw): ?string
    {
        $parts = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $p) {
            $p = preg_replace('/\D+/', '', $p) ?? '';
            if ($p === '' || in_array($p, $parts, true)) {
                continue;
            }
            $parts[] = $p;
        }
        if ($parts === []) {
            return null;
        }
        // Longest first so "011" wins over "0" when both listed
        usort($parts, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return implode(',', $parts);
    }
}
