<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use PDO;

/**
 * Prepaid / credit authorisation (BILLING_RULES section 4 & 5).
 * Reservations are taken under a row lock on `balances`, so concurrent calls cannot
 * overspend the same unreserved balance.
 */
final class PrepaidService
{
    public function __construct(private PDO $pdo, private LedgerService $ledger, private RatingService $rating)
    {
    }

    /** System default hard cap from Settings (prepaid_hard_cap_seconds). */
    public function systemHardCap(): int
    {
        return (new \Ownswitch\Services\Settings\MaxCallDuration($this->pdo))->systemDefault();
    }

    /**
     * Largest billable duration B (aligned to I + k*S) whose total charge fits within $available.
     * Returns 0 when even the initial block (plus connect fee) cannot be afforded.
     * A zero rate yields the hard cap (capped to an I+k*S aligned value).
     */
    public function maxDuration(string $available, string $ratePerMinute, int $initial, int $subsequent, string $connectFee = '0', int $hardCapSeconds = 7200): int
    {
        RatingService::assertDecimal($ratePerMinute, 'rate');
        RatingService::assertDecimal($connectFee, 'connect fee');
        if ($initial <= 0 || $subsequent <= 0) {
            throw new \InvalidArgumentException('Increments must be > 0');
        }
        $budget = bcsub($available, $connectFee, 6);
        if (bccomp($budget, '0', 6) < 0) {
            return 0;
        }
        $cap = max($hardCapSeconds, $initial);
        if (bccomp($ratePerMinute, '0', 12) === 0) {
            $secs = $cap;
        } else {
            $secs = (int) bcdiv(bcmul($budget, '60', 12), $ratePerMinute, 0);   // floor(budget*60/rate)
            $secs = min($secs, $cap);
        }
        if ($secs < $initial) {
            return 0;
        }
        $b = $initial + intdiv($secs - $initial, $subsequent) * $subsequent;
        // guard against half-up rounding pushing the final charge above the budget
        while ($b >= $initial && bccomp($this->rating->usageCharge($ratePerMinute, $b), $budget, 6) > 0) {
            $b = $b - $subsequent >= $initial ? $b - $subsequent : 0;
            if ($b === 0) {
                break;
            }
        }
        return $b;
    }

    /**
     * Atomically authorise a call and reserve credit. Idempotent per call_id.
     *
     * @param array{id:int,billing_mode:string,credit_limit:string,currency:string,reseller_id:?int} $customer
     * @return array{authorized:bool,reason?:string,max_seconds?:int,reserved?:string,reservation_id?:int}
     */
    public function authorize(array $customer, string $callId, string $rate, int $initial, int $subsequent, string $connectFee = '0', int $hardCapSeconds = 0, int $ttlSeconds = 0): array
    {
        if ($hardCapSeconds <= 0) {
            $hardCapSeconds = $this->systemHardCap();
        }
        $cid = (int) $customer['id'];
        return $this->ledger->tx(function () use ($customer, $callId, $rate, $initial, $subsequent, $connectFee, $hardCapSeconds, $ttlSeconds, $cid): array {
            $this->ledger->ensureBalanceRow($cid);
            $this->pdo->prepare('SELECT balance FROM balances WHERE customer_id = ? FOR UPDATE')->execute([$cid]);

            $ex = $this->pdo->prepare('SELECT id, amount, max_seconds, status FROM balance_reservations WHERE call_id = ?');
            $ex->execute([$callId]);
            if ($r = $ex->fetch(PDO::FETCH_ASSOC)) {
                return $r['status'] === 'open'
                    ? ['authorized' => true, 'max_seconds' => (int) $r['max_seconds'], 'reserved' => (string) $r['amount'], 'reservation_id' => (int) $r['id']]
                    : ['authorized' => false, 'reason' => 'reservation_closed'];
            }

            $avail = $this->ledger->availableCredit($cid, (string) $customer['billing_mode'], (string) $customer['credit_limit']);
            $max = $this->maxDuration($avail, $rate, $initial, $subsequent, $connectFee, $hardCapSeconds);
            if ($max <= 0) {
                return ['authorized' => false, 'reason' => 'insufficient_credit'];
            }
            $amount = $this->rating->rate($rate, $initial, $subsequent, $max, $connectFee)['total'];
            $ttl = $ttlSeconds > 0 ? $ttlSeconds : $max + 300;
            $this->pdo->prepare(
                'INSERT INTO balance_reservations (customer_id, call_id, amount, max_seconds, expires_at)
                 VALUES (?,?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
            )->execute([$cid, $callId, $amount, $max, $ttl]);
            $rid = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE balances SET reserved = reserved + ? WHERE customer_id = ?')->execute([$amount, $cid]);
            $this->ledger->post([
                'customer_id' => $cid, 'reseller_id' => $customer['reseller_id'] ?? null, 'entry_type' => 'reservation',
                'amount' => bcmul($amount, '-1', 6), 'currency' => (string) $customer['currency'], 'affects_balance' => false,
                'idempotency_key' => 'resv:' . $callId, 'external_ref' => $callId, 'description' => "Reserve for max {$max}s",
            ]);
            return ['authorized' => true, 'max_seconds' => $max, 'reserved' => $amount, 'reservation_id' => $rid];
        });
    }

    /** Release the open reservation of a call (hangup / settlement). Safe to call repeatedly. */
    public function release(int $customerId, string $callId, string $finalStatus = 'settled'): bool
    {
        return $this->ledger->tx(function () use ($customerId, $callId, $finalStatus): bool {
            $this->ledger->ensureBalanceRow($customerId);
            $this->pdo->prepare('SELECT balance FROM balances WHERE customer_id = ? FOR UPDATE')->execute([$customerId]);
            $st = $this->pdo->prepare("SELECT id, amount FROM balance_reservations WHERE call_id = ? AND customer_id = ? AND status = 'open' FOR UPDATE");
            $st->execute([$callId, $customerId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return false;
            }
            $this->pdo->prepare('UPDATE balance_reservations SET status = ?, closed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$finalStatus, $r['id']]);
            $this->pdo->prepare('UPDATE balances SET reserved = GREATEST(reserved - ?, 0) WHERE customer_id = ?')->execute([$r['amount'], $customerId]);
            $cur = $this->pdo->prepare('SELECT currency FROM customers WHERE id = ?');
            $cur->execute([$customerId]);
            $this->ledger->post([
                'customer_id' => $customerId, 'entry_type' => 'reservation_release', 'amount' => (string) $r['amount'],
                'currency' => (string) ($cur->fetchColumn() ?: 'USD'), 'affects_balance' => false,
                'idempotency_key' => 'resv_rel:' . $callId, 'external_ref' => $callId, 'description' => "Reservation {$finalStatus}",
            ]);
            return true;
        });
    }

    /** Expire reservations past their TTL (e.g. crashed call legs). Returns number released. */
    public function expireStale(): int
    {
        $rows = $this->pdo->query("SELECT customer_id, call_id FROM balance_reservations WHERE status = 'open' AND expires_at < UTC_TIMESTAMP() LIMIT 500")
            ->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $r) {
            $n += $this->release((int) $r['customer_id'], (string) $r['call_id'], 'expired') ? 1 : 0;
        }
        return $n;
    }
}
