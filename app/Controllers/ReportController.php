<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Reports\CallSummaryService;
use Ownswitch\Support\SipCode;
use Ownswitch\Services\Reports\FinancialReportService;
use Ownswitch\Support\Menu;

final class ReportController extends BaseController
{
    /** Magnus-style call summary (day / month / user / trunk / destination). */
    public function callSummary(Request $r, array $p = []): Response
    {
        $slug = (string) ($p['slug'] ?? 'summary-day');
        if (!isset(CallSummaryService::REPORTS[$slug])) {
            return $this->notFound();
        }
        $meta = CallSummaryService::REPORTS[$slug];
        $scope = $this->scope($r);
        $role = (string) ($this->user($r)['role'] ?? '');
        if ($meta['staff_only'] && !in_array($role, Menu::STAFF, true) && !$scope->isGlobal()) {
            return $this->forbidden();
        }

        [$from, $to] = $this->dateRange($r, $meta['bucket'] === 'month' ? 90 : 7);
        $res = (new CallSummaryService($this->db()))->run($slug, $scope, $from, $to, $this->userTimezone($r));
        [$res, $result, $query] = $this->paginateRows($res, $r, 10, ['from' => $from, 'to' => $to]);

        $reportLinks = [];
        foreach (CallSummaryService::REPORTS as $key => $rep) {
            if ($rep['staff_only'] && !in_array($role, Menu::STAFF, true) && !$scope->isGlobal()) {
                continue;
            }
            $reportLinks[] = ['label' => $rep['title'], 'path' => '/reports/' . $key];
        }

        return $this->view('reports/call_summary', [
            'title' => $meta['title'],
            'slug' => $slug,
            'from' => $from,
            'to' => $to,
            'res' => $res,
            'reportLinks' => $reportLinks,
            'result' => $result,
            'query' => $query,
        ]);
    }

    /** @deprecated kept for old bookmarks — redirects into call summary */
    public function asrAcd(Request $r, array $p = []): Response
    {
        return $this->callSummary($r, ['slug' => 'summary-day']);
    }

    public function financial(Request $r, array $p = []): Response
    {
        [$from, $to] = $this->dateRange($r, 30);
        $group = is_string($r->query('group')) ? (string) $r->query('group') : 'day';
        $res = (new FinancialReportService($this->db()))->summary($this->scope($r), $from, $to, $group, $this->userTimezone($r));
        [$res, $result, $query] = $this->paginateRows($res, $r, 10, ['from' => $from, 'to' => $to, 'group' => $group]);
        return $this->view('reports/financial', [
            'title' => 'Financial Report',
            'res' => $res,
            'from' => $from,
            'to' => $to,
            'group' => $group,
            'canCarrier' => $this->scope($r)->isStaff(),
            'result' => $result,
            'query' => $query,
        ]);
    }

    /** Old "CDR Failed" bookmark. Call history is the single CDR list. */
    public function cdrFailed(Request $r, array $p = []): Response
    {
        $q = $r->query;
        unset($q['disposition'], $q['failed_only']);
        $qs = http_build_query($q);
        return Response::redirect(url('/cdrs' . ($qs !== '' ? '?' . $qs : '')));
    }

    /** Post-dial delay (setup → answer) for answered calls. */
    public function pdd(Request $r, array $p = []): Response
    {
        [$from, $to, $fromTs, $toTs] = $this->dateRangeUtc($r, 7);
        $scope = $this->scope($r);
        [$tw, $tp] = $scope->where('owned', 'c');
        $res = [
            'attempts' => 0,
            'failed' => 0,
            'answered' => 0,
            'asr' => '0.00',
            'acd' => '0.0',
            'duration_min' => '0.00',
            'avg_pdd_ms' => '0',
            'p50_ms' => '0',
            'p95_ms' => '0',
            'rows' => [],
        ];
        try {
            $all = $this->db()->prepare(
                "SELECT COUNT(*) AS attempts,
                        SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                        COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_actual ELSE 0 END), 0) AS seconds
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?"
            );
            $all->execute([...$tp, $fromTs, $toTs]);
            $aggAll = $all->fetch(\PDO::FETCH_ASSOC) ?: [];
            $attempts = (int) ($aggAll['attempts'] ?? 0);
            $answeredAll = (int) ($aggAll['answered'] ?? 0);
            $seconds = (int) ($aggAll['seconds'] ?? 0);
            $stats = \Ownswitch\Services\Reports\AsrAcdService::compute($attempts, $answeredAll, $seconds);
            $res['attempts'] = $attempts;
            $res['answered'] = $answeredAll;
            $res['failed'] = max(0, $attempts - $answeredAll);
            $res['asr'] = $stats['asr'];
            $res['acd'] = $stats['acd'];
            $res['duration_min'] = number_format($seconds / 60, 2, '.', '');
            $res['talk_seconds'] = $seconds;

