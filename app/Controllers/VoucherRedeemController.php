<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RatingService;

/** Billing → Redeem voucher: a customer turns an unused voucher code into ledger credit. */
final class VoucherRedeemController extends BaseController
{
    public function form(Request $r, array $p = []): Response
    {
        return $this->render($r, ['code' => ''], []);
    }

    public function redeem(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $code = $this->normalizeCode((string) ($r->post['code'] ?? ''));
        $errors = [];

        if ($scope->customerId === null) {
            return $this->render($r, ['code' => $code], ['Your account is not linked to a customer, so vouchers cannot be redeemed.'], 403);
        }
        if (!preg_match('/^[A-Z0-9-]{6,32}$/', $code)) {
            return $this->render($r, ['code' => $code], ['Enter a valid voucher code.'], 422);
        }

        $cust = (new CustomerRepository($this->db()))->find($scope->customerId, $scope);
        if ($cust === null) {
            return $this->render($r, ['code' => $code], ['Customer account not found.'], 403);
        }

        $pdo = $this->db();
        $ledger = new LedgerService($pdo);
        $userId = (int) ($this->user($r)['id'] ?? 0) ?: null;
        $amountPosted = null;

        try {
            $res = $ledger->tx(function () use ($pdo, $ledger, $code, $cust, $userId, &$amountPosted): array {
                $st = $pdo->prepare('SELECT * FROM vouchers WHERE code = ? FOR UPDATE');
                $st->execute([$code]);
                $v = $st->fetch(\PDO::FETCH_ASSOC);
                if ($v === false) {
                    throw new \DomainException('Voucher code not found.');
                }
                if ((string) $v['status'] === 'used') {
                    throw new \DomainException('This voucher has already been used.');
                }
                if ((string) $v['status'] !== 'unused') {
                    throw new \DomainException('This voucher is not active.');
                }
                if ($v['expires_at'] !== null && strtotime((string) $v['expires_at']) !== false && strtotime((string) $v['expires_at']) < time()) {
                    throw new \DomainException('This voucher has expired.');
                }
                if (strtoupper((string) $v['currency']) !== strtoupper((string) $cust['currency'])) {
                    throw new \DomainException("This voucher is in {$v['currency']} but your account currency is {$cust['currency']}.");
                }

                $amount = RatingService::round((string) $v['amount'], 6);
                if (bccomp($amount, '0', 6) <= 0) {
                    throw new \DomainException('This voucher has no value.');
                }

                $pdo->prepare("UPDATE vouchers SET status = 'used', used_by_customer_id = ?, used_at = NOW() WHERE id = ? AND status = 'unused'")
                    ->execute([(int) $cust['id'], (int) $v['id']]);

                $pdo->prepare('INSERT INTO voucher_redemptions (voucher_id, customer_id, amount, currency, redeemed_by_user_id) VALUES (?,?,?,?,?)')
                    ->execute([(int) $v['id'], (int) $cust['id'], $amount, (string) $v['currency'], $userId]);
                $redemptionId = (int) $pdo->lastInsertId();

                $entry = $ledger->post([
                    'customer_id' => (int) $cust['id'],
                    'reseller_id' => $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null,
                    'entry_type' => 'topup',
                    'amount' => $amount,
                    'currency' => (string) $v['currency'],
                    'idempotency_key' => 'voucher:' . $code,
                    'source_system' => 'voucher',
                    'external_ref' => (string) $v['id'],
                    'description' => 'Voucher redeemed: ' . $code,
                    'created_by' => $userId,
                ]);
                if ($entry['duplicate']) {
                    throw new \DomainException('This voucher has already been credited.');
                }
                $pdo->prepare('UPDATE voucher_redemptions SET ledger_entry_id = ? WHERE id = ?')->execute([$entry['id'], $redemptionId]);
                $amountPosted = $amount;

                return ['voucher' => $v, 'ledger_entry_id' => $entry['id'], 'redemption_id' => $redemptionId];
            });
        } catch (\DomainException $e) {
            return $this->render($r, ['code' => $code], [$e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[ownswitch] voucher redeem: ' . $e->getMessage());
            return $this->render($r, ['code' => $code], ['Could not redeem the voucher; please try again or contact support.'], 500);
        }

        $this->audit($r, 'voucher_redeem', 'vouchers', (int) $res['voucher']['id'], ['status' => 'unused'], [
            'code' => $code,
            'status' => 'used',
            'customer_id' => (int) $cust['id'],
            'amount' => $amountPosted,
            'ledger_entry_id' => $res['ledger_entry_id'],
        ]);

        return $this->redirect(
            $r,
            '/billing/redeem-voucher',
            'Voucher redeemed: ' . fmt_money((string) $amountPosted, 2) . ' ' . $cust['currency'] . ' added to your balance.'
        );
    }

    private function normalizeCode(string $raw): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');
    }

    /**
     * @param array<string,string> $values
     * @param list<string> $errors
     */
    private function render(Request $r, array $values, array $errors, int $status = 200): Response
    {
        $recent = [];
        $balance = null;
        $currency = null;
        $cid = $this->scope($r)->customerId;
        if ($cid !== null) {
            try {
                $st = $this->db()->prepare(
                    'SELECT v.code, vr.amount, vr.currency, vr.redeemed_at
                     FROM voucher_redemptions vr JOIN vouchers v ON v.id = vr.voucher_id
                     WHERE vr.customer_id = ? ORDER BY vr.id DESC LIMIT 10'
                );
                $st->execute([$cid]);
                $recent = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $balance = (new LedgerService($this->db()))->balance($cid);
                $cust = (new CustomerRepository($this->db()))->find($cid, $this->scope($r));
                $currency = $cust['currency'] ?? null;
            } catch (\Throwable $e) {
                error_log('[ownswitch] voucher redeem page: ' . $e->getMessage());
            }
        }
        return $this->view('billing/redeem_voucher', [
            'title' => 'Redeem voucher',
            'values' => $values,
            'errors' => $errors,
            'recent' => $recent,
            'balance' => $balance,
            'currency' => $currency,
            'hasCustomer' => $cid !== null,
        ], $status);
    }
}
