<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use PDO;
use PDOException;

/**
 * Append-only ledger. Sign: amount > 0 credits the customer, < 0 debits.
 * Idempotent on idempotency_key. Corrections are reversing entries (never UPDATE/DELETE).
 */
final class LedgerService
{
    public const TYPES = ['initial_credit', 'topup', 'adjustment', 'reservation', 'reservation_release', 'call_charge', 'refund', 'invoice_payment', 'reversal'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{customer_id:int,reseller_id?:?int,entry_type:string,amount:string,currency:string,idempotency_key:string,
     *   affects_balance?:bool,reference_entry_id?:?int,cdr_id?:?int,source_system?:?string,external_ref?:?string,
     *   run_type?:?string,description?:?string,created_by?:?int} $e
     * @return array{id:int,duplicate:bool}
     */
    public function post(array $e): array
    {
        if (!in_array($e['entry_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown ledger entry type');
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $e['amount'])) {
            throw new \InvalidArgumentException('Ledger amount must be a decimal string');
        }
        if ($e['idempotency_key'] === '') {
            throw new \InvalidArgumentException('idempotency_key is required');
        }
        $affects = $e['affects_balance'] ?? true;

        $result = $this->tx(function () use ($e, $affects): array {
            $cid = (int) $e['customer_id'];
            $this->ensureBalanceRow($cid);
            $this->pdo->prepare('SELECT balance FROM balances WHERE customer_id = ? FOR UPDATE')->execute([$cid]);

            $dup = $this->pdo->prepare('SELECT id FROM ledger_entries WHERE idempotency_key = ?');
            $dup->execute([$e['idempotency_key']]);
            $existing = $dup->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'duplicate' => true, 'balance_changed' => false];
            }

            try {
                $st = $this->pdo->prepare(
                    'INSERT INTO ledger_entries (reseller_id, customer_id, entry_type, amount, currency, affects_balance, reference_entry_id,
                        cdr_id, source_system, external_ref, run_type, idempotency_key, description, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $st->execute([
                    $e['reseller_id'] ?? null, $cid, $e['entry_type'], RatingService::round($e['amount'], 6), $e['currency'], $affects ? 1 : 0,
                    $e['reference_entry_id'] ?? null, $e['cdr_id'] ?? null, $e['source_system'] ?? null, $e['external_ref'] ?? null,
                    $e['run_type'] ?? null, $e['idempotency_key'], $e['description'] ?? null, $e['created_by'] ?? null,
                ]);
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {   // concurrent duplicate key
                    $dup->execute([$e['idempotency_key']]);
                    return ['id' => (int) $dup->fetchColumn(), 'duplicate' => true, 'balance_changed' => false];
                }
                throw $ex;
            }
            $id = (int) $this->pdo->lastInsertId();
            if ($affects) {
                $this->pdo->prepare('UPDATE balances SET balance = balance + ? WHERE customer_id = ?')
                    ->execute([RatingService::round($e['amount'], 6), $cid]);
            }
            return ['id' => $id, 'duplicate' => false, 'balance_changed' => (bool) $affects];
        });
        // Sync OpenSIPS allow_calls after top-up / spend (402 Payment Required gate)
        if (!empty($result['balance_changed'])) {
            try {
                \Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner::fromConfig()
                    ->refreshAllowCallsForCustomer((int) $e['customer_id']);
            } catch (\Throwable $ex) {
                error_log('[ownswitch] allow_calls sync after ledger: ' . $ex->getMessage());
            }
        }
        return ['id' => (int) $result['id'], 'duplicate' => (bool) $result['duplicate']];
    }

    /** Post a reversing entry that nets the original to zero. */
    public function reverse(int $entryId, string $reason, ?int $userId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ledger_entries WHERE id = ?');
        $st->execute([$entryId]);
        $orig = $st->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            throw new \RuntimeException('Ledger entry not found');
        }
        if ($orig['entry_type'] === 'reversal') {
            throw new \RuntimeException('A reversal cannot be reversed; post a new adjustment instead.');
        }
        return $this->post([
            'customer_id'        => (int) $orig['customer_id'],
            'reseller_id'        => $orig['reseller_id'] !== null ? (int) $orig['reseller_id'] : null,
            'entry_type'         => 'reversal',
            'amount'             => bcmul((string) $orig['amount'], '-1', 6),
            'currency'           => (string) $orig['currency'],
            'affects_balance'    => (bool) $orig['affects_balance'],
            'reference_entry_id' => $entryId,
            'idempotency_key'    => 'reversal:' . $entryId,
            'description'        => 'Reversal: ' . mb_substr($reason, 0, 200),
            'created_by'         => $userId,
        ]);
    }

    public function balance(int $customerId): string
    {
        $st = $this->pdo->prepare('SELECT balance FROM balances WHERE customer_id = ?');
        $st->execute([$customerId]);
        $v = $st->fetchColumn();
        return $v === false ? '0.000000' : (string) $v;
    }

    /**
     * Spendable amount: prepaid = balance - reserved; postpaid = credit_limit + balance - reserved
     * (balance is negative when the customer owes).
     */
    public function availableCredit(int $customerId, string $billingMode, string $creditLimit): string
    {
        $st = $this->pdo->prepare('SELECT balance, reserved FROM balances WHERE customer_id = ?');
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['balance' => '0', 'reserved' => '0'];
        $avail = bcsub((string) $row['balance'], (string) $row['reserved'], 6);
        return $billingMode === 'postpaid' ? bcadd($avail, $creditLimit, 6) : $avail;
    }

    public function ensureBalanceRow(int $customerId): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO balances (customer_id, balance, reserved) VALUES (?, 0, 0)')->execute([$customerId]);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
    }
}
