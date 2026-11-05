<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Revenue / cost / margin from settled, answered CDRs only.
 * Failed / unrated / demo legs are counted in "Calls" but do not affect money columns.
 */
final class FinancialReportService
{
    private const GROUPS = ['day' => 'DATE(c.setup_at)', 'customer' => 'c.customer_id', 'carrier' => 'c.carrier_id', 'currency' => 'c.currency'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,totals:array<string,string>,show_cost:bool}
     */
    public function summary(TenantScope $scope, string $from, string $to, string $groupBy = 'day', string $tz = 'UTC'): array
    {
        $showCost = $scope->isGlobal();   // carrier cost/margin are platform-only
        if (!isset(self::GROUPS[$groupBy]) || (!$showCost && $groupBy === 'carrier')) {
            $groupBy = 'day';
        }
        $tz = \Ownswitch\Support\Timezone::normalize($tz);
        $offsetSec = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->getOffset();
        $groups = self::GROUPS;
        $groups['day'] = 'DATE(DATE_ADD(c.setup_at, INTERVAL ' . (int) $offsetSec . ' SECOND))';
        $expr = $groups[$groupBy];
        [$tw, $tp] = $scope->where('owned', 'c');
        $name = match ($groupBy) {
            'customer' => 'COALESCE(cu.name, CONCAT(\'#\', c.customer_id))',
            'carrier'  => 'COALESCE(ca.name, \'(none)\')',
            default    => "COALESCE(CAST({$expr} AS CHAR), '-')",
        };

        // Money only from settled answered calls (same gate as ledger post)
        $billed = "c.settlement_status = 'settled' AND c.disposition = 'ANSWERED' AND c.duration_actual > 0 AND IFNULL(c.is_demo, 0) = 0";

        $st = $this->pdo->prepare(
            "SELECT {$name} AS label,
                COALESCE(c.currency, '—') AS currency,
                COUNT(*) AS calls,
                SUM(CASE WHEN {$billed} THEN 1 ELSE 0 END) AS billed_calls,
                COALESCE(SUM(CASE WHEN {$billed} THEN c.duration_billable_customer ELSE 0 END), 0) AS billable_seconds,
                COALESCE(SUM(CASE WHEN {$billed} THEN c.customer_charge ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN {$billed} THEN c.carrier_cost ELSE 0 END), 0) AS cost
             FROM cdrs c
             LEFT JOIN customers cu ON cu.id = c.customer_id
             LEFT JOIN carriers ca ON ca.id = c.carrier_id
             WHERE ({$tw})
               AND c.setup_at >= ? AND c.setup_at < ?
               AND IFNULL(c.is_demo, 0) = 0
             GROUP BY label, c.currency
             ORDER BY label
             LIMIT 1000"
        );
        [$fromUtc, $toUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($from, $to, $tz);
        $st->execute([...$tp, $fromUtc, $toUtc]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tot = [
            'calls' => '0',
            'billed_calls' => '0',
            'billable_seconds' => '0',
            'revenue' => '0.000000',
            'cost' => '0.000000',
            'margin' => '0.000000',
        ];
        $currencies = [];
        foreach ($rows as &$r) {
            $rev = $this->dec6((string) $r['revenue']);
            $cost = $this->dec6((string) $r['cost']);
            $margin = bcsub($rev, $cost, 6);
            $r['revenue'] = $rev;
            $r['cost'] = $cost;
            $r['margin'] = $margin;
            $r['calls'] = (string) (int) $r['calls'];
            $r['billed_calls'] = (string) (int) $r['billed_calls'];
            $r['billable_seconds'] = (string) (int) $r['billable_seconds'];
            $r['margin_pct'] = bccomp($rev, '0', 6) > 0
                ? bcdiv(bcmul($margin, '100', 8), $rev, 2)
                : (bccomp($cost, '0', 6) > 0 ? '-100.00' : '0.00');

            if (!$showCost) {
                unset($r['cost'], $r['margin'], $r['margin_pct']);
            }
            $tot['calls'] = (string) ((int) $tot['calls'] + (int) $r['calls']);
            $tot['billed_calls'] = (string) ((int) $tot['billed_calls'] + (int) $r['billed_calls']);
            $tot['billable_seconds'] = (string) ((int) $tot['billable_seconds'] + (int) $r['billable_seconds']);
            $tot['revenue'] = bcadd($tot['revenue'], $rev, 6);
            $tot['cost'] = bcadd($tot['cost'], $cost, 6);
            $currencies[(string) $r['currency']] = true;
        }
        unset($r);

        $tot['margin'] = bcsub($tot['revenue'], $tot['cost'], 6);
        $tot['currencies'] = implode(',', array_keys($currencies));
        $tot['margin_pct'] = bccomp($tot['revenue'], '0', 6) > 0
            ? bcdiv(bcmul($tot['margin'], '100', 8), $tot['revenue'], 2)
            : (bccomp($tot['cost'], '0', 6) > 0 ? '-100.00' : '0.00');

        return ['rows' => $rows, 'totals' => $tot, 'show_cost' => $showCost];
    }

    private function dec6(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return '0.000000';
        }
        return bcadd($v, '0', 6);
    }
}
