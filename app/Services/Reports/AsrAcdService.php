<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * ASR = answered / attempts * 100.  ACD = answered talk seconds / answered calls.
 * Longer periods are ALWAYS computed from summed attempts/answered/seconds, never by averaging daily percentages.
 */
final class AsrAcdService
{
    public const GROUPS = ['day' => 'DATE(c.setup_at)', 'customer' => 'c.customer_id', 'carrier' => 'c.carrier_id', 'prefix' => 'c.dst_prefix'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{attempts:int,answered:int,seconds:int,asr:string,acd:string} */
    public static function compute(int $attempts, int $answered, int $seconds): array
    {
        $asr = $attempts > 0
            ? \Ownswitch\Services\Billing\RatingService::round(bcdiv(bcmul((string) $answered, '100', 10), (string) $attempts, 10), 2)
            : '0.00';
        $acd = $answered > 0
            ? \Ownswitch\Services\Billing\RatingService::round(bcdiv((string) $seconds, (string) $answered, 10), 1)
            : '0.0';
        return ['attempts' => $attempts, 'answered' => $answered, 'seconds' => $seconds, 'asr' => $asr, 'acd' => $acd];
    }

    /**
     * Sum raw counters across rows then compute.
     *
     * @param list<array{attempts:int|string,answered:int|string,duration_seconds?:int|string,seconds?:int|string}> $rows
     */
    public static function aggregate(array $rows): array
    {
        $a = $n = $s = 0;
        foreach ($rows as $r) {
            $a += (int) $r['attempts'];
            $n += (int) $r['answered'];
            $s += (int) ($r['duration_seconds'] ?? $r['seconds'] ?? 0);
        }
        return self::compute($a, $n, $s);
    }

    /**
     * Roll daily aggregate rows (bucket_date, attempts, answered, duration_seconds) into week / month periods.
     *
     * @param list<array<string,mixed>> $dailyRows
     * @return list<array{period:string,attempts:int,answered:int,seconds:int,asr:string,acd:string}>
     */
    public static function rollupPeriods(array $dailyRows, string $period): array
    {
        $fmt = $period === 'month' ? 'Y-m' : 'o-\WW';
        $buckets = [];
        foreach ($dailyRows as $r) {
            $key = (new \DateTimeImmutable((string) $r['bucket_date']))->format($fmt);
            $buckets[$key][] = $r;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $key => $rows) {
            $out[] = ['period' => $key] + self::aggregate($rows);
        }
        return $out;
    }

    /**
     * Live ASR/ACD from raw CDRs.
     *
     * @return list<array{label:string,attempts:int,answered:int,seconds:int,asr:string,acd:string}>
     */
    public function live(TenantScope $scope, string $from, string $to, string $groupBy = 'day'): array
    {
        $this->requirePdo();
        $expr = self::GROUPS[$groupBy] ?? self::GROUPS['day'];
        if (!$scope->isGlobal() && $groupBy === 'carrier') {
            $expr = self::GROUPS['day'];   // non-global tenants never see carrier breakdowns
        }
        [$tw, $tp] = $scope->where('owned', 'c');
        $sql = "SELECT {$expr} AS label, COUNT(*) AS attempts,
                    SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                    COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN c.duration_actual ELSE 0 END),0) AS seconds
                FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
                GROUP BY label ORDER BY label LIMIT 1000";
        $st = $this->pdo->prepare($sql);
        $st->execute([...$tp, $from . ' 00:00:00', (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['label' => (string) ($r['label'] ?? '-')] + self::compute((int) $r['attempts'], (int) $r['answered'], (int) $r['seconds']);
        }
        return $out;
    }

    /**
     * Weekly/monthly ASR from stats_daily (sums), tenant-scoped.
     *
     * @return list<array{period:string,attempts:int,answered:int,seconds:int,asr:string,acd:string}>
     */
    public function periodFromAggregates(TenantScope $scope, string $from, string $to, string $period): array
    {
        $this->requirePdo();
        [$tw, $tp] = $scope->where('owned', 's');
        $st = $this->pdo->prepare("SELECT s.bucket_date, SUM(s.attempts) AS attempts, SUM(s.answered) AS answered, SUM(s.duration_seconds) AS duration_seconds
            FROM stats_daily s WHERE ({$tw}) AND s.bucket_date >= ? AND s.bucket_date <= ? GROUP BY s.bucket_date ORDER BY s.bucket_date");
        $st->execute([...$tp, $from, $to]);
        return self::rollupPeriods($st->fetchAll(PDO::FETCH_ASSOC), $period === 'month' ? 'month' : 'week');
    }

    private function requirePdo(): void
    {
        if ($this->pdo === null) {
            throw new \LogicException('Database required');
        }
    }
}