            $st = $this->db()->prepare(
                "SELECT COUNT(*) AS answered,
                        ROUND(AVG(TIMESTAMPDIFF(MICROSECOND, c.setup_at, c.answer_at) / 1000), 1) AS avg_pdd_ms
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
                   AND c.answer_at IS NOT NULL AND c.disposition = 'ANSWERED'"
            );
            $st->execute([...$tp, $fromTs, $toTs]);
            $agg = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            $res['avg_pdd_ms'] = (string) ($agg['avg_pdd_ms'] ?? '0');

            $samples = $this->db()->prepare(
                "SELECT TIMESTAMPDIFF(MICROSECOND, c.setup_at, c.answer_at) / 1000 AS pdd_ms
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
                   AND c.answer_at IS NOT NULL AND c.disposition = 'ANSWERED'
                 ORDER BY pdd_ms ASC
                 LIMIT 5000"
            );
            $samples->execute([...$tp, $fromTs, $toTs]);
            $vals = array_map(static fn ($row) => (float) $row['pdd_ms'], $samples->fetchAll(\PDO::FETCH_ASSOC) ?: []);
            $n = count($vals);
            if ($n > 0) {
                $res['p50_ms'] = (string) round($vals[(int) floor(($n - 1) * 0.50)], 1);
                $res['p95_ms'] = (string) round($vals[(int) floor(($n - 1) * 0.95)], 1);
            }

