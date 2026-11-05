<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\CustomerRepository;

/** Live calls from OpenSIPS dialogs — stats, user filter, VICIdial-style colors. */
final class CallsOnlineController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $filterUser = trim((string) $r->query('user', ''));
        [$calls, $error] = $this->loadCalls($r, $filterUser !== '' ? $filterUser : null);
        $stats = [
            'total' => count($calls),
            'trying' => 0,
            'ringing' => 0,
            'connected' => 0,
            'other' => 0,
        ];
        foreach ($calls as $c) {
            $bucket = (string) ($c['bucket'] ?? 'other');
            if (isset($stats[$bucket])) {
                $stats[$bucket]++;
            } else {
                $stats['other']++;
            }
        }
        $users = [];
        if ($this->scope($r)->isGlobal() || $this->scope($r)->isStaff()) {
            foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
                $users[(string) $c['id']] = (string) $c['name'];
            }
            // Also offer SIP usernames
            try {
                foreach ($this->db()->query("SELECT username FROM sip_accounts WHERE status = 'active' ORDER BY username LIMIT 500")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $u) {
                    $users['sip:' . $u] = 'SIP ' . $u;
                }
            } catch (\Throwable) {
            }
        }
        return $this->view('calls/online', [
            'title' => 'Calls Online',
            'calls' => $calls,
            'error' => $error,
            'count' => count($calls),
            'stats' => $stats,
            'users' => $users,
            'filterUser' => $filterUser,
            'canHangup' => true,
        ]);
    }

    public function hangup(Request $r, array $p = []): Response
    {
        $callId = trim((string) $r->input('callid', ''));
        $fromTag = trim((string) $r->input('from_tag', ''));
        $dialogId = trim((string) $r->input('dialog_id', ''));
        if ($callId === '' && $dialogId === '') {
            return $this->redirect($r, '/calls-online', 'Missing Call-ID.', 'danger');
        }
        [$calls] = $this->loadCalls($r, null);
        $allowed = false;
        foreach ($calls as $c) {
            if (($callId !== '' && strcasecmp((string) ($c['callid'] ?? ''), $callId) === 0)
                || ($dialogId !== '' && (string) ($c['dialog'] ?? '') === $dialogId)) {
                $allowed = true;
                // Prefer live internal ID from current list (required by dlg_end_dlg)
                if (trim((string) ($c['dialog'] ?? '')) !== '') {
                    $dialogId = trim((string) $c['dialog']);
                }
                if ($fromTag === '' && trim((string) ($c['from_tag'] ?? '')) !== '') {
                    $fromTag = trim((string) $c['from_tag']);
                }
                if ($callId === '' && trim((string) ($c['callid'] ?? '')) !== '') {
                    $callId = trim((string) $c['callid']);
                }
                break;
            }
        }
        // Staff/global may hang up even if the row raced off the list (refresh vs click)
        if (!$allowed && !$this->scope($r)->isGlobal() && !$this->scope($r)->isStaff()) {
            return $this->redirect($r, '/calls-online', 'You can only hang up your own calls.', 'danger');
        }
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return $this->redirect($r, '/calls-online', 'FreeSWITCH is not configured.', 'danger');
        }
        $res = $prov->endDialog(
            $callId,
            $fromTag !== '' ? $fromTag : null,
            $dialogId !== '' ? $dialogId : null
        );
        return $this->redirect(
            $r,
            '/calls-online',
            $res['ok'] ? $res['detail'] : ('Hangup failed: ' . $res['detail']),
            $res['ok'] ? 'success' : 'danger'
        );
    }

    /** @return array{0:list<array<string,mixed>>,1:?string} */
    private function loadCalls(Request $r, ?string $filterUser): array
    {
        $prov = FreeswitchProvisioner::fromConfig();
        $error = null;
        $calls = [];
        if (!$prov->enabled()) {
            return [[], 'FreeSWITCH is not configured (ESL host / password).'];
        }
        try {
            $mine = $this->mySipUsernames($r);
            $filterTokens = $this->filterTokens($filterUser);
            foreach ($prov->listDialogs() as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $n = self::normalize($d);
                if ($n['callid'] === '') {
                    continue;
                }
                // ViciDial: From/os_src often = CallerID digits — map signalling IP → SIP username
                $sip = trim((string) ($n['sip_user'] ?? ''));
                if ($sip === '' || preg_match('/^[+]?[0-9]{8,30}$/', $sip)) {
                    $ip = self::callerSignallingIp($d);
                    if ($ip !== null) {
                        $byIp = $prov->usernameBySourceIp($ip);
                        if ($byIp !== null && $byIp !== '') {
                            $n['sip_user'] = $byIp;
                        }
                    }
                }
                if ($mine !== null) {
                    if (!$this->matchesUsers($n, $mine)) {
                        continue;
                    }
                }
                if ($filterTokens !== null && !$this->matchesUsers($n, $filterTokens)) {
                    continue;
                }
                $calls[] = $n;
            }
        } catch (\Throwable $e) {
            $error = 'Failed to query live dialogs: ' . $e->getMessage();
        }
        usort($calls, static fn ($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));
        return [$calls, $error];
    }

    /** @return list<string>|null */
    private function filterTokens(?string $filterUser): ?array
    {
        if ($filterUser === null || $filterUser === '') {
            return null;
        }
        if (str_starts_with($filterUser, 'sip:')) {
            return [strtolower(substr($filterUser, 4))];
        }
        if (ctype_digit($filterUser)) {
            $st = $this->db()->prepare('SELECT username FROM sip_accounts WHERE customer_id = ?');
            $st->execute([(int) $filterUser]);
            $list = array_map(static fn ($u) => strtolower((string) $u), $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            // Also match customer name tokens lightly via sip usernames only
            return $list !== [] ? $list : ['__none__'];
        }
        return [strtolower($filterUser)];
    }

    /** @return list<string>|null null = no filter (staff) */
    private function mySipUsernames(Request $r): ?array
    {
        $scope = $this->scope($r);
        if ($scope->isGlobal() || $scope->isStaff()) {
            return null;
        }
        $cid = $scope->customerId;
        if ($cid === null) {
            return [];
        }
        $st = $this->db()->prepare('SELECT username FROM sip_accounts WHERE customer_id = ?');
        $st->execute([$cid]);
        return array_map(static fn ($u) => strtolower((string) $u), $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $n @param list<string> $users */
    private function matchesUsers(array $n, array $users): bool
    {
        $fu = strtolower((string) $n['from_user']);
        $tu = strtolower((string) $n['to_user']);
        $sip = strtolower((string) ($n['sip_user'] ?? ''));
        $from = strtolower((string) $n['from']);
        $to = strtolower((string) $n['to']);
        foreach ($users as $u) {
            if ($u === '' || $u === '__none__') {
                continue;
            }
            if ($sip === $u || $fu === $u || $tu === $u || str_contains($from, $u) || str_contains($to, $u)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $d */
    private static function normalize(array $d): array
    {
        $from = (string) ($d['from_uri'] ?? $d['caller'] ?? $d['From'] ?? $d['from'] ?? $d['caller_uri'] ?? $d['fromuri'] ?? '');
        $to = (string) ($d['to_uri'] ?? $d['callee'] ?? $d['To'] ?? $d['to'] ?? $d['callee_uri'] ?? $d['touri'] ?? '');
        $sipUser = self::dlgVal($d, 'os_src');
        $dstDial = self::dlgVal($d, 'os_dst');
        if ($to === '' && $dstDial !== '') {
            $to = $dstDial;
        }
        $stateRaw = $d['state'] ?? $d['State'] ?? $d['dialog_state'] ?? $d['dlg_state'] ?? $d['status'] ?? $d['dlgstate'] ?? '';
        $start = (int) ($d['start_time'] ?? $d['timestart'] ?? $d['start'] ?? $d['start_ts'] ?? $d['starttime'] ?? 0);
        if ($start <= 0 && isset($d['start_time']) && is_string($d['start_time']) && strtotime($d['start_time']) !== false) {
            $start = (int) strtotime($d['start_time']);
        }
        $dur = (int) ($d['duration'] ?? $d['calltime'] ?? $d['Duration'] ?? $d['lifetime'] ?? 0);
        if ($dur <= 0 && $start > 0) {
            $dur = $start > 1_000_000_000 ? max(0, time() - $start) : max(0, $start);
        }
        [$bucket, $stateLabel] = self::classifyState($stateRaw, $dur);
        $color = self::vicidialColor($bucket, $dur);
        $fromUser = self::userPart($from);
        $toUser = self::userPart($to);
        if ($sipUser === '') {
            $sipUser = $fromUser;
        }
        $dlg = FreeswitchProvisioner::extractDialogId($d);
        $fromTag = (string) ($d['from_tag'] ?? $d['caller_tag'] ?? $d['ftag'] ?? $d['fromtag'] ?? '');
        $callid = (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? $d['CallId'] ?? $d['ci'] ?? '');
        return [
            'from' => $from !== '' ? $from : ($sipUser !== '' ? $sipUser : $fromUser),
            'to' => $to !== '' ? $to : $toUser,
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'sip_user' => $sipUser,
            'state' => $stateLabel,
            'state_raw' => (string) $stateRaw,
            'bucket' => $bucket,
            'color' => $color,
            'duration' => $dur,
            'callid' => $callid,
            'from_tag' => $fromTag,
            'dialog' => $dlg,
        ];
    }

    /** Best-effort caller signalling IP from dialog MI / DB shapes. */
    private static function callerSignallingIp(array $d): ?string
    {
        foreach ([
            'caller_contact', 'caller_bind_addr', 'contact', 'from_contact',
            'leg1_contact', 'caller_socket', 'from_sock', 'bind_addr',
        ] as $k) {
            $v = $d[$k] ?? null;
            if (!is_string($v) || $v === '') {
                continue;
            }
            if (preg_match('#(\d{1,3}(?:\.\d{1,3}){3})#', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
                return $m[1];
            }
        }
        return null;
    }

    /** Read dialog context value (os_src / os_dst) from MI dlg_list_ctx shapes. */
    private static function dlgVal(array $d, string $key): string
    {
        foreach (['values', 'Values', 'dlg_vals', 'context', 'vars'] as $bag) {
            if (!isset($d[$bag]) || !is_array($d[$bag])) {
                continue;
            }
            $vals = $d[$bag];
            if (isset($vals[$key]) && (is_string($vals[$key]) || is_numeric($vals[$key]))) {
                return trim((string) $vals[$key]);
            }
            if (array_is_list($vals)) {
                foreach ($vals as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $n = (string) ($row['name'] ?? $row['Name'] ?? $row['key'] ?? '');
                    if ($n === $key) {
                        return trim((string) ($row['value'] ?? $row['Value'] ?? ''));
                    }
                }
            }
        }
        if (isset($d[$key]) && (is_string($d[$key]) || is_numeric($d[$key]))) {
            return trim((string) $d[$key]);
        }
        return '';
    }

    /** @return array{0:string,1:string} bucket, label */
    private static function classifyState(mixed $stateRaw, int $dur): array
    {
        // OpenSIPS dlg states: 1=unconfirmed, 2=early(180/183), 3=confirmed(no ACK), 4=confirmed, 5=deleted
        if (is_int($stateRaw) || (is_string($stateRaw) && ctype_digit(trim($stateRaw)))) {
            $n = (int) $stateRaw;
            return match (true) {
                $n === 1 => ['trying', 'Trying'],
                $n === 2 => ['ringing', 'Ringing'],
                $n === 3, $n === 4 => ['connected', 'Connected'],
                $n === 5 => ['other', 'Ending'],
                default => ['other', 'State ' . $n],
            };
        }
        $s = strtoupper(trim((string) $stateRaw));
        // MI sometimes returns "2: EARLY" / "4: CONFIRMED"
        if (preg_match('/\b([1-5])\b/', $s, $m)) {
            return self::classifyState((int) $m[1], $dur);
        }
        if ($s === '') {
            // No state — prefer trying/ringing over false "Connected" for short legs
            return $dur >= 15 ? ['connected', 'Connected'] : ['trying', 'Trying'];
        }
        if (str_contains($s, 'CONFIRM') || str_contains($s, 'CONNECT') || $s === 'INCALL' || $s === 'ANSWERED') {
            return ['connected', 'Connected'];
        }
        if (str_contains($s, 'EARLY') || str_contains($s, 'RING') || str_contains($s, 'PROGRESS') || str_contains($s, '183') || str_contains($s, '180')) {
            return ['ringing', 'Ringing'];
        }
        if (str_contains($s, 'TRY') || str_contains($s, 'UNCONFIRM') || str_contains($s, '100') || str_contains($s, 'INIT')) {
            return ['trying', 'Trying'];
        }
        if (str_contains($s, 'DEAD') || str_contains($s, 'DELETE') || str_contains($s, 'END')) {
            return ['other', 'Ending'];
        }
        return ['other', $s];
    }

    /** VICIdial-style row background class. */
    private static function vicidialColor(string $bucket, int $dur): string
    {
        if ($bucket === 'connected') {
            if ($dur >= 600) {
                return 'os-rt-green'; // >10 min light green
            }
            if ($dur >= 60) {
                return 'os-rt-pink'; // >1 min pink
            }
            if ($dur >= 10) {
                return 'os-rt-pink-soft';
            }
            return 'os-rt-white';
        }
        if ($bucket === 'ringing') {
            if ($dur >= 300) {
                return 'os-rt-blue-dark';
            }
            if ($dur >= 60) {
                return 'os-rt-blue';
            }
            return 'os-rt-blue-light';
        }
        if ($bucket === 'trying') {
            return 'os-rt-blue-light';
        }
        if ($bucket === 'other') {
            return 'os-rt-black';
        }
        return 'os-rt-white';
    }

    private static function userPart(string $uri): string
    {
        if (preg_match('/sip:([^@;>]+)/i', $uri, $m)) {
            return $m[1];
        }
        return $uri;
    }
}
