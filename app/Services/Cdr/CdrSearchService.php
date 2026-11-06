<?php

declare(strict_types=1);

namespace Ownswitch\Services\Cdr;

use Ownswitch\Support\SipCode;
use Ownswitch\Repositories\AreaCodeRepository;
use Ownswitch\Support\Destination;
use Ownswitch\Support\TenantScope;
use PDO;

/** Tenant-scoped, injection-safe CDR search with CSV export. */
final class CdrSearchService
{
    public const SORTABLE = ['id', 'setup_at', 'duration_actual', 'customer_charge', 'carrier_cost', 'disposition', 'dst_normalized'];
    public const DISPOSITIONS = ['ANSWERED', 'NO_ANSWER', 'BUSY', 'FAILED', 'CANCELLED'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $f from,to (Y-m-d), customer_id, carrier_id, src, dst, disposition, min_duration, q
     * @return array{0:string,1:list<mixed>}
     */
    public function where(TenantScope $scope, array $f): array
    {
        [$sql, $params] = $scope->where('owned', 'c');
        $w = ["({$sql})"];
        $from = $this->date($f['from'] ?? null);
        $to = $this->date($f['to'] ?? null);
        $tz = \Ownswitch\Support\Timezone::normalize(is_string($f['tz'] ?? null) ? (string) $f['tz'] : 'UTC');
        if ($from && $to) {
            [$fromUtc, $toUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($from, $to, $tz);
            $w[] = 'c.setup_at >= ?';
            $params[] = $fromUtc;
            $w[] = 'c.setup_at < ?';
            $params[] = $toUtc;
        } elseif ($from) {
            [$fromUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($from, $from, $tz);
            $w[] = 'c.setup_at >= ?';
            $params[] = $fromUtc;
        } elseif ($to) {
            [, $toUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($to, $to, $tz);
            $w[] = 'c.setup_at < ?';
            $params[] = $toUtc;
        }
        foreach (['customer_id', 'carrier_id', 'reseller_id'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && ctype_digit((string) $f[$k])) {
                if ($k === 'carrier_id' && !$scope->isGlobal()) {
                    continue;   // customers must not probe carrier-side data
                }
                $w[] = "c.{$k} = ?";
                $params[] = (int) $f[$k];
            }
        }
        if (!empty($f['src']) && is_string($f['src'])) {
            $w[] = 'c.src LIKE ?';
            $params[] = addcslashes(trim($f['src']), '%_\\') . '%';
        }
        if (!empty($f['dst']) && is_string($f['dst'])) {
            $w[] = 'c.dst_normalized LIKE ?';
            $params[] = addcslashes(preg_replace('/\D+/', '', $f['dst']) ?? '', '%_\\') . '%';
        }
        if (!empty($f['disposition']) && in_array($f['disposition'], self::DISPOSITIONS, true)) {
            $w[] = 'c.disposition = ?';
            $params[] = $f['disposition'];
        }
        if (isset($f['min_duration']) && ctype_digit((string) $f['min_duration']) && $f['min_duration'] !== '') {
            $w[] = 'c.duration_actual >= ?';
            $params[] = (int) $f['min_duration'];
        }
        return [implode(' AND ', $w), $params];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int} */
    public function search(TenantScope $scope, array $filters, int $page = 1, int $perPage = 50, string $sort = 'setup_at', string $dir = 'desc'): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        [$where, $params] = $this->where($scope, $filters);
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'setup_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM cdrs c WHERE {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $sql = "SELECT c.*, cu.name AS customer_name, ca.name AS carrier_name,
                       COALESCE(NULLIF(t.name, ''), ca.name) AS trunk_name,
                       sa.username AS sip_username
                FROM cdrs c
                LEFT JOIN customers cu ON cu.id = c.customer_id
                LEFT JOIN carriers ca ON ca.id = c.carrier_id
                LEFT JOIN carrier_trunks t ON t.id = c.carrier_trunk_id
                LEFT JOIN sip_accounts sa ON sa.id = c.sip_account_id
                WHERE {$where} ORDER BY c.`{$sort}` {$dir}, c.id DESC LIMIT ? OFFSET ?";
        $st = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue($i++, $perPage, PDO::PARAM_INT);
        $st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $callIds = [];
        $dsts = [];
        foreach ($rows as &$r) {
            $r = self::normalizeRowDisplay($r);
            if (!empty($r['sip_call_id'])) {
                $callIds[] = (string) $r['sip_call_id'];
            }
            $dsts[] = (string) ($r['dst_normalized'] ?? '');
            if (!empty($r['dst_raw'])) {
                $dsts[] = (string) $r['dst_raw'];
            }
        }
        unset($r);
        $areaNames = $this->areaNames($dsts);
        $legsByCall = $this->legsByCallIds($callIds);
        foreach ($rows as &$r) {
            $cid = (string) ($r['sip_call_id'] ?? '');
            $r['attempt_legs'] = $legsByCall[$cid] ?? [];
            $r['has_sip_trace'] = trim((string) ($r['sip_trace'] ?? '')) !== '' || $r['attempt_legs'] !== [];
            $dn = Destination::normalize((string) ($r['dst_normalized'] ?? ''));
            $rawDn = Destination::normalize((string) ($r['dst_raw'] ?? ''));
            $r['area_description'] = $areaNames[$dn] ?? $areaNames[$rawDn] ?? null;
            if ($r['area_description'] === null && ($dn !== '' || $rawDn !== '')) {
                $r['area_description'] = $this->areaNameOne($dn !== '' ? $dn : $rawDn);
            }
        }
        unset($r);

        // Customers must never see carrier-side cost/margin.
        if (!$scope->isStaff()) {
            foreach ($rows as &$r) {
                foreach (['carrier_cost', 'carrier_rate', 'carrier_connect_fee', 'gross_margin', 'carrier_id', 'carrier_name', 'carrier_trunk_id', 'carrier_initial', 'carrier_subsequent', 'duration_billable_carrier', 'trunk_name'] as $k) {
                    unset($r[$k]);
                }
            }
            unset($r);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(TenantScope $scope, int $id): ?array
    {
        [$sql, $params] = $scope->where('owned', 'c');
        $st = $this->pdo->prepare("SELECT c.*, cu.name AS customer_name, ca.name AS carrier_name,
                       COALESCE(NULLIF(t.name, ''), ca.name) AS trunk_name,
                       sa.username AS sip_username
            FROM cdrs c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            LEFT JOIN carriers ca ON ca.id = c.carrier_id
            LEFT JOIN carrier_trunks t ON t.id = c.carrier_trunk_id
            LEFT JOIN sip_accounts sa ON sa.id = c.sip_account_id
            WHERE c.id = ? AND ({$sql}) LIMIT 1");
        $st->execute([$id, ...$params]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row = self::normalizeRowDisplay($row);
        $dn = Destination::normalize((string) ($row['dst_normalized'] ?? ''));
        $rawDn = Destination::normalize((string) ($row['dst_raw'] ?? ''));
        $names = $this->areaNames(array_values(array_filter([$dn, $rawDn])));
        $row['area_description'] = $names[$dn] ?? $names[$rawDn] ?? null;
        if ($row['area_description'] === null && ($dn !== '' || $rawDn !== '')) {
            $row['area_description'] = $this->areaNameOne($dn !== '' ? $dn : $rawDn);
        }
        if (!$scope->isStaff()) {
            foreach (['carrier_cost', 'carrier_rate', 'carrier_connect_fee', 'gross_margin', 'carrier_id', 'carrier_name', 'carrier_trunk_id', 'carrier_initial', 'carrier_subsequent', 'duration_billable_carrier', 'cgrates_cost', 'rating_discrepancy', 'trunk_name'] as $k) {
                unset($row[$k]);
            }
        }
        return $row;
    }

    /** @param list<string> $destinations @return array<string,string> */
    private function areaNames(array $destinations): array
    {
        try {
            return (new AreaCodeRepository($this->pdo))->namesForNumbers($destinations);
        } catch (\Throwable $e) {
            error_log('[ownswitch] CDR areaNames: ' . $e->getMessage());
            return [];
        }
    }

    private function areaNameOne(string $destination): ?string
    {
        try {
            return (new AreaCodeRepository($this->pdo))->nameForNumber($destination);
        } catch (\Throwable $e) {
            error_log('[ownswitch] CDR areaNameOne: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function normalizeRowDisplay(array $r): array
    {
        if (isset($r['src']) && is_string($r['src'])) {
            $r['src'] = OpensipsAccImporter::cleanCallerId($r['src']) ?: OpensipsAccImporter::cleanToken($r['src']);
        }
        if (isset($r['dst_normalized']) && is_string($r['dst_normalized'])) {
            $r['dst_normalized'] = OpensipsAccImporter::cleanCallerId($r['dst_normalized']) ?: $r['dst_normalized'];
        }
        $code = isset($r['sip_response']) ? (int) $r['sip_response'] : 0;
        $r['sip_code_label'] = SipCode::label($code);
        $r['sip_code_short'] = SipCode::short($code);
        return $r;
    }

    /**
     * @param list<string> $callIds
     * @return array<string,list<array<string,mixed>>>
     */
    private function legsByCallIds(array $callIds): array
    {
        $callIds = array_values(array_unique(array_filter($callIds)));
        if ($callIds === []) {
            return [];
        }
        try {
            $ph = implode(',', array_fill(0, count($callIds), '?'));
            $st = $this->pdo->prepare(
                "SELECT * FROM cdr_attempt_legs WHERE sip_call_id IN ({$ph}) ORDER BY attempt_no ASC, id ASC"
            );
            $st->execute($callIds);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $leg) {
                $cid = (string) $leg['sip_call_id'];
                $leg['sip_code_label'] = SipCode::label((int) ($leg['sip_code'] ?? 0));
                $out[$cid][] = $leg;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<int> $ids @return int */
    public function deleteByIds(TenantScope $scope, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        [$where, $params] = $this->where($scope, []);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // where() aliases table as c — use subquery for DELETE
        $sql = "DELETE FROM cdrs WHERE id IN ({$ph}) AND id IN (SELECT id FROM (SELECT c.id FROM cdrs c WHERE {$where}) AS scoped)";
        $st = $this->pdo->prepare($sql);
        $st->execute([...$ids, ...$params]);
        $n = $st->rowCount();
        try {
            $this->pdo->prepare("DELETE FROM cdr_attempt_legs WHERE cdr_id IN ({$ph})")->execute($ids);
        } catch (\Throwable) {
        }
        return $n;
    }

    /** Delete all CDRs matching filters (max 50k). */
    public function deleteFiltered(TenantScope $scope, array $filters, int $max = 50000): int
    {
        [$where, $params] = $this->where($scope, $filters);
        $st = $this->pdo->prepare("SELECT c.id FROM cdrs c WHERE {$where} ORDER BY c.id DESC LIMIT " . (int) $max);
        $st->execute($params);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        return $this->deleteByIds($scope, $ids);
    }

    /** Stream CSV in chunks (keyset on id) so large exports do not exhaust memory. Max 200k rows. */
    public function exportCsv(TenantScope $scope, array $filters, callable $write): void
    {
        $tz = \Ownswitch\Support\Timezone::normalize(is_string($filters['tz'] ?? null) ? (string) $filters['tz'] : 'UTC');
        $hdr = ['id', 'setup_at_' . $tz, 'src', 'dst', 'disposition', 'sip_code', 'sip_code_label', 'duration_s', 'billable_s', 'rate', 'increment', 'charge', 'currency'];
        $staff = $scope->isStaff();
        if ($staff) {
            array_push($hdr, 'carrier', 'carrier_cost', 'margin');
        }
        $write(\Ownswitch\Support\Csv::line($hdr));
        [$where, $params] = $this->where($scope, $filters);
        $last = PHP_INT_MAX;
        $sent = 0;
        while ($sent < 200000) {
            $st = $this->pdo->prepare("SELECT c.*, ca.name AS carrier_name FROM cdrs c LEFT JOIN carriers ca ON ca.id = c.carrier_id
                WHERE {$where} AND c.id < ? ORDER BY c.id DESC LIMIT 1000");
            $st->execute([...$params, $last]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $code = (int) ($r['sip_response'] ?? 0);
                $setupLocal = \Ownswitch\Support\Timezone::format(isset($r['setup_at']) ? (string) $r['setup_at'] : null, $tz);
                $line = [$r['id'], $setupLocal, $r['src'], $r['dst_normalized'], $r['disposition'], $code > 0 ? $code : '',
                    SipCode::label($code), $r['duration_actual'],
                    $r['duration_billable_customer'], $r['customer_rate'], ($r['customer_initial'] ?? '') . '/' . ($r['customer_subsequent'] ?? ''), $r['customer_charge'], $r['currency']];
                if ($staff) {
                    array_push($line, $r['carrier_name'], $r['carrier_cost'], $r['gross_margin']);
                }
                $write(\Ownswitch\Support\Csv::line($line));
                $last = (int) $r['id'];
                $sent++;
            }
        }
    }

    private function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }
}
