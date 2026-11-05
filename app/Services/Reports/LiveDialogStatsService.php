<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use PDO;

/** Live OpenSIPS dialogs grouped by customer / trunk, plus today's ASR/ACD from CDRs. */
final class LiveDialogStatsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{dialogs:list<array<string,mixed>>,error:?string}
     */
    public function dialogs(): array
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return ['dialogs' => [], 'error' => 'FreeSWITCH is not configured (ESL host / password).'];
        }
        try {
            $out = [];
            foreach ($prov->listDialogs() as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $n = self::normalize($d);
                if ($n['callid'] === '') {
                    continue;
                }
                $out[] = $n;
            }
            return ['dialogs' => $out, 'error' => null];
        } catch (\Throwable $e) {
            return ['dialogs' => [], 'error' => 'Failed to query live dialogs: ' . $e->getMessage()];
        }
    }

    /**
     * Per-customer live counts + today's ASR/ACD.
     *
     * @return array{rows:list<array<string,mixed>>,totals:array<string,int|string>,error:?string}
     */
    public function byCustomer(): array
    {
        $live = $this->dialogs();
        $sipMap = $this->sipToCustomer();
        $asrMap = $this->todayAsrByCustomer();

        /** @var array<int,array<string,mixed>> $by */
        $by = [];
        foreach ($live['dialogs'] as $d) {
            $cid = $this->customerIdForDialog($d, $sipMap);
            if ($cid === null) {
                continue;
            }
            if (!isset($by[$cid])) {
                $by[$cid] = $this->emptyLiveRow($cid);
            }
            $by[$cid]['active']++;
            $bucket = (string) ($d['bucket'] ?? 'other');
            if (isset($by[$cid][$bucket])) {
                $by[$cid][$bucket]++;
            }
            $dst = (string) ($d['to_user'] ?? '');
            if ($dst !== '') {
                $by[$cid]['_dest'][$dst] = true;
            }
        }

        // Ensure customers with traffic today still appear even with 0 live
        foreach ($asrMap as $cid => $asr) {
            if (!isset($by[$cid])) {
                $by[$cid] = $this->emptyLiveRow($cid);
            }
            $by[$cid]['asr'] = $asr['asr'];
            $by[$cid]['acd'] = $asr['acd'];
            $by[$cid]['attempts_today'] = $asr['attempts'];
            $by[$cid]['answered_today'] = $asr['answered'];
        }

        $names = $this->customerNames(array_keys($by));
        $rows = [];
        $tot = ['active' => 0, 'trying' => 0, 'ringing' => 0, 'connected' => 0, 'destinations' => 0, 'users_calling' => 0];
        foreach ($by as $cid => $row) {
            $row['name'] = $names[$cid] ?? ('#' . $cid);
            $row['destinations'] = count($row['_dest'] ?? []);
            unset($row['_dest']);
            if (($row['active'] ?? 0) > 0) {
                $tot['users_calling']++;
            }
            $tot['active'] += (int) $row['active'];
            $tot['trying'] += (int) $row['trying'];
            $tot['ringing'] += (int) $row['ringing'];
            $tot['connected'] += (int) $row['connected'];
            $tot['destinations'] += (int) $row['destinations'];
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => ((int) $b['active'] <=> (int) $a['active']) ?: strcasecmp((string) $a['name'], (string) $b['name']));

        return ['rows' => $rows, 'totals' => $tot, 'error' => $live['error']];
    }

    /**
     * Per-trunk live counts + today's ASR/ACD.
     *
     * @return array{rows:list<array<string,mixed>>,totals:array<string,int|string>,error:?string}
     */
    public function byTrunk(): array
    {
        $live = $this->dialogs();
        $trunks = $this->trunkIndex();
        $asrMap = $this->todayAsrByTrunk();

        /** @var array<int,array<string,mixed>> $by */
        $by = [];
        foreach ($live['dialogs'] as $d) {
            $tid = $this->trunkIdForDialog($d, $trunks);
            if ($tid === null) {
                // Unattributed outbound still counted under 0 = Unknown
                $tid = 0;
            }
            if (!isset($by[$tid])) {
                $by[$tid] = $this->emptyLiveRow($tid);
                $by[$tid]['name'] = $tid === 0 ? 'Unattributed / early' : ($trunks['by_id'][$tid]['label'] ?? ('#' . $tid));
                $by[$tid]['carrier'] = $tid === 0 ? '—' : ($trunks['by_id'][$tid]['carrier'] ?? '—');
            }
            $by[$tid]['active']++;
            $bucket = (string) ($d['bucket'] ?? 'other');
            if (isset($by[$tid][$bucket])) {
                $by[$tid][$bucket]++;
            }
        }

        foreach ($asrMap as $tid => $asr) {
            if (!isset($by[$tid])) {
                $by[$tid] = $this->emptyLiveRow($tid);
                $by[$tid]['name'] = $trunks['by_id'][$tid]['label'] ?? ('#' . $tid);
                $by[$tid]['carrier'] = $trunks['by_id'][$tid]['carrier'] ?? '—';
            }
            $by[$tid]['asr'] = $asr['asr'];
            $by[$tid]['acd'] = $asr['acd'];
            $by[$tid]['attempts_today'] = $asr['attempts'];
            $by[$tid]['answered_today'] = $asr['answered'];
        }

        $rows = [];
        $tot = ['active' => 0, 'trying' => 0, 'ringing' => 0, 'connected' => 0];
        foreach ($by as $row) {
            unset($row['_dest']);
            $tot['active'] += (int) $row['active'];
            $tot['trying'] += (int) $row['trying'];
            $tot['ringing'] += (int) $row['ringing'];
            $tot['connected'] += (int) $row['connected'];
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => ((int) $b['active'] <=> (int) $a['active']) ?: strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return ['rows' => $rows, 'totals' => $tot, 'error' => $live['error']];
    }

    /** @return array{id:int,active:int,trying:int,ringing:int,connected:int,other:int,_dest:array<string,bool>,asr:string,acd:string,attempts_today:int,answered_today:int} */
    private function emptyLiveRow(int $id): array
    {
        return [
            'id' => $id,
            'active' => 0,
            'trying' => 0,
            'ringing' => 0,
            'connected' => 0,
            'other' => 0,
            '_dest' => [],
            'asr' => '0.00',
            'acd' => '0.0',
            'attempts_today' => 0,
            'answered_today' => 0,
        ];
    }

    /** @return array<string,int> lowercase sip username => customer_id */
    private function sipToCustomer(): array
    {
        try {
            $st = $this->pdo->query("SELECT LOWER(username) AS u, customer_id FROM sip_accounts WHERE status = 'active'");
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $map[(string) $r['u']] = (int) $r['customer_id'];
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,int> $sipMap */
    private function customerIdForDialog(array $d, array $sipMap): ?int
    {
        foreach ([(string) ($d['from_user'] ?? ''), (string) ($d['to_user'] ?? '')] as $u) {
            $u = strtolower($u);
            if ($u !== '' && isset($sipMap[$u])) {
                return $sipMap[$u];
            }
        }
        return null;
    }

    /**
     * @return array{by_id:array<int,array{label:string,carrier:string,hosts:list<string>}>,by_host:array<string,int>}
     */
    private function trunkIndex(): array
    {
        $byId = [];
        $byHost = [];
        try {
            $rows = $this->pdo->query(
                "SELECT t.id, t.name, t.host, t.port, c.name AS carrier_name
                 FROM carrier_trunks t
                 LEFT JOIN carriers c ON c.id = t.carrier_id
                 WHERE t.status = 'active'
                 LIMIT 2000"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $hosts = [];
                $h = strtolower(trim((string) ($r['host'] ?? '')));
                if ($h !== '' && $h !== 'dynamic') {
                    $bare = explode(':', $h, 2)[0];
                    $hosts[] = $bare;
                    $byHost[$bare] = $id;
                    $byHost[$h] = $id;
                    $port = (int) ($r['port'] ?? 5060);
                    if ($port > 0) {
                        $byHost[$bare . ':' . $port] = $id;
                    }
                }
                $byId[$id] = [
                    'label' => (string) ($r['name'] ?? ('Trunk #' . $id)),
                    'carrier' => (string) ($r['carrier_name'] ?? '—'),
                    'hosts' => $hosts,
                ];
            }
        } catch (\Throwable) {
        }
        return ['by_id' => $byId, 'by_host' => $byHost];
    }

    /** @param array{by_id:array,by_host:array<string,int>} $trunks */
    private function trunkIdForDialog(array $d, array $trunks): ?int
    {
        foreach (['callee_host', 'caller_host', 'to_host'] as $k) {
            $h = strtolower((string) ($d[$k] ?? ''));
            if ($h !== '' && isset($trunks['by_host'][$h])) {
                return $trunks['by_host'][$h];
            }
        }
        return null;
    }

    /** @return array<int,array{attempts:int,answered:int,asr:string,acd:string}> */
    private function todayAsrByCustomer(): array
    {
        $from = gmdate('Y-m-d 00:00:00');
        $to = gmdate('Y-m-d 00:00:00', time() + 86400);
        try {
            $st = $this->pdo->prepare(
                "SELECT customer_id,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                        COALESCE(SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN duration_actual ELSE 0 END),0) AS seconds
                 FROM cdrs
                 WHERE customer_id IS NOT NULL AND setup_at >= ? AND setup_at < ?
                 GROUP BY customer_id"
            );
            $st->execute([$from, $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $cid = (int) $r['customer_id'];
                $c = AsrAcdService::compute((int) $r['attempts'], (int) $r['answered'], (int) $r['seconds']);
                $out[$cid] = $c;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array{attempts:int,answered:int,asr:string,acd:string}> */
    private function todayAsrByTrunk(): array
    {
        $from = gmdate('Y-m-d 00:00:00');
        $to = gmdate('Y-m-d 00:00:00', time() + 86400);
        try {
            $st = $this->pdo->prepare(
                "SELECT carrier_trunk_id,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                        COALESCE(SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN duration_actual ELSE 0 END),0) AS seconds
                 FROM cdrs
                 WHERE carrier_trunk_id IS NOT NULL AND setup_at >= ? AND setup_at < ?
                 GROUP BY carrier_trunk_id"
            );
            $st->execute([$from, $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $tid = (int) $r['carrier_trunk_id'];
                $out[$tid] = AsrAcdService::compute((int) $r['attempts'], (int) $r['answered'], (int) $r['seconds']);
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<int> $ids @return array<int,string> */
    private function customerNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $this->pdo->prepare("SELECT id, name FROM customers WHERE id IN ({$ph})");
            $st->execute($ids);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(int) $r['id']] = (string) $r['name'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $d */
    public static function normalize(array $d): array
    {
        $from = (string) ($d['from_uri'] ?? $d['caller'] ?? $d['From'] ?? $d['from'] ?? $d['caller_uri'] ?? '');
        $to = (string) ($d['to_uri'] ?? $d['callee'] ?? $d['To'] ?? $d['to'] ?? $d['callee_uri'] ?? '');
        $stateRaw = $d['state'] ?? $d['State'] ?? $d['dialog_state'] ?? $d['dlg_state'] ?? $d['status'] ?? '';
        $start = (int) ($d['start_time'] ?? $d['timestart'] ?? $d['start'] ?? $d['start_ts'] ?? 0);
        $dur = (int) ($d['duration'] ?? $d['calltime'] ?? $d['Lifetime'] ?? 0);
        if ($dur <= 0 && $start > 0) {
            $dur = $start > 1_000_000_000 ? max(0, time() - $start) : max(0, $start);
        }
        [$bucket, $stateLabel] = self::classifyState($stateRaw, $dur);
        $fromUser = self::userPart($from);
        $toUser = self::userPart($to);
        $calleeContact = (string) ($d['callee_contact'] ?? $d['to_contact'] ?? $d['callee'] ?? '');
        $callerContact = (string) ($d['caller_contact'] ?? $d['from_contact'] ?? '');
        return [
            'from' => $from !== '' ? $from : $fromUser,
            'to' => $to !== '' ? $to : $toUser,
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'state' => $stateLabel,
            'bucket' => $bucket,
            'duration' => $dur,
            'callid' => (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? $d['CallId'] ?? $d['ci'] ?? ''),
            'callee_host' => self::hostPart($calleeContact !== '' ? $calleeContact : $to),
            'caller_host' => self::hostPart($callerContact !== '' ? $callerContact : $from),
            'to_host' => self::hostPart($to),
        ];
    }

    /** @return array{0:string,1:string} */
    private static function classifyState(mixed $stateRaw, int $dur): array
    {
        if (is_int($stateRaw) || (is_string($stateRaw) && ctype_digit($stateRaw))) {
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
        if ($s === '') {
            return $dur >= 2 ? ['connected', 'Connected'] : ['trying', 'Trying'];
        }
        if (str_contains($s, 'CONFIRM') || str_contains($s, 'CONNECT') || $s === 'INCALL' || $s === 'ANSWERED' || $s === '4' || $s === '3') {
            return ['connected', 'Connected'];
        }
        if (str_contains($s, 'EARLY') || str_contains($s, 'RING') || str_contains($s, 'PROGRESS') || str_contains($s, '183') || str_contains($s, '180') || $s === '2') {
            return ['ringing', 'Ringing'];
        }
        if (str_contains($s, 'TRY') || str_contains($s, 'UNCONFIRM') || str_contains($s, '100') || str_contains($s, 'INIT') || $s === '1') {
            return ['trying', 'Trying'];
        }
        return ['other', $s !== '' ? $s : 'Connected'];
    }

    private static function userPart(string $uri): string
    {
        if (preg_match('/sip:([^@;>]+)/i', $uri, $m)) {
            return $m[1];
        }
        return $uri;
    }

    private static function hostPart(string $uri): string
    {
        if (preg_match('/@([^;>\s]+)/', $uri, $m)) {
            $h = strtolower($m[1]);
            return explode(':', $h, 2)[0];
        }
        if (preg_match('#sip:([^@;>\s]+)#i', $uri, $m) && str_contains($m[1], '.')) {
            return strtolower(explode(':', $m[1], 2)[0]);
        }
        return '';
    }
}