            $byTrunk = $this->db()->prepare(
                "SELECT COALESCE(NULLIF(t.name, ''), (SELECT ct.name FROM carrier_trunks ct WHERE c.sip_trace LIKE CONCAT('%sofia/gateway/gw-', ct.id, '/%') ORDER BY ct.id ASC LIMIT 1), '(none)') AS label,
                        COUNT(*) AS answered,
                        ROUND(AVG(TIMESTAMPDIFF(MICROSECOND, c.setup_at, c.answer_at) / 1000), 1) AS avg_pdd_ms
                 FROM cdrs c
                 LEFT JOIN carrier_trunks t ON t.id = c.carrier_trunk_id
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
                   AND c.answer_at IS NOT NULL AND c.disposition = 'ANSWERED'
                 GROUP BY label
                 ORDER BY answered DESC
                 LIMIT 50"
            );
            $byTrunk->execute([...$tp, $fromTs, $toTs]);
            $res['rows'] = $byTrunk->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] pdd report: ' . $e->getMessage());
        }
        return $this->view('reports/pdd', [
            'title' => 'PDD Report',
            'res' => $res,
            'from' => $from,
            'to' => $to,
            'staff' => $scope->isStaff(),
        ]);
    }

    /** SIP response code counts for a time frame. */
    public function sipResponse(Request $r, array $p = []): Response
    {
        $window = is_string($r->query('window')) ? (string) $r->query('window') : '24h';
        if (!in_array($window, ['1h', '3h', '6h', '12h', '24h', 'today', 'custom'], true)) {
            $window = '24h';
        }
        [$from, $to] = $this->dateRange($r, 0);
        $tz = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        if ($window === 'today') {
            $userTz = new \DateTimeZone($this->userTimezone($r));
            $startLocal = new \DateTimeImmutable('today', $userTz);
            $endLocal = $startLocal->modify('+1 day');
            $fromUtc = $startLocal->setTimezone($tz)->format('Y-m-d H:i:s');
            $toUtc = $endLocal->setTimezone($tz)->format('Y-m-d H:i:s');
            $label = 'Today';
        } elseif ($window === 'custom') {
            [$from, $to, $fromUtc, $toUtc] = $this->dateRangeUtc($r, 0);
            $label = $from . ' to ' . $to;
        } else {
            $hours = (int) $window;
            $fromUtc = $now->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
            $toUtc = $now->modify('+1 minute')->format('Y-m-d H:i:s');
            $label = 'Last ' . $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        $scope = $this->scope($r);
        [$tw, $tp] = $scope->where('owned', 'c');
        $rows = [];
        $total = 0;
        try {
            $st = $this->db()->prepare(
                "SELECT COALESCE(c.sip_response, 0) AS code, COUNT(*) AS calls
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
                 GROUP BY code
                 ORDER BY calls DESC, code ASC"
            );
            $st->execute([...$tp, $fromUtc, $toUtc]);
            $raw = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($raw as $row) {
                $total += (int) $row['calls'];
            }
            foreach ($raw as $row) {
                $code = (int) $row['code'];
                $calls = (int) $row['calls'];
                $rows[] = [
                    'code' => $code > 0 ? (string) $code : '—',
                    'label' => $code > 0 ? SipCode::label($code) : 'No SIP code',
                    'calls' => $calls,
                    'share' => $total > 0 ? number_format($calls * 100 / $total, 1, '.', '') : '0.0',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] sip response report: ' . $e->getMessage());
        }
        return $this->view('reports/sip_response', [
            'title' => 'SIP Response Breakdown',
            'rows' => $rows,
            'total' => $total,
            'window' => $window,
            'from' => $from,
            'to' => $to,
            'label' => $label,
        ]);
    }

    /** Unrated / unattributed / rating-discrepancy CDRs. */
    public function cdrReconciliation(Request $r, array $p = []): Response
    {
        [$from, $to, $fromTs, $toTs] = $this->dateRangeUtc($r, 7);
        $focus = is_string($r->query('focus')) ? (string) $r->query('focus') : 'all';
        if (!in_array($focus, ['all', 'unrated', 'unattributed', 'discrepancy'], true)) {
            $focus = 'all';
        }
        $scope = $this->scope($r);
        [$tw, $tp] = $scope->where('owned', 'c');
        $page = max(1, (int) $r->query('page', 1));
        $per = 10;
        $issue = "(c.settlement_status IN ('unrated','unattributed') OR c.rating_discrepancy = 1)";
        $focusSql = match ($focus) {
            'unrated' => "c.settlement_status = 'unrated'",
            'unattributed' => "c.settlement_status = 'unattributed'",
            'discrepancy' => 'c.rating_discrepancy = 1',
            default => $issue,
        };
        $counts = ['unrated' => 0, 'unattributed' => 0, 'discrepancy' => 0];
        $total = 0;
        $rows = [];
        try {
            $cst = $this->db()->prepare(
                "SELECT
                    SUM(CASE WHEN c.settlement_status = 'unrated' THEN 1 ELSE 0 END) AS unrated,
                    SUM(CASE WHEN c.settlement_status = 'unattributed' THEN 1 ELSE 0 END) AS unattributed,
                    SUM(CASE WHEN c.rating_discrepancy = 1 THEN 1 ELSE 0 END) AS discrepancy
                 FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ? AND {$issue}"
            );
            $cst->execute([...$tp, $fromTs, $toTs]);
            $c = $cst->fetch(\PDO::FETCH_ASSOC) ?: [];
            $counts = [
                'unrated' => (int) ($c['unrated'] ?? 0),
                'unattributed' => (int) ($c['unattributed'] ?? 0),
                'discrepancy' => (int) ($c['discrepancy'] ?? 0),
            ];
            $stc = $this->db()->prepare(
                "SELECT COUNT(*) FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ? AND {$focusSql}"
            );
            $stc->execute([...$tp, $fromTs, $toTs]);
            $total = (int) $stc->fetchColumn();
            $st = $this->db()->prepare(
                "SELECT c.id, c.setup_at, c.disposition, c.settlement_status, c.rating_discrepancy,
                        c.customer_charge, c.carrier_cost
                 FROM cdrs c
                 WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ? AND {$focusSql}
                 ORDER BY c.setup_at DESC
                 LIMIT {$per} OFFSET " . (($page - 1) * $per)
            );
            $st->execute([...$tp, $fromTs, $toTs]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] cdr reconciliation: ' . $e->getMessage());
        }
        $query = $r->query;
        $query['from'] = $from;
        $query['to'] = $to;
        $query['focus'] = $focus;
        return $this->view('reports/cdr_reconciliation', [
            'title' => 'CDR Reconciliation',
            'result' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $per, 'pages' => max(1, (int) ceil(max(0, $total) / $per))],
            'from' => $from,
            'to' => $to,
            'focus' => $focus,
            'counts' => $counts,
            'query' => $query,
            'showCost' => $scope->isStaff(),
        ]);
    }

    /**
     * Slice in-memory report rows for pagination; totals stay for the full set.
     *
     * @param array<string,mixed> $res
     * @param array<string,string> $keepQuery
     * @return array{0:array<string,mixed>,1:array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int},2:array<string,string>}
     */
    private function paginateRows(array $res, Request $r, int $perPage, array $keepQuery): array
    {
        $all = array_values($res['rows'] ?? []);
        $total = count($all);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $r->query('page', 1)));
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $res['rows'] = $slice;
        $query = $keepQuery;
        foreach ($r->query as $k => $v) {
            if (is_string($k) && is_string($v) && $k !== 'page' && !array_key_exists($k, $query)) {
                $query[$k] = $v;
            }
        }
        $result = [
            'rows' => $slice,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => $pages,
        ];
        return [$res, $result, $query];
    }
}
