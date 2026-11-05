<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Cdr\CdrSearchService;

final class CdrController extends BaseController
{
    /** @return array<string,mixed> whitelisted string filters from query or POST */
    private function filters(Request $r): array
    {
        [$from, $to] = $this->dateRange($r, 1);
        $f = ['from' => $from, 'to' => $to, 'tz' => $this->userTimezone($r)];
        foreach (['customer_id', 'carrier_id', 'src', 'dst', 'disposition', 'min_duration', 'failed_only', 'from', 'to'] as $k) {
            $v = $r->query($k);
            if (!is_string($v) || $v === '') {
                $v = $r->input($k, null);
            }
            if (is_string($v) && $v !== '') {
                $f[$k] = $v;
            }
        }
        $f['tz'] = $this->userTimezone($r);
        return $f;
    }

    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $svc = new CdrSearchService($this->db());
        $f = $this->filters($r);
        $res = $svc->search($scope, $f, (int) $r->query('page', 1), 10,
            is_string($r->query('sort')) ? (string) $r->query('sort') : 'setup_at', is_string($r->query('dir')) ? (string) $r->query('dir') : 'desc');
        $customers = [];
        if (!$scope->isCustomerRole()) {
            foreach ((new CustomerRepository($this->db()))->all($scope, [], 500, 'name') as $c) {
                $customers[$c['id']] = $c['name'];
            }
        }
        $carriers = [];
        if ($scope->isGlobal()) {
            foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
                $carriers[$c['id']] = $c['name'];
            }
        }
        $failedOnly = ($f['failed_only'] ?? '') === '1';
        return $this->view('cdr/search', [
            'title' => $failedOnly ? 'Failed CDRs' : 'CDR Search',
            'result' => $res, 'f' => $f, 'customers' => $customers, 'carriers' => $carriers,
            'dispositions' => CdrSearchService::DISPOSITIONS, 'showCost' => $scope->isGlobal(), 'query' => $r->query,
            'failedOnly' => $failedOnly,
            'canDelete' => $scope->isStaff() || $scope->isGlobal(),
        ]);
    }

    public function show(Request $r, array $p): Response
    {
        $row = (new CdrSearchService($this->db()))->find($this->scope($r), (int) $p['id']);
        if ($row === null) {
            return $this->notFound('CDR not found');
        }
        $legs = [];
        try {
            $st = $this->db()->prepare('SELECT * FROM cdr_attempt_legs WHERE sip_call_id = ? ORDER BY attempt_no ASC, id ASC');
            $st->execute([(string) ($row['sip_call_id'] ?? '')]);
            $legs = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($legs as &$leg) {
                $leg['sip_code_label'] = \Ownswitch\Support\SipCode::label((int) ($leg['sip_code'] ?? 0));
            }
            unset($leg);
        } catch (\Throwable) {
        }
        return $this->view('cdr/show', [
            'title' => 'CDR #' . $row['id'],
            'cdr' => $row,
            'showCost' => $this->scope($r)->isGlobal(),
            'legs' => $legs,
        ]);
    }

    public function export(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $filters = $this->filters($r);
        $svc = new CdrSearchService($this->db());
        $fmt = strtolower((string) $r->query('format', 'csv'));
        $this->audit($r, 'cdr_export', 'cdrs', null, null, $filters + ['format' => $fmt]);
        $filename = 'cdrs-' . gmdate('Ymd-His') . ($fmt === 'excel' || $fmt === 'xls' ? '.xls' : '.csv');
        $ctype = ($fmt === 'excel' || $fmt === 'xls')
            ? 'application/vnd.ms-excel; charset=UTF-8'
            : 'text/csv; charset=UTF-8';
        return Response::stream(
            static function () use ($svc, $scope, $filters): void {
                $svc->exportCsv($scope, $filters, static function (string $chunk): void {
                    echo $chunk;
                });
            },
            $ctype,
            ['Content-Disposition' => 'attachment; filename="' . $filename . '"']
        );
    }

    public function delete(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        if (!$scope->isStaff() && !$scope->isGlobal()) {
            return $this->forbidden();
        }
        $mode = (string) $r->input('mode', 'selected');
        $svc = new CdrSearchService($this->db());
        $n = 0;
        if ($mode === 'selected') {
            $raw = $r->input('ids', []);
            $ids = [];
            if (is_array($raw)) {
                $ids = $raw;
            } elseif (is_string($raw)) {
                $ids = preg_split('/[\s,]+/', $raw) ?: [];
            }
            $n = $svc->deleteByIds($scope, array_map('intval', $ids));
        } elseif ($mode === 'filtered') {
            $n = $svc->deleteFiltered($scope, $this->filters($r));
        } elseif ($mode === 'all') {
            $n = $svc->deleteFiltered($scope, ['from' => '2000-01-01', 'to' => gmdate('Y-m-d')]);
        }
        $this->audit($r, 'cdr_delete', 'cdrs', null, null, ['mode' => $mode, 'count' => $n]);
        $qs = http_build_query(array_filter($this->filters($r), 'is_string'));
        return $this->redirect($r, '/cdrs' . ($qs !== '' ? '?' . $qs : ''), "Deleted {$n} CDR(s).", $n > 0 ? 'success' : 'warning');
    }
}
