<?php

declare(strict_types=1);

namespace Ownswitch\Services\Fraud;

use PDO;

/**
 * Signalling/CDR-based fraud detection only (no audio analysis).
 * Rule types: velocity, short_calls, destination_spread, blocked_prefix(not scanned here).
 */
final class SignalingFraudService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Pure detector over pre-aggregated per-customer stats. Easy to unit-test.
     *
     * @param array{attempts:int,answered:int,short_answered:int,distinct_prefixes:int} $stats
     * @param array{rule_type:string,threshold:string|int|float} $rule
     * @return array{triggered:bool,observed:string,summary:string}
     */
    public static function evaluate(array $stats, array $rule): array
    {
        $thr = (string) $rule['threshold'];
        switch ($rule['rule_type']) {
            case 'velocity':
                $obs = (string) $stats['attempts'];
                return ['triggered' => bccomp($obs, $thr, 4) >= 0, 'observed' => $obs, 'summary' => "{$obs} call attempts in window (threshold {$thr})"];
            case 'short_calls':
                $ans = (int) $stats['answered'];
                $pct = $ans >= 10 ? bcdiv(bcmul((string) $stats['short_answered'], '100', 6), (string) $ans, 2) : '0.00';   // need a minimum sample
                return ['triggered' => bccomp($pct, $thr, 2) >= 0, 'observed' => $pct, 'summary' => "{$pct}% of {$ans} answered calls shorter than 6s (threshold {$thr}%)"];
            case 'destination_spread':
                $obs = (string) $stats['distinct_prefixes'];
                return ['triggered' => bccomp($obs, $thr, 4) >= 0, 'observed' => $obs, 'summary' => "{$obs} distinct destination prefixes in window (threshold {$thr})"];
            default:
                return ['triggered' => false, 'observed' => '0', 'summary' => ''];
        }
    }

    /**
     * Scan recent CDRs, write deduplicated fraud_events. Returns number of new events.
     * `suspend` rules set the customer to 'suspended' (reversible by an admin).
     */
    public function scan(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rules = $this->pdo->query("SELECT * FROM fraud_rules WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $new = 0;
        foreach ($rules as $rule) {
            if (!in_array($rule['rule_type'], ['velocity', 'short_calls', 'destination_spread'], true)) {
                continue;
            }
            $win = max(60, (int) $rule['window_seconds']);
            $since = $now->modify("-{$win} seconds")->format('Y-m-d H:i:s');
            $st = $this->pdo->prepare(
                "SELECT customer_id, MAX(reseller_id) AS reseller_id, COUNT(*) AS attempts,
                    SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 AND duration_actual < 6 THEN 1 ELSE 0 END) AS short_answered,
                    COUNT(DISTINCT LEFT(dst_normalized, 5)) AS distinct_prefixes
                 FROM cdrs WHERE customer_id IS NOT NULL AND setup_at >= ? AND is_demo = 0 GROUP BY customer_id"
            );
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats = ['attempts' => (int) $row['attempts'], 'answered' => (int) $row['answered'],
                    'short_answered' => (int) $row['short_answered'], 'distinct_prefixes' => (int) $row['distinct_prefixes']];
                $res = self::evaluate($stats, $rule);
                if (!$res['triggered']) {
                    continue;
                }
                $bucket = intdiv($now->getTimestamp(), $win);   // one event per rule/customer/window
                $dedupe = "{$rule['code']}:{$row['customer_id']}:{$bucket}";
                $ins = $this->pdo->prepare(
                    'INSERT IGNORE INTO fraud_events (reseller_id, customer_id, rule_id, rule_code, severity, summary, details, dedupe_key)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $row['reseller_id'], $row['customer_id'], $rule['id'], $rule['code'], $rule['action'] === 'suspend' ? 'high' : 'medium',
                    mb_substr($res['summary'], 0, 250), json_encode(['stats' => $stats, 'window_seconds' => $win, 'observed' => $res['observed']], JSON_THROW_ON_ERROR), $dedupe,
                ]);
                if ($ins->rowCount() > 0) {
                    $new++;
                    if ($rule['action'] === 'suspend') {
                        $this->pdo->prepare("UPDATE customers SET status = 'suspended' WHERE id = ? AND status = 'active'")->execute([$row['customer_id']]);
                    }
                }
            }
        }
        return $new;
    }
}
