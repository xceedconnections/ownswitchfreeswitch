<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/** KPI pack for the Xceed-style admin/customer dashboards. Never throws on missing tables. */
final class DashboardMetricsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Empty metrics when CDRs / schema are unavailable. */
    public static function empty(): array
    {
        $zero = AsrAcdService::compute(0, 0, 0) + ['spend' => '0', 'last_min' => 0];
        return [
            'window60' => $zero,
            'today' => $zero,
            'yesterday_pace' => '0',
            'asr7' => '0',
            'spend30' => '0',
            'peakPerMin' => 0,
            'lastCdrAt' => null,
            'traffic60' => [],
            'daily7' => [],
            'failed24' => ['total' => 0, 'attempts' => 0, 'pct' => '0', 'reasons' => []],
            'routes' => [],
            'trunk_groups' => 0,
        ];
    }

    public function build(TenantScope $scope): array
    {
        try {
            if (!$this->tableExists('cdrs')) {
                $out = self::empty();
                $out['routes'] = $this->routeHealth($scope);
                $out['trunk_groups'] = $this->trunkGroupCount($scope);
                return $out;
            }

            [$tw, $tp] = $scope->where('owned', 'c');
            $since60 = gmdate('Y-m-d H:i:s', time() - 3600);
            $since24 = gmdate('Y-m-d H:i:s', time() - 86400);
            $todayStart = gmdate('Y-m-d 00:00:00');
            $yStart = gmdate('Y-m-d 00:00:00', strtotime('-1 day'));
            $yNow = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
            $since7 = gmdate('Y-m-d 00:00:00', strtotime('-6 days'));

            $window60 = $this->aggregate($tw, $tp, $since60);
            $window60['last_min'] = $this->countAttempts($tw, $tp, gmdate('Y-m-d H:i:s', time() - 60));
            $traffic60 = $this->trafficBuckets($tw, $tp, $since60);
            $peakPerMin = 0;
            foreach ($traffic60 as $b) {
                $peakPerMin = max($peakPerMin, (int) $b['answered'] + (int) $b['failed']);
            }
            $agg7 = $this->aggregate($tw, $tp, $since7);
            $since30 = gmdate('Y-m-d 00:00:00', strtotime('-29 days'));

            return [
                'window60' => $window60,
                'today' => $this->aggregate($tw, $tp, $todayStart),
                'yesterday_pace' => $this->aggregate($tw, $tp, $yStart, $yNow)['spend'],
                'asr7' => (string) ($agg7['asr'] ?? '0'),
                'spend30' => $this->aggregate($tw, $tp, $since30)['spend'],
                'peakPerMin' => $peakPerMin,
                'lastCdrAt' => $this->lastCdrAt($tw, $tp),
                'traffic60' => $traffic60,
                'daily7' => $this->dailyUsage($tw, $tp, $since7),
                'failed24' => $this->failedBreakdown($tw, $tp, $since24),
                'routes' => $this->routeHealth($scope),
                'trunk_groups' => $this->trunkGroupCount($scope),
            ];
        } catch (\Throwable $e) {
            error_log('[ownswitch] dashboard metrics: ' . $e->getMessage());
            return self::empty();
        }
    }

    /** @return array{attempts:int,answered:int,seconds:int,spend:string,asr:string,acd:string} */
    private function aggregate(string $tw, array $tp, string $from, ?string $to = null): array
    {
        $sql = "SELECT COUNT(*) AS attempts,
            SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
            COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_actual ELSE 0 END),0) AS seconds,
            COALESCE(SUM(c.customer_charge),0) AS spend
            FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?";
        $params = [...$tp, $from];
        if ($to !== null) {
            $sql .= ' AND c.setup_at < ?';
            $params[] = $to;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = AsrAcdService::compute((int) ($r['attempts'] ?? 0), (int) ($r['answered'] ?? 0), (int) ($r['seconds'] ?? 0));
        return $stats + ['spend' => (string) ($r['spend'] ?? '0')];
    }

    private function countAttempts(string $tw, array $tp, string $from): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?");
        $st->execute([...$tp, $from]);
        return (int) $st->fetchColumn();
    }

    private function lastCdrAt(string $tw, array $tp): ?string
    {
        try {
            $st = $this->pdo->prepare("SELECT MAX(c.setup_at) FROM cdrs c WHERE ({$tw})");
            $st->execute($tp);
            $v = $st->fetchColumn();
            return $v ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{minute:string,answered:int,failed:int}> */
    private function trafficBuckets(string $tw, array $tp, string $from): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT DATE_FORMAT(c.setup_at, '%Y-%m-%d %H:%i') AS bucket,
                    DATE_FORMAT(c.setup_at, '%H:%i') AS minute,
                    SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0) THEN 1 ELSE 0 END) AS failed
                 FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?
                 GROUP BY bucket, minute
                 ORDER BY bucket ASC LIMIT 60"
            );
            $st->execute([...$tp, $from]);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $rows[] = [
                    'minute' => (string) $r['minute'],
                    'answered' => (int) $r['answered'],
                    'failed' => (int) $r['failed'],
                ];
            }
            return $rows;
        } catch (\Throwable $e) {
            error_log('[ownswitch] trafficBuckets: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array{day:string,attempts:int,answered:int,minutes:float,cost:string,asr:string}> */
    private function dailyUsage(string $tw, array $tp, string $from): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT DATE(c.setup_at) AS day, COUNT(*) AS attempts,
                    SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                    COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_billable_customer ELSE 0 END),0) AS billable_s,
                    COALESCE(SUM(c.customer_charge),0) AS cost
                 FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?
                 GROUP BY DATE(c.setup_at) ORDER BY day DESC LIMIT 7"
            );
            $st->execute([...$tp, $from]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $a = (int) $r['attempts'];
                $n = (int) $r['answered'];
                $out[] = [
                    'day' => (string) $r['day'],
                    'attempts' => $a,
                    'answered' => $n,
                    'minutes' => round(((int) $r['billable_s']) / 60, 1),
                    'cost' => (string) $r['cost'],
                    'asr' => AsrAcdService::compute($a, $n, 0)['asr'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[ownswitch] dailyUsage: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array{total:int,attempts:int,pct:string,reasons:list<array{label:string,count:int,pct:float}>} */
    private function failedBreakdown(string $tw, array $tp, string $from): array
    {
        $empty = ['total' => 0, 'attempts' => 0, 'pct' => '0', 'reasons' => []];
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) AS attempts,
                    SUM(CASE WHEN NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0) THEN 1 ELSE 0 END) AS failed
                 FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?"
            );
            $st->execute([...$tp, $from]);
            $tot = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $attempts = (int) ($tot['attempts'] ?? 0);
            $failed = (int) ($tot['failed'] ?? 0);

            $caseExpr = "CASE
                WHEN c.disposition IN ('FAILED','CANCELLED') THEN 'Failed at carrier'
                WHEN c.disposition = 'NO_ANSWER' THEN 'No answer'
                WHEN c.disposition = 'BUSY' THEN 'Busy'
                ELSE CONCAT('Other (', COALESCE(c.disposition, 'unknown'), ')')
             END";
            $st2 = $this->pdo->prepare(
                "SELECT {$caseExpr} AS label, COUNT(*) AS cnt
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ?
                   AND NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0)
                 GROUP BY {$caseExpr}
                 ORDER BY cnt DESC LIMIT 6"
            );
            $st2->execute([...$tp, $from]);
            $reasons = [];
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $c = (int) $r['cnt'];
                $reasons[] = [
                    'label' => (string) $r['label'],
                    'count' => $c,
                    'pct' => $failed > 0 ? round(100 * $c / $failed, 1) : 0.0,
                ];
            }
            $failPct = $attempts > 0
                ? \Ownswitch\Services\Billing\RatingService::round(
                    bcdiv(bcmul((string) $failed, '100', 8), (string) $attempts, 8),
                    0
                )
                : '0';

            return [
                'total' => $failed,
                'attempts' => $attempts,
                'pct' => $failPct,
                'reasons' => $reasons,
            ];
        } catch (\Throwable $e) {
            error_log('[ownswitch] failedBreakdown: ' . $e->getMessage());
            return $empty;
        }
    }

    /** @return list<array{name:string,role:string,status:string,share:float,asr:string}> */
    private function routeHealth(TenantScope $scope): array
    {
        if (!$scope->isGlobal() && !$scope->isStaff()) {
            return [];
        }
        try {
            if (!$this->tableExists('routing_plans')) {
                return [];
            }
            $cols = $this->columns('routing_plans');
            $statusSel = in_array('status', $cols, true) ? 'status' : "'' AS status";
            $plans = $this->pdo->query(
                "SELECT id, name, {$statusSel} FROM routing_plans ORDER BY id ASC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $since60 = gmdate('Y-m-d H:i:s', time() - 3600);
        $statsByPlan = [];
        $totalAttempts = 0;
        try {
            if ($this->tableExists('cdrs') && $this->tableExists('routing_rules')) {
                $st = $this->pdo->prepare(
                    "SELECT r.routing_plan_id AS plan_id,
                            COUNT(*) AS attempts,
                            SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                            COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_actual ELSE 0 END),0) AS seconds
                     FROM cdrs c
                     JOIN routing_rules r ON r.carrier_trunk_id = c.carrier_trunk_id AND r.prefix = '*' AND r.status = 'active'
                     WHERE c.setup_at >= ? AND c.carrier_trunk_id IS NOT NULL
                     GROUP BY r.routing_plan_id"
                );
                $st->execute([$since60]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $pid = (int) $row['plan_id'];
                    $statsByPlan[$pid] = AsrAcdService::compute((int) $row['attempts'], (int) $row['answered'], (int) $row['seconds']);
                    $totalAttempts += (int) $statsByPlan[$pid]['attempts'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] routeHealth stats: ' . $e->getMessage());
        }

        $out = [];
        $i = 0;
        foreach ($plans as $p) {
            $pid = (int) $p['id'];
            $s = $statsByPlan[$pid] ?? null;
            $attempts = (int) ($s['attempts'] ?? 0);
            $share = $totalAttempts > 0 ? round(100 * $attempts / $totalAttempts, 1) : ($i === 0 ? 100.0 : 0.0);
            $out[] = [
                'name' => (string) $p['name'],
                'role' => $i === 0 ? 'PRIMARY' : 'FALLBACK',
                'status' => (($p['status'] ?? '') === 'active' || ($p['status'] ?? '') === '') ? 'Available' : 'Disabled',
                'share' => $share,
                'asr' => $s !== null && $attempts > 0 ? (string) $s['asr'] : '—',
            ];
            $i++;
        }
        return $out;
    }

    private function trunkGroupCount(TenantScope $scope): int
    {
        try {
            if (($scope->isGlobal() || $scope->isStaff()) && $this->tableExists('routing_plans')) {
                return (int) $this->pdo->query('SELECT COUNT(*) FROM routing_plans')->fetchColumn();
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        try {
            $rows = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_values(array_map(static fn ($r) => (string) $r['Field'], $rows));
        } catch (\Throwable) {
            return [];
        }
    }
}
