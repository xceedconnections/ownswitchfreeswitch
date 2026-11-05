<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\LedgerRepository;
use Ownswitch\Services\Billing\LedgerService;

final class LedgerController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $filters = [];
        foreach (['customer_id', 'entry_type'] as $k) {
            $v = $r->query($k);
            if (is_string($v) && $v !== '') {
                $filters[$k] = $v;
            }
        }
        $res = (new LedgerRepository($this->db()))->paginate($scope, $filters, (int) $r->query('page', 1), 50,
            is_string($r->query('sort')) ? (string) $r->query('sort') : 'id', is_string($r->query('dir')) ? (string) $r->query('dir') : 'desc');
        $customers = [];
        if (!$scope->isCustomerRole()) {
            foreach ((new CustomerRepository($this->db()))->all($scope, [], 500, 'name') as $c) {
                $customers[$c['id']] = $c['name'];
            }
        }
        $balance = null;
        $cid = $scope->isCustomerRole() ? $scope->customerId : (isset($filters['customer_id']) && ctype_digit($filters['customer_id']) ? (int) $filters['customer_id'] : null);
        if ($cid !== null && (new CustomerRepository($this->db()))->find($cid, $scope) !== null) {
            $balance = (new LedgerService($this->db()))->balance($cid);
        }
        return $this->view('ledger/index', [
            'title' => 'Ledger', 'result' => $res, 'customers' => $customers, 'filters' => $filters, 'balance' => $balance,
            'types' => LedgerService::TYPES, 'canReverse' => $scope->isStaff(), 'query' => $r->query,
        ]);
    }

    /** Staff only (route-level). Posts a reversing entry; the original is never modified. */
    public function reverse(Request $r, array $p): Response
    {
        $scope = $this->scope($r);
        $entry = (new LedgerRepository($this->db()))->find((int) $p['id'], $scope);
        if ($entry === null) {
            return $this->notFound('Ledger entry not found');
        }
        $reason = trim((string) ($r->post['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            return $this->redirect($r, '/ledger', 'A reason (min 3 characters) is required.', 'danger');
        }
        try {
            $res = (new LedgerService($this->db()))->reverse((int) $entry['id'], $reason, (int) $this->user($r)['id']);
        } catch (\RuntimeException $e) {
            return $this->redirect($r, '/ledger', $e->getMessage(), 'danger');
        }
        $this->audit($r, 'ledger_reverse', 'ledger_entries', (int) $entry['id'], null, ['reversal_id' => $res['id'], 'reason' => $reason]);
        return $this->redirect($r, '/ledger?customer_id=' . (int) $entry['customer_id'], $res['duplicate'] ? 'Entry was already reversed.' : 'Reversal posted.');
    }
}
