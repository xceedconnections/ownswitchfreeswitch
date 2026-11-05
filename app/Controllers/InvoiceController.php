<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\InvoiceService;
use Ownswitch\Services\Billing\LedgerService;

final class InvoiceController extends BaseController
{
    private function repo(): BaseRepository
    {
        return new BaseRepository($this->db(), 'invoices', ['customer_id', 'status'], 'owned', ['number'], ['id', 'number', 'period_start', 'total', 'status']);
    }

    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $res = $this->repo()->paginate($scope, is_string($r->query('customer_id')) ? ['customer_id' => $r->query('customer_id')] : [], (int) $r->query('page', 1), 25);
        $customers = [];
        foreach ((new CustomerRepository($this->db()))->all($scope, [], 500, 'name') as $c) {
            $customers[$c['id']] = $c['name'];
        }
        return $this->view('invoices/index', [
            'title' => 'Invoices', 'result' => $res, 'customers' => $customers, 'canGenerate' => $scope->isStaff() || $scope->role === 'reseller',
        ]);
    }

    public function generate(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = (string) ($r->post['customer_id'] ?? '');
        $start = (string) ($r->post['period_start'] ?? '');
        $end = (string) ($r->post['period_end'] ?? '');
        $cust = ctype_digit($cid) ? (new CustomerRepository($this->db()))->find((int) $cid, $scope) : null;
        if ($cust === null) {
            return $this->redirect($r, '/invoices', 'Select a valid customer.', 'danger');
        }
        try {
            $inv = (new InvoiceService($this->db(), new LedgerService($this->db())))->generate((int) $cust['id'], $start, $end, (int) $this->user($r)['id']);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->redirect($r, '/invoices', $e->getMessage(), 'danger');
        } catch (\PDOException) {
            return $this->redirect($r, '/invoices', 'Invoice could not be created (duplicate period?).', 'danger');
        }
        $this->audit($r, 'invoice_generate', 'invoices', $inv['id'], null, $inv);
        return $this->redirect($r, '/invoices/' . $inv['id'], "Invoice {$inv['number']} generated.");
    }

    public function show(Request $r, array $p): Response
    {
        $inv = $this->repo()->find((int) $p['id'], $this->scope($r));   // tenant check happens here
        if ($inv === null) {
            return $this->notFound('Invoice not found');
        }
        $lines = $this->db()->prepare('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_no');
        $lines->execute([(int) $inv['id']]);
        $c = $this->db()->prepare('SELECT name, code FROM customers WHERE id = ?');
        $c->execute([(int) $inv['customer_id']]);
        return $this->view('invoices/show', ['title' => 'Invoice ' . $inv['number'], 'inv' => $inv, 'lines' => $lines->fetchAll(), 'customer' => $c->fetch() ?: []]);
    }
}
