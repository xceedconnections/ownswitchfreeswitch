<?php

declare(strict_types=1);

namespace Ownswitch\Services\Switching;

use Ownswitch\Http\Request;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Cdr\CdrIngestService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Settings\SettingsService;
use PDO;

/**
 * Same portal call flow, executed by FreeSWITCH xml_curl instead of OpenSIPS.
 * REGISTER is the directory. INVITE is this dialplan: user, pause/balance, DNC, rate cap, trunk waterfall, DID.
 */
final class FreeswitchCallFlow
{
    public function __construct(private PDO $db)
    {
    }

    public function dialplan(Request $r): string
    {
        $dest = preg_replace('/\D+/', '', (string) $r->input('Caller-Destination-Number', '')) ?? '';
        $user = $this->sipUserFromRequest($r);
        $ip = (string) $r->input('variable_sip_network_ip', $r->input('network_addr', $r->input('ip', '')));
        $account = $this->findAccount($user, $ip);
        $didUser = $dest !== '' ? $this->didOwner($dest) : null;

        if ($didUser !== null && ($account === null || strcasecmp((string) $account['username'], $didUser) !== 0)) {
            return $this->bridgeUser($didUser);
        }
        if ($account === null) {
            return $this->reject('403', 'CALL_REJECTED');
        }
        if (!$this->allowsCalls($account)) {
            return $this->reject('402', 'CALL_REJECTED');
        }
        if ($dest === '') {
            return $this->reject('484', 'INVALID_NUMBER_FORMAT');
        }
        $groupId = (int) ($account['dnc_group_id'] ?? 0);
        if ($groupId > 0 && $this->dncHit($groupId, $dest)) {
            return $this->reject('403', 'CALL_REJECTED');
        }
        $callId = trim((string) $r->input('variable_sip_call_id', $r->input('Unique-ID', '')));
        if ($callId === '') {
            $callId = 'fs-' . bin2hex(random_bytes(8));
        }
        $maxSeconds = $this->prepaidGate($account, $dest, $callId);
        if ($maxSeconds === false) {
            return $this->reject('402', 'CALL_REJECTED');
        }
        $planId = (int) ($account['routing_plan_id'] ?? 0);
        if ($planId <= 0) {
            $planId = $this->customerPlanId((int) $account['customer_id']);
        }
        $trunks = $this->trunksFor($planId, $dest);
        if ($trunks === []) {
            return $this->reject('503', 'NORMAL_TEMPORARY_FAILURE');
        }
        $media = strtolower((string) ($account['media_policy'] ?? 'relay')) === 'direct' ? 'true' : 'false';
        $bridges = [];
        foreach ($trunks as $t) {
            $tid = (int) $t['id'];
            $bridges[] = 'sofia/gateway/gw-' . $tid . '/' . $dest;
        }
        $data = implode('|', $bridges);
        $userName = (string) $account['username'];
        $cap = $maxSeconds > 0
            ? '<action application="sched_hangup" data="+' . $maxSeconds . ' ALLOTTED_TIMEOUT"/>'
            : '';
        return $this->extension(
            'outbound',
            '.*',
            '<action application="set" data="bypass_media=' . $media . '"/>'
            . '<action application="set" data="hangup_after_bridge=true"/>'
            . '<action application="set" data="continue_on_fail=408,480,486,487,500,503,NORMAL_TEMPORARY_FAILURE,RECOVERY_ON_TIMER_EXPIRE,TIMEOUT,USER_BUSY,NO_ANSWER,CALL_REJECTED,UNALLOCATED_NUMBER"/>'
            . '<action application="set" data="fail_on_single_reject=true"/>'
            . '<action application="set" data="os_sip_user=' . $this->x($userName) . '"/>'
            . $cap
            . '<action application="bridge" data="' . $this->x($data) . '"/>'
        );
    }

