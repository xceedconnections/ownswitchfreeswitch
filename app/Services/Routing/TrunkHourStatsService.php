<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Services\Reports\AsrAcdService;
use PDO;

/**
 * Recent connected-call stats per carrier trunk (ASR / ACD / share / long calls).
 * Windows: 15 / 30 / 60 minutes. Defensive: missing cdrs or SQL errors → empty stats.
 */
final class TrunkHourStatsService
{
    public const WINDOWS = [15, 30, 60];
    public const LONG_CALL_SECONDS = 12;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int|string>|null $trunkIds null = all trunks with traffic in window
     * @return array<string,array{attempts:int,answered:int,failed:int,seconds:int,asr:float,acd:float,share:float,long_answered:int,long_seconds:int,long_acd:float,label:string,window_minutes:int}>
     */
    public function lastHour(?array $trunkIds = null): array
    {
        return $this->window(60, $trunkIds);
    }

    /**
     * @param list<int|string>|null $trunkIds
     * @return array<string,array{attempts:int,answered:int,failed:int,seconds:int,asr:float,acd:float,share:float,long_answered:int,long_seconds:int,long_acd:float,label:string,window_minutes:int}>
     */
    public function window(int $minutes, ?array $trunkIds = null): array
    {
        $minutes = self::normalizeMinutes($minutes);
        $longMin = self::LONG_CALL_SECONDS;
        try {
            if (!$this->tableExists('cdrs')) {
                return [];
            }
            $from = (new \DateTimeImmutable('now'))->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s');
            $sql = "SELECT c.carrier_trunk_id AS trunk_id,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                        COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN c.duration_actual ELSE 0 END),0) AS seconds,
                        SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual >= {$longMin} THEN 1 ELSE 0 END) AS long_answered,
                        COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual >= {$longMin} THEN c.duration_actual ELSE 0 END),0) AS long_seconds
                    FROM cdrs c
                    WHERE c.setup_at >= ? AND c.carrier_trunk_id IS NOT NULL";
            $params = [$from];
            if ($trunkIds !== null) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $trunkIds), static fn ($i) => $i > 0)));
                if ($ids === []) {
                    return [];
                }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " AND c.carrier_trunk_id IN ({$ph})";
                $params = array_merge($params, $ids);
            }
            $sql .= ' GROUP BY c.carrier_trunk_id';
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $totalAttempts = 0;
            foreach ($rows as $r) {
                $totalAttempts += (int) ($r['attempts'] ?? 0);
            }
            $label = self::windowLabel($minutes);
            $out = [];
            foreach ($rows as $r) {
                $tid = (string) (int) $r['trunk_id'];
                $computed = AsrAcdService::compute((int) $r['attempts'], (int) $r['answered'], (int) $r['seconds']);
                $attempts = (int) $computed['attempts'];
                $answered = (int) $computed['answered'];
                $longAnswered = (int) ($r['long_answered'] ?? 0);
                $longSeconds = (int) ($r['long_seconds'] ?? 0);
                $longAcd = $longAnswered > 0 ? round($longSeconds / $longAnswered, 1) : 0.0;
                $out[$tid] = [
                    'attempts' => $attempts,
                    'answered' => $answered,
                    'failed' => max(0, $attempts - $answered),
                    'seconds' => (int) $computed['seconds'],
                    'asr' => (float) $computed['asr'],
                    'acd' => (float) $computed['acd'],
                    'share' => $totalAttempts > 0 ? round(100 * $attempts / $totalAttempts, 1) : 0.0,
                    'long_answered' => $longAnswered,
                    'long_seconds' => $longSeconds,
                    'long_acd' => $longAcd,
                    'label' => $label,
                    'window_minutes' => $minutes,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[ownswitch] trunk window stats: ' . $e->getMessage());
            return [];
        }
    }

    public static function normalizeMinutes(int $minutes): int
    {
        return in_array($minutes, self::WINDOWS, true) ? $minutes : 60;
    }

    public static function windowLabel(int $minutes): string
    {
        $minutes = self::normalizeMinutes($minutes);
        return match ($minutes) {
            15 => 'last 15 min',
            30 => 'last 30 min',
            default => 'last hour',
        };
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
