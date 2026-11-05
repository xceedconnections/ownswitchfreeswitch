<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use PDO;

/** Recomputes stats_hourly / stats_daily buckets from CDRs. Idempotent (buckets are overwritten with exact sums). */
final class StatsRollupService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{hourly:int,daily:int} affected row counts */
    public function rollup(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromH = $from->setTime((int) $from->format('H'), 0, 0)->format('Y-m-d H:i:s');
        $toH = $to->setTime((int) $to->format('H'), 0, 0)->modify('+1 hour')->format('Y-m-d H:i:s');
        $fromD = $from->format('Y-m-d 00:00:00');
        $toD = $to->modify('+1 day')->format('Y-m-d 00:00:00');

        $answered = "SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN 1 ELSE 0 END)";
        $talk = "COALESCE(SUM(CASE WHEN disposition = 'ANSWERED' AND duration_actual > 0 THEN duration_actual ELSE 0 END),0)";
        $upd = 'attempts = VALUES(attempts), answered = VALUES(answered), duration_seconds = VALUES(duration_seconds),
                billable_seconds = VALUES(billable_seconds), revenue = VALUES(revenue), cost = VALUES(cost)';

        $h = $this->pdo->prepare(
            "INSERT INTO stats_hourly (bucket_start, reseller_id, customer_id, carrier_id, attempts, answered, duration_seconds, billable_seconds, revenue, cost)
             SELECT DATE_FORMAT(setup_at, '%Y-%m-%d %H:00:00'), COALESCE(reseller_id,0), COALESCE(customer_id,0), COALESCE(carrier_id,0),
                    COUNT(*), {$answered}, {$talk}, COALESCE(SUM(duration_billable_customer),0), COALESCE(SUM(customer_charge),0), COALESCE(SUM(carrier_cost),0)
             FROM cdrs WHERE setup_at >= ? AND setup_at < ?
             GROUP BY 1, 2, 3, 4
             ON DUPLICATE KEY UPDATE {$upd}"
        );
        $h->execute([$fromH, $toH]);
        $hc = $h->rowCount();

        $d = $this->pdo->prepare(
            "INSERT INTO stats_daily (bucket_date, reseller_id, customer_id, carrier_id, attempts, answered, duration_seconds, billable_seconds, revenue, cost)
             SELECT DATE(setup_at), COALESCE(reseller_id,0), COALESCE(customer_id,0), COALESCE(carrier_id,0),
                    COUNT(*), {$answered}, {$talk}, COALESCE(SUM(duration_billable_customer),0), COALESCE(SUM(customer_charge),0), COALESCE(SUM(carrier_cost),0)
             FROM cdrs WHERE setup_at >= ? AND setup_at < ?
             GROUP BY 1, 2, 3, 4
             ON DUPLICATE KEY UPDATE {$upd}"
        );
        $d->execute([$fromD, $toD]);
        return ['hourly' => $hc, 'daily' => $d->rowCount()];
    }
}
