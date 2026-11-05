<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\BillingPreviewService;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RatingService;

final class BillingController extends BaseController
{
    private const FIELDS = ['rate', 'initial', 'subsequent', 'duration', 'connect_fee', 'carrier_rate', 'carrier_initial', 'carrier_subsequent', 'carrier_connect_fee'];

    public function previewForm(Request $r, array $p = []): Response
    {
        return $this->view('billing/preview', [
            'title' => 'Billing Preview', 'values' => ['rate' => '0.0100', 'initial' => '12', 'subsequent' => '6', 'duration' => '16', 'connect_fee' => '0',
                'carrier_rate' => '0.0060', 'carrier_initial' => '6', 'carrier_subsequent' => '6', 'carrier_connect_fee' => '0'],
            'errors' => [], 'result' => null,
        ]);
    }

    public function preview(Request $r, array $p = []): Response
    {
        $values = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post[$k] ?? '';
            $values[$k] = is_string($v) ? trim($v) : '';
        }
        $out = (new BillingPreviewService())->preview($values);   // server-side RatingService, never JS
        return $this->view('billing/preview', ['title' => 'Billing Preview', 'values' => $values, 'errors' => $out['errors'], 'result' => $out['result']], $out['errors'] ? 422 : 200);
    }

    public function topupForm(Request $r, array $p = []): Response
    {
        return $this->view('billing/topup', [
            'title' => 'Refills', 'customers' => $this->customerOptions($r), 'values' => ['customer_id' => '', 'type' => 'topup', 'amount' => '', 'description' => ''], 'errors' => [],
        ]);
    }

    public function topup(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $v = ['customer_id' => (string) ($r->post['customer_id'] ?? ''), 'type' => (string) ($r->post['type'] ?? 'topup'),
            'amount' => trim((string) ($r->post['amount'] ?? '')), 'description' => trim((string) ($r->post['description'] ?? ''))];
        $errors = [];
        $cust = ctype_digit($v['customer_id']) ? (new CustomerRepository($this->db()))->find((int) $v['customer_id'], $scope) : null;
        if ($cust === null) {
            $errors[] = 'Select a valid customer.';
        }
        if (!in_array($v['type'], ['topup', 'adjustment', 'refund'], true)) {
            $errors[] = 'Invalid entry type.';
        }
        // adjustment may be negative; topup/refund must be positive magnitudes (refund debits).
        if (!preg_match($v['type'] === 'adjustment' ? '/^-?\d+(\.\d{1,6})?$/' : '/^\d+(\.\d{1,6})?$/', $v['amount']) || bccomp($v['amount'], '0', 6) === 0) {
            $errors[] = 'Amount must be a non-zero decimal with up to 6 places' . ($v['type'] === 'adjustment' ? ' (negative allowed).' : '.');
        }
        if (mb_strlen($v['description']) < 3 || mb_strlen($v['description']) > 200) {
            $errors[] = 'A description (3-200 chars) is required for the audit trail.';
        }
        if ($errors) {
            return $this->view('billing/topup', ['title' => 'Refills', 'customers' => $this->customerOptions($r), 'values' => $v, 'errors' => $errors], 422);
        }
        $amount = $v['type'] === 'refund' ? bcmul($v['amount'], '-1', 6) : $v['amount'];
        // Idempotency key includes a form nonce so a double-submit does not post twice.
        $nonce = is_string($r->post['nonce'] ?? null) ? (string) $r->post['nonce'] : bin2hex(random_bytes(8));
        $res = (new LedgerService($this->db()))->post([
            'customer_id' => (int) $cust['id'], 'reseller_id' => $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null,
            'entry_type' => $v['type'], 'amount' => RatingService::round($amount, 6), 'currency' => (string) $cust['currency'],
            'idempotency_key' => "manual:{$v['type']}:{$cust['id']}:{$nonce}", 'description' => $v['description'], 'created_by' => (int) $this->user($r)['id'],
        ]);
        $this->audit($r, 'ledger_' . $v['type'], 'ledger_entries', $res['id'], null, ['customer_id' => $cust['id'], 'amount' => $amount]);
        return $this->redirect($r, '/ledger?customer_id=' . (int) $cust['id'], $res['duplicate'] ? 'Entry already posted (duplicate submit ignored).' : 'Ledger entry posted.');
    }

    /** @return array<int|string,string> */
    private function customerOptions(Request $r): array
    {
        $o = [];
        foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
            $o[$c['id']] = "{$c['name']} ({$c['code']}, {$c['billing_mode']}, {$c['currency']})";
        }
        return $o;
    }
}
