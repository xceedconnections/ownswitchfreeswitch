<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * MagnusBilling-style call summaries from CDRs (session time, ASR, ACD, buy/sell/profit).
 */
final class CallSummaryService
{
    /**
     * Report catalog keyed by URL slug.
     *
     * @var array<string, array{title:string,bucket:?string,dim:?string,staff_only:bool,blurb:string}>
     */
    public const REPORTS = [
        'summary-day' => [
            'title' => 'Summary per Day',
            'bucket' => 'day',
            'dim' => null,
            'staff_only' => false,
            'blurb' => 'Calls, ASR, ACD, sell/buy and profit grouped by day',
        ],
        'summary-day-user' => [
            'title' => 'Summary Day User',
            'bucket' => 'day',
            'dim' => 'customer',
            'staff_only' => false,
            'blurb' => 'Per day and per user',
        ],
        'summary-day-trunk' => [
            'title' => 'Summary Day Trunk',
            'bucket' => 'day',
            'dim' => 'trunk',
            'staff_only' => true,
            'blurb' => 'Per day and per trunk',
        ],
        'summary-month' => [
            'title' => 'Summary per Month',
            'bucket' => 'month',
            'dim' => null,
            'staff_only' => false,
            'blurb' => 'Monthly totals',
        ],
        'summary-month-user' => [
            'title' => 'Summary Month User',
            'bucket' => 'month',
            'dim' => 'customer',
            'staff_only' => false,
            'blurb' => 'Per month and per user',
        ],
        'summary-month-trunk' => [
            'title' => 'Summary Month Trunk',
            'bucket' => 'month',
            'dim' => 'trunk',
            'staff_only' => true,
            'blurb' => 'Per month and per trunk',
        ],
        'summary-user' => [
            'title' => 'Summary per User',
            'bucket' => null,
            'dim' => 'customer',
            'staff_only' => false,
            'blurb' => 'Totals by user for the selected period',
        ],
        'summary-trunk' => [
            'title' => 'Summary per Trunk',
            'bucket' => null,
            'dim' => 'trunk',
            'staff_only' => true,
            'blurb' => 'Totals by trunk for the selected period',
        ],
        'summary-destination' => [
            'title' => 'Summary per Destination',
            'bucket' => null,
            'dim' => 'destination',
            'staff_only' => false,
            'blurb' => 'Totals by destination prefix',
        ],
        'summary-month-destination' => [
            'title' => 'Summary Month Destination',
            'bucket' => 'month',
            'dim' => 'destination',
            'staff_only' => false,
            'blurb' => 'Per month and destination prefix (Magnus Summary Month DID style)',
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,totals:array<string,mixed>,show_cost:bool,columns:list<string>}
     */
    public function run(string $slug, TenantScope $scope, string $from, string $to, string $tz = 'UTC'): array
    {
        $meta = self::REPORTS[$slug] ?? self::REPORTS['summary-day'];
        if (($meta['staff_only'] || $meta['dim'] === 'trunk') && !$scope->isGlobal()) {
            // Resellers/customers: fall back to user-safe dimensions
            if ($meta['dim'] === 'trunk') {
                $meta['dim'] = 'customer';
            }
            $meta['staff_only'] = false;
        }

        $showCost = $scope->isGlobal();
        $select = [];
        $group = [];
        $columns = [];
        $tz = \Ownswitch\Support\Timezone::normalize($tz);
        $offsetSec = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->getOffset();

        if ($meta['bucket'] === 'day') {
            $select[] = 'DATE(DATE_ADD(c.setup_at, INTERVAL ' . (int) $offsetSec . ' SECOND)) AS bucket';
            $group[] = 'bucket';
            $columns[] = 'Day';
        } elseif ($meta['bucket'] === 'month') {
            $select[] = "DATE_FORMAT(DATE_ADD(c.setup_at, INTERVAL " . (int) $offsetSec . " SECOND), '%Y-%m') AS bucket";
            $group[] = 'bucket';
            $columns[] = 'Month';
        }

        if ($meta['dim'] === 'customer') {
            $select[] = "COALESCE(NULLIF(cu.name, ''), NULLIF(sa.username, ''), CONCAT('SIP ', NULLIF(c.src, '')), '(unattributed)') AS dim_label";
            $group[] = 'dim_label';
            $columns[] = 'User';
        } elseif ($meta['dim'] === 'trunk') {
            $select[] = "COALESCE(NULLIF(t.name, ''), CONCAT('Trunk #', c.carrier_trunk_id), '(none)') AS dim_label";
            $group[] = 'dim_label';
            $columns[] = 'Trunk';
        } elseif ($meta['dim'] === 'destination') {
            $select[] = "COALESCE(NULLIF(p.destination, ''), NULLIF(c.dst_prefix, ''), '(unknown)') AS dim_label";
            $group[] = 'dim_label';
            $columns[] = 'Destination';
        }

        if ($select === []) {
            $select[] = "'Total' AS bucket";
            $columns[] = 'Period';
        }

        $metrics = "COUNT(*) AS calls,
            SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
            COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_actual ELSE 0 END), 0) AS session_time,
            COALESCE(SUM(c.customer_charge), 0) AS sell,
            COALESCE(SUM(c.carrier_cost), 0) AS buy,
            COALESCE(SUM(c.gross_margin), 0) AS profit";

