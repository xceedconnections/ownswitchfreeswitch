<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RatingService;

/** Payments added to user accounts (balance credits). */
final class PaymentController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $page = max(1, (int) $r->query('page', 1));
        $per = 25;
        $cid = is_string($r->query('customer_id')) && ctype_digit((string) $r->query('customer_id'))
            ? (int) $r->query('customer_id') : null;
        // Scope on ledger_entries (le), not customers (c) — owned mode uses customer_id column.
        [$tw, $tp] = $scope->where('owned', 'le');
        $where = [
            "({$tw})",
            'le.affects_balance = 1',
            'le.amount > 0',
            "le.entry_type IN ('topup','invoice_payment','initial_credit','adjustment','refund')",
        ];
        $params = $tp;
        if ($cid !== null && !$scope->isCustomerRole()) {
            $where[] = 'le.customer_id = ?';
            $params[] = $cid;
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $stc = $this->db()->prepare(
                "SELECT COUNT(*) FROM ledger_entries le
                 JOIN customers c ON c.id = le.customer_id
                 WHERE {$sqlWhere}"
            );
            $stc->execute($params);
            $total = (int) $stc->fetchColumn();
            $st = $this->db()->prepare(
                "SELECT le.id, le.created_at, le.entry_type, le.amount, le.currency, le.description, le.external_ref,
                        c.name AS customer_name, c.code AS customer_code,
                        p.method AS payment_method, p.reference AS payment_reference
                 FROM ledger_entries le
                 JOIN customers c ON c.id = le.customer_id
                 LEFT JOIN payments p ON p.ledger_entry_id = le.id
                 WHERE {$sqlWhere}
                 ORDER BY le.id DESC
                 LIMIT {$per} OFFSET " . (($page - 1) * $per)
            );
            $st->execute($params);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] payments: ' . $e->getMessage());
            $total = 0;
            $rows = [];
        }
        $isCustomer = $scope->isCustomerRole();
        return $this->view('payments/index', [
            'title' => 'Payments',
            'result' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per))],
            'customers' => $isCustomer ? [] : $this->customerOptions($r),
            'customer_id' => $cid !== null ? (string) $cid : '',
            'canWrite' => in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true),
            'isCustomer' => $isCustomer,
            'query' => $r->query,
        ]);
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true)) {
            return $this->forbidden();
        }
        return $this->view('payments/form', [
            'title' => 'Add payment',
            'customers' => $this->customerOptions($r),
            'values' => ['customer_id' => '', 'amount' => '', 'method' => 'bank', 'reference' => '', 'description' => ''],
            'errors' => [],
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true)) {
            return $this->forbidden();
        }
        $scope = $this->scope($r);
        $v = [
            'customer_id' => (string) ($r->post['customer_id'] ?? ''),
            'amount' => trim((string) ($r->post['amount'] ?? '')),
            'method' => trim((string) ($r->post['method'] ?? 'bank')),
            'reference' => trim((string) ($r->post['reference'] ?? '')),
            'description' => trim((string) ($r->post['description'] ?? '')),
        ];
        $errors = [];
        $cust = ctype_digit($v['customer_id']) ? (new CustomerRepository($this->db()))->find((int) $v['customer_id'], $scope) : null;
        if ($cust === null) {
            $errors[] = 'Select a valid user.';
        }
        if (!preg_match('/^\d+(\.\d{1,6})?$/', $v['amount']) || bccomp($v['amount'], '0', 6) <= 0) {
            $errors[] = 'Amount must be a positive decimal (max 6 places).';
        }
        if (!in_array($v['method'], ['bank', 'cash', 'card', 'wallet', 'other'], true)) {
            $errors[] = 'Invalid payment method.';
        }
        if (mb_strlen($v['reference']) > 190) {
            $errors[] = 'Reference is too long.';
        }
        if (mb_strlen($v['description']) > 200) {
            $errors[] = 'Description is too long.';
        }
        if ($errors) {
            return $this->view('payments/form', [
                'title' => 'Add payment',
                'customers' => $this->customerOptions($r),
                'values' => $v,
                'errors' => $errors,
            ], 422);
        }

        $amount = RatingService::round($v['amount'], 6);
        $nonce = is_string($r->post['nonce'] ?? null) ? (string) $r->post['nonce'] : bin2hex(random_bytes(8));
        $desc = $v['description'] !== '' ? $v['description'] : ('Payment via ' . $v['method']);
        if ($v['reference'] !== '') {
            $desc .= ' (' . $v['reference'] . ')';
        }
        $ledger = (new LedgerService($this->db()))->post([
            'customer_id' => (int) $cust['id'],
            'reseller_id' => $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null,
            'entry_type' => 'topup',
            'amount' => $amount,
            'currency' => (string) $cust['currency'],
            'idempotency_key' => "payment:{$cust['id']}:{$nonce}",
            'description' => mb_substr($desc, 0, 200),
            'created_by' => (int) $this->user($r)['id'],
            'external_ref' => $v['reference'] !== '' ? $v['reference'] : null,
        ]);
        try {
            $this->db()->prepare(
                'INSERT INTO payments (customer_id, invoice_id, amount, method, reference, ledger_entry_id, created_by) VALUES (?,?,?,?,?,?,?)'
            )->execute([
                (int) $cust['id'], null, $amount, $v['method'],
                $v['reference'] !== '' ? $v['reference'] : null,
                $ledger['id'], (int) $this->user($r)['id'],
            ]);
            $pid = (int) $this->db()->lastInsertId();
        } catch (\Throwable) {
            $pid = (int) $ledger['id'];
        }
        $this->audit($r, 'payment_create', 'payments', $pid, null, ['customer_id' => $cust['id'], 'amount' => $amount]);
        return $this->redirect($r, '/payments', $ledger['duplicate'] ? 'Payment already recorded (duplicate submit ignored).' : 'Payment added to user account.');
    }

    /** @return array<int|string,string> */
    private function customerOptions(Request $r): array
    {
        $o = [];
        foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
            $o[$c['id']] = "{$c['name']} ({$c['code']})";
        }
        return $o;
    }
}