    public function ingestCdr(string $xml): void
    {
        if ($xml === '' || !str_contains($xml, '<cdr')) {
            return;
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return;
        }
        $vars = [];
        foreach ($sx->variables->children() ?? [] as $name => $node) {
            $vars[(string) $name] = (string) $node;
        }
        $bill = (int) ($vars['billsec'] ?? $vars['billsec_int'] ?? 0);
        $dest = (string) ($vars['destination_number'] ?? $vars['sip_to_user'] ?? '');
        $src = (string) ($vars['os_sip_user'] ?? $vars['username'] ?? $vars['sip_from_user'] ?? '');
        $uuid = (string) ($vars['uuid'] ?? '');
        $cause = strtoupper((string) ($vars['hangup_cause'] ?? 'NORMAL_CLEARING'));
        $disp = $bill > 0 ? 'ANSWERED' : match (true) {
            str_contains($cause, 'BUSY') => 'BUSY',
            str_contains($cause, 'NO_ANSWER'), str_contains($cause, 'NO_USER') => 'NO_ANSWER',
            str_contains($cause, 'CANCEL') => 'CANCELLED',
            default => 'FAILED',
        };
        $start = (string) ($vars['start_stamp'] ?? $vars['start_epoch'] ?? '');
        if ($start !== '' && ctype_digit($start)) {
            $start = gmdate('Y-m-d H:i:s', (int) $start);
        }
        $gw = (string) ($vars['sofia_profile_name'] ?? '');
        $trunkId = null;
        if (preg_match('/gw-(\d+)/', (string) ($vars['last_bridge_to'] ?? $vars['bridge_channel'] ?? ''), $m)) {
            $trunkId = (int) $m[1];
        }
        unset($gw);
        $svc = new CdrIngestService(
            $this->db,
            new RatingService(),
            new RateResolver($this->db, new SettingsService($this->db)),
            new LedgerService($this->db),
            new PrepaidService($this->db, new LedgerService($this->db), new RatingService()),
            new SettingsService($this->db),
            new \Ownswitch\Repositories\CdrRepository($this->db),
        );
        $svc->ingest([
            'external_cdr_key' => $uuid !== '' ? $uuid : md5($xml),
            'uuid' => $uuid,
            'disposition' => $disp,
            'dst' => $dest,
            'src' => $src,
            'username' => $src,
            'sip_call_id' => (string) ($vars['sip_call_id'] ?? $uuid),
            'setup_at' => $start !== '' ? $start : gmdate('Y-m-d H:i:s'),
            'duration' => (string) max(0, $bill),
            'carrier_trunk_id' => $trunkId,
            'source_ip' => (string) ($vars['sip_network_ip'] ?? ''),
        ], 'freeswitch');
    }

