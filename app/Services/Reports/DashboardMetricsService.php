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
            'moneyToday' => ['buy' => '0', 'sell' => '0', 'profit' => '0'],
            'moneyMonth' => ['buy' => '0', 'sell' => '0', 'profit' => '0'],
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
            $monthStart = gmdate('Y-m-01 00:00:00');

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
                'moneyToday' => $this->money($tw, $tp, $todayStart),
                'moneyMonth' => $this->money($tw, $tp, $monthStart),
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

    /** @return array{buy:string,sell:string,profit:string} */
    private function money(string $tw, array $tp, string $from, ?string $to = null): array
    {
        $zero = ['buy' => '0', 'sell' => '0', 'profit' => '0'];
        try {
            $sql = "SELECT COALESCE(SUM(c.carrier_cost),0) AS buy,
                    COALESCE(SUM(c.customer_charge),0) AS sell,
                    COALESCE(SUM(c.gross_margin),0) AS profit
                    FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?";
            $params = [...$tp, $from];
            if ($to !== null) {
                $sql .= ' AND c.setup_at < ?';
                $params[] = $to;
            }
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'buy' => (string) ($r['buy'] ?? '0'),
                'sell' => (string) ($r['sell'] ?? '0'),
                'profit' => (string) ($r['profit'] ?? '0'),
            ];
        } catch (\Throwable $e) {
            error_log('[ownswitch] dashboard money: ' . $e->getMessage());
            return $zero;
        }
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
                WHEN c.disposition = 'BUSY' OR c.hangup_cause LIKE '%BUSY%' THEN 'Busy'
                WHEN c.disposition = 'NO_ANSWER' OR c.hangup_cause LIKE '%NO_ANSWER%' OR c.hangup_cause LIKE '%NO_USER%' THEN 'No answer'
                WHEN c.disposition = 'CANCELLED' OR c.hangup_cause LIKE '%CANCEL%' THEN 'Cancelled'
                WHEN c.carrier_trunk_id IS NULL THEN 'Rejected before carrier'
                WHEN c.disposition = 'FAILED' THEN 'Failed at carrier'
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

    /** @return list<array{name:string,role:string,status:string,share:float,asr:string,attempts:int,answered:int}> */
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
                "SELECT id, name, {$statusSel} FROM routing_plans ORDER BY id ASC LIMIT 8"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        if ($plans === []) {
            return [];
        }

        $statsByPlan = $this->planCallStats(array_map(static fn ($p) => (int) $p['id'], $plans));
        $totalAttempts = 0;
        foreach ($statsByPlan as $s) {
            $totalAttempts += (int) $s['attempts'];
        }

        $out = [];
        $i = 0;
        foreach ($plans as $p) {
            $pid = (int) $p['id'];
            $s = $statsByPlan[$pid] ?? AsrAcdService::compute(0, 0, 0);
            $attempts = (int) $s['attempts'];
            $share = $totalAttempts > 0 ? round(100 * $attempts / $totalAttempts, 1) : 0.0;
            $out[] = [
                'name' => (string) $p['name'],
                'role' => $i === 0 ? 'PRIMARY' : 'FALLBACK',
                'status' => (($p['status'] ?? '') === 'active' || ($p['status'] ?? '') === '') ? 'Available' : 'Disabled',
                'share' => $share,
                'asr' => (string) $s['asr'],
                'attempts' => $attempts,
                'answered' => (int) $s['answered'],
            ];
            $i++;
        }
        return $out;
    }

    /**
     * Last-60-minute ASR per trunk group.
     * Calls that died before a trunk was chosen still count, via the SIP user's plan,
     * the matched tariff plan, or the only plan on the switch.
     *
     * @param list<int> $planIds
     * @return array<int,array{attempts:int,answered:int,seconds:int,asr:string,acd:string}>
     */
    private function planCallStats(array $planIds): array
    {
        $since60 = gmdate('Y-m-d H:i:s', time() - 3600);
        $buckets = [];
        foreach ($planIds as $id) {
            $buckets[$id] = ['attempts' => 0, 'answered' => 0, 'seconds' => 0];
        }
        if (!$this->tableExists('cdrs')) {
            return $this->finishPlanStats($buckets);
        }
        try {
            $trunkPlan = [];
            if ($this->tableExists('routing_rules')) {
                foreach ($this->pdo->query(
                    "SELECT carrier_trunk_id, routing_plan_id FROM routing_rules WHERE status = 'active'"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $trunkPlan[(int) $r['carrier_trunk_id']] = (int) $r['routing_plan_id'];
                }
            }
            $sipPlan = [];
            if ($this->tableExists('sip_accounts')) {
                foreach ($this->pdo->query(
                    'SELECT id, routing_plan_id FROM sip_accounts WHERE routing_plan_id IS NOT NULL AND routing_plan_id > 0'
                )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $sipPlan[(int) $r['id']] = (int) $r['routing_plan_id'];
                }
            }
            $tariffs = [];
            if ($this->tableExists('rate_plan_tariffs') && $this->tableExists('customers')) {
                $tariffs = $this->pdo->query(
                    "SELECT cu.id AS customer_id, t.prefix, t.routing_plan_id
                     FROM rate_plan_tariffs t
                     JOIN customers cu ON cu.rate_plan_id = t.rate_plan_id
                     WHERE t.status = 'active' AND t.routing_plan_id IS NOT NULL AND t.routing_plan_id > 0"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $st = $this->pdo->prepare(
                'SELECT disposition, duration_actual, carrier_trunk_id, sip_account_id, customer_id, dst_normalized
                 FROM cdrs WHERE setup_at >= ?'
            );
            $st->execute([$since60]);
            $onlyPlan = count($planIds) === 1 ? $planIds[0] : 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $plan = 0;
                $trunk = (int) ($c['carrier_trunk_id'] ?? 0);
                if ($trunk > 0 && isset($trunkPlan[$trunk])) {
                    $plan = $trunkPlan[$trunk];
                }
                if ($plan <= 0) {
                    $sip = (int) ($c['sip_account_id'] ?? 0);
                    $plan = $sipPlan[$sip] ?? 0;
                }
                if ($plan <= 0) {
                    $plan = $this->tariffPlan($tariffs, (int) ($c['customer_id'] ?? 0), (string) ($c['dst_normalized'] ?? ''));
                }
                if ($plan <= 0) {
                    $plan = $onlyPlan;
                }
                if ($plan <= 0 || !isset($buckets[$plan])) {
                    continue;
                }
                $buckets[$plan]['attempts']++;
                $answered = (string) ($c['disposition'] ?? '') === 'ANSWERED' && (int) ($c['duration_actual'] ?? 0) > 0;
                if ($answered) {
                    $buckets[$plan]['answered']++;
                    $buckets[$plan]['seconds'] += (int) $c['duration_actual'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] routeHealth stats: ' . $e->getMessage());
        }
        return $this->finishPlanStats($buckets);
    }

    /** @param list<array<string,mixed>> $tariffs */
    private function tariffPlan(array $tariffs, int $customerId, string $dest): int
    {
        $bestLen = -1;
        $plan = 0;
        foreach ($tariffs as $t) {
            if ((int) ($t['customer_id'] ?? 0) !== $customerId) {
                continue;
            }
            $pfx = (string) ($t['prefix'] ?? '');
            if ($pfx !== '' && str_starts_with($dest, $pfx) && strlen($pfx) > $bestLen) {
                $bestLen = strlen($pfx);
                $plan = (int) $t['routing_plan_id'];
            }
        }
        return $plan;
    }

    /**
     * @param array<int,array{attempts:int,answered:int,seconds:int}> $buckets
     * @return array<int,array{attempts:int,answered:int,seconds:int,asr:string,acd:string}>
     */
    private function finishPlanStats(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $id => $b) {
            $out[$id] = AsrAcdService::compute($b['attempts'], $b['answered'], $b['seconds']);
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