        [$tw, $tp] = $scope->where('owned', 'c');
        $joins = ' LEFT JOIN customers cu ON cu.id = c.customer_id';
        if ($meta['dim'] === 'customer') {
            $joins .= ' LEFT JOIN sip_accounts sa ON sa.id = c.sip_account_id';
        }
        if ($meta['dim'] === 'trunk') {
            $joins .= ' LEFT JOIN carrier_trunks t ON t.id = c.carrier_trunk_id';
        }
        if ($meta['dim'] === 'destination') {
            $joins .= ' LEFT JOIN prefixes p ON p.prefix = c.dst_prefix';
        }

        $groupSql = $group !== [] ? ' GROUP BY ' . implode(', ', $group) : '';
        $orderParts = [];
        if (in_array('bucket', $group, true)) {
            $orderParts[] = 'bucket DESC';
        }
        if (in_array('dim_label', $group, true)) {
            $orderParts[] = 'dim_label ASC';
        }
        if ($orderParts === []) {
            $orderParts[] = 'session_time DESC';
        }
        $order = ' ORDER BY ' . implode(', ', $orderParts);

        $sql = 'SELECT ' . implode(', ', $select) . ', ' . $metrics
            . ' FROM cdrs c' . $joins
            . " WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?"
            . $groupSql . $order . ' LIMIT 2000';

        [$fromUtc, $toUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($from, $to, $tz);
        $st = $this->pdo->prepare($sql);
        $st->execute([...$tp, $fromUtc, $toUtc]);
        $rows = [];
        $tot = ['calls' => 0, 'answered' => 0, 'session_time' => 0, 'sell' => '0', 'buy' => '0', 'profit' => '0'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $calls = (int) $r['calls'];
            $answered = (int) $r['answered'];
            $session = (int) $r['session_time'];
            $stats = AsrAcdService::compute($calls, $answered, $session);
            $row = [
                'bucket' => (string) ($r['bucket'] ?? ''),
                'dim_label' => (string) ($r['dim_label'] ?? ''),
                'calls' => $calls,
                'answered' => $answered,
                'session_time' => $session,
                'asr' => $stats['asr'],
                'acd' => $stats['acd'],
                'sell' => (string) $r['sell'],
                'buy' => (string) $r['buy'],
                'profit' => (string) $r['profit'],
            ];
            if (!$showCost) {
                unset($row['buy'], $row['profit']);
            }
            $rows[] = $row;
            $tot['calls'] += $calls;
            $tot['answered'] += $answered;
            $tot['session_time'] += $session;
            $tot['sell'] = bcadd($tot['sell'], (string) $r['sell'], 6);
            $tot['buy'] = bcadd($tot['buy'], (string) $r['buy'], 6);
            $tot['profit'] = bcadd($tot['profit'], (string) $r['profit'], 6);
        }
        $totStats = AsrAcdService::compute($tot['calls'], $tot['answered'], $tot['session_time']);
        $tot['asr'] = $totStats['asr'];
        $tot['acd'] = $totStats['acd'];
        if (!$showCost) {
            unset($tot['buy'], $tot['profit']);
        }

        return [
            'rows' => $rows,
            'totals' => $tot,
            'show_cost' => $showCost,
            'columns' => $columns,
            'meta' => $meta,
        ];
    }
}