    /** @return array<string,mixed>|null */
    private function findAccount(string $user, string $ip): ?array
    {
        if ($user !== '') {
            $st = $this->db->prepare("SELECT * FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1");
            $st->execute([$user]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $st = $this->db->prepare(
                "SELECT * FROM sip_accounts WHERE status = 'active' AND connection_mode = 'ip' AND sip_host = ? LIMIT 1"
            );
            $st->execute([$ip]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
            try {
                $st = $this->db->prepare(
                    "SELECT s.* FROM ip_endpoints e
                     JOIN sip_accounts s ON s.customer_id = e.customer_id AND s.status = 'active'
                     WHERE e.status = 'active' AND e.ip_address = ?
                     ORDER BY (s.connection_mode = 'ip') DESC, s.id ASC
                     LIMIT 1"
                );
                $st->execute([$ip]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /**
     * Prepaid: reserve through PrepaidService. false = refuse (402).
     * 0 = no hard cap (postpaid, or no tariff yet — CDR still rates the call).
     */
    private function prepaidGate(array $account, string $dest, string $callId): int|false
    {
        $cid = (int) ($account['customer_id'] ?? 0);
        if ($cid <= 0) {
            return 0;
        }
        $st = $this->db->prepare(
            'SELECT id, billing_mode, credit_limit, currency, reseller_id, status FROM customers WHERE id = ? LIMIT 1'
        );
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || strtolower((string) $c['status']) !== 'active') {
            return false;
        }
        if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
            return 0;
        }
        $rate = (new RateResolver($this->db, new SettingsService($this->db)))->customer($cid, $dest);
        if ($rate === null) {
            return 0;
        }
        try {
            $auth = (new PrepaidService($this->db, new LedgerService($this->db), new RatingService()))->authorize(
                [
                    'id' => $cid,
                    'billing_mode' => (string) $c['billing_mode'],
                    'credit_limit' => (string) ($c['credit_limit'] ?? '0'),
                    'currency' => (string) ($c['currency'] ?? 'USD'),
                    'reseller_id' => $c['reseller_id'] !== null ? (int) $c['reseller_id'] : null,
                ],
                $callId,
                (string) $rate['rate'],
                (int) $rate['initial'],
                (int) $rate['subsequent'],
                (string) $rate['connect_fee']
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] fs prepaid: ' . $e->getMessage());
            return $this->allowsCalls($account) ? 0 : false;
        }
        if (empty($auth['authorized'])) {
            return false;
        }
        return (int) ($auth['max_seconds'] ?? 0);
    }

    private function sipUserFromRequest(Request $r): string
    {
        foreach (['variable_os_sip_user', 'Caller-Username', 'variable_sip_auth_username', 'Hunt-Username', 'user'] as $k) {
            $v = trim((string) $r->input($k, ''));
            if ($v !== '' && !str_contains($v, '@')) {
                return $v;
            }
            if (str_contains($v, '@')) {
                return explode('@', $v, 2)[0];
            }
        }
        return '';
    }

    /** @param array<string,mixed> $account */
    private function allowsCalls(array $account): bool
    {
        if (strtolower((string) ($account['status'] ?? '')) !== 'active') {
            return false;
        }
        if (isset($account['allow_calls']) && (string) $account['allow_calls'] === '0') {
            return false;
        }
        $cid = (int) ($account['customer_id'] ?? 0);
        if ($cid <= 0) {
            return true;
        }
        $st = $this->db->prepare('SELECT billing_mode, status FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || strtolower((string) $c['status']) !== 'active') {
            return false;
        }
        if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
            return true;
        }
        $b = $this->db->prepare('SELECT balance, reserved FROM balances WHERE customer_id = ? LIMIT 1');
        $b->execute([$cid]);
        $row = $b->fetch(PDO::FETCH_ASSOC) ?: ['balance' => '0', 'reserved' => '0'];
        return ((float) $row['balance'] - (float) $row['reserved']) > 0.000001;
    }

    private function dncHit(int $groupId, string $digits): bool
    {
        $alts = [$digits];
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $alts[] = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $alts[] = '1' . $digits;
        }
        $in = implode(',', array_fill(0, count($alts), '?'));
        $st = $this->db->prepare("SELECT 1 FROM dnc_numbers WHERE dnc_group_id = ? AND number_digits IN ($in) LIMIT 1");
        $st->execute(array_merge([$groupId], $alts));
        return (bool) $st->fetchColumn();
    }

    private function customerPlanId(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        $st = $this->db->prepare('SELECT routing_plan_id FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$customerId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /** @return list<array<string,mixed>> */
    private function trunksFor(int $planId, string $dest): array
    {
        if ($planId <= 0) {
            return [];
        }
        $st = $this->db->prepare(
            "SELECT t.* FROM routing_rules r
             JOIN carrier_trunks t ON t.id = r.carrier_trunk_id
             WHERE r.routing_plan_id = ? AND r.status = 'active' AND t.status = 'active'
               AND (r.prefix = '*' OR ? LIKE CONCAT(r.prefix, '%'))
             ORDER BY CHAR_LENGTH(r.prefix) DESC, r.priority ASC"
        );
        $st->execute([$planId, $dest]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $groups = [];
        foreach ($rows as $row) {
            $len = ($row['prefix'] ?? '*') === '*' ? 0 : strlen((string) $row['prefix']);
            $groups[$len][] = $row;
        }
        krsort($groups);
        foreach ($groups as $list) {
            $out = [];
            foreach ($list as $row) {
                if ($this->overCap($row, $dest) || $this->trunkDnc($row, $dest)) {
                    continue;
                }
                $out[] = $row;
            }
            if ($out !== []) {
                return $out;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $trunk */
    private function overCap(array $trunk, string $dest): bool
    {
        $cap = $trunk['rate_cap'] ?? null;
        if ($cap === null || $cap === '' || (float) $cap <= 0) {
            return false;
        }
        $cid = (int) ($trunk['carrier_id'] ?? 0);
        if ($cid <= 0) {
            return false;
        }
        $st = $this->db->prepare(
            "SELECT rate FROM carrier_tariffs
             WHERE carrier_id = ? AND status = 'active' AND ? LIKE CONCAT(prefix, '%')
             ORDER BY CHAR_LENGTH(prefix) DESC LIMIT 1"
        );
        $st->execute([$cid, $dest]);
        $rate = $st->fetchColumn();
        if ($rate === false) {
            return false;
        }
        return (float) $rate > (float) $cap;
    }

    /** @param array<string,mixed> $trunk */
    private function trunkDnc(array $trunk, string $dest): bool
    {
        $gid = (int) ($trunk['dnc_group_id'] ?? 0);
        return $gid > 0 && $this->dncHit($gid, $dest);
    }

    private function didOwner(string $digits): ?string
    {
        $st = $this->db->prepare(
            "SELECT s.username FROM dids d
             JOIN did_assignments a ON a.did_id = d.id AND a.status = 'active'
             LEFT JOIN sip_accounts s ON s.id = a.sip_account_id
             WHERE d.number_norm = ? OR d.number_e164 = ? LIMIT 1"
        );
        try {
            $st->execute([$digits, '+' . $digits]);
        } catch (\Throwable) {
            return null;
        }
        $u = $st->fetchColumn();
        return is_string($u) && $u !== '' ? $u : null;
    }

    private function bridgeUser(string $username): string
    {
        return $this->extension(
            'inbound-did',
            '.*',
            '<action application="set" data="hangup_after_bridge=true"/>'
            . '<action application="bridge" data="user/' . $this->x($username) . '"/>'
        );
    }

    private function reject(string $code, string $cause): string
    {
        return $this->extension(
            'reject',
            '.*',
            '<action application="respond" data="' . $code . '"/>'
            . '<action application="hangup" data="' . $cause . '"/>'
        );
    }

    private function extension(string $name, string $expr, string $actions): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<document type="freeswitch/xml"><section name="dialplan"><context name="ownswitch">'
            . '<extension name="' . $this->x($name) . '">'
            . '<condition field="destination_number" expression="' . $expr . '">'
            . $actions
            . '</condition></extension></context></section></document>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
