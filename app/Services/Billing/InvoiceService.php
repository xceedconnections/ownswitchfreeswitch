<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use Ownswitch\Services\Settings\SettingsService;
use PDO;

/** Postpaid invoices generated from POSTED ledger entries only (never from editable CDR fields). */
final class InvoiceService
{
    public function __construct(private PDO $pdo, private LedgerService $ledger)
    {
    }

    /**
     * @param string $start Y-m-d (inclusive) @param string $end Y-m-d (inclusive)
     * @return array{id:int,number:string,total:string}
     */
    public function generate(int $customerId, string $start, string $end, ?int $userId): array
    {
        $s = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $e = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$s || !$e || $s > $e || $s->format('Y-m-d') !== $start || $e->format('Y-m-d') !== $end) {
            throw new \InvalidArgumentException('Invalid billing period.');
        }
        return $this->ledger->tx(function () use ($customerId, $s, $e, $userId): array {
            $c = $this->pdo->prepare('SELECT id, reseller_id, currency FROM customers WHERE id = ? FOR UPDATE');
            $c->execute([$customerId]);
            $cust = $c->fetch(PDO::FETCH_ASSOC);
            if (!$cust) {
                throw new \RuntimeException('Customer not found');
            }
            $ov = $this->pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND status <> 'void' AND period_start <= ? AND period_end >= ?");
            $ov->execute([$customerId, $e->format('Y-m-d'), $s->format('Y-m-d')]);
            if ((int) $ov->fetchColumn() > 0) {
                throw new \RuntimeException('An invoice already covers part of this period.');
            }
            $from = $s->format('Y-m-d 00:00:00');
            $to = $e->modify('+1 day')->format('Y-m-d 00:00:00');

            $q = $this->pdo->prepare(
                "SELECT DATE(l.created_at) AS d, COUNT(*) AS calls, COALESCE(SUM(c.duration_billable_customer),0) AS secs, SUM(l.amount) AS amt
                 FROM ledger_entries l LEFT JOIN cdrs c ON c.id = l.cdr_id
                 WHERE l.customer_id = ? AND l.entry_type = 'call_charge' AND l.created_at >= ? AND l.created_at < ?
                 GROUP BY DATE(l.created_at) ORDER BY d"
            );
            $q->execute([$customerId, $from, $to]);
            $lines = [];
            $subtotal = '0';
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $amt = bcmul((string) $r['amt'], '-1', 6);   // charges are negative in the ledger
                $lines[] = ["Call charges {$r['d']}", (int) $r['calls'], (int) $r['secs'], $amt];
                $subtotal = bcadd($subtotal, $amt, 6);
            }
            $rv = $this->pdo->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(r.amount),0) AS amt FROM ledger_entries r
                 JOIN ledger_entries o ON o.id = r.reference_entry_id AND o.entry_type = 'call_charge'
                 WHERE r.customer_id = ? AND r.entry_type = 'reversal' AND r.created_at >= ? AND r.created_at < ?"
            );
            $rv->execute([$customerId, $from, $to]);
            $rev = $rv->fetch(PDO::FETCH_ASSOC);
            if ((int) $rev['n'] > 0) {
                $amt = bcmul((string) $rev['amt'], '-1', 6);
                $lines[] = ['Corrections / reversals', (int) $rev['n'], 0, $amt];
                $subtotal = bcadd($subtotal, $amt, 6);
            }
            if ($lines === []) {
                throw new \RuntimeException('No posted call charges in this period; nothing to invoice.');
            }

            $number = sprintf('INV-%d-%s-%s', $customerId, $s->format('Ymd'), $e->format('Ymd'));
            $sub = RatingService::round($subtotal, 4);
            $taxPct = (new SettingsService($this->pdo))->get('tax_rate_percent', '0') ?? '0';
            if (!preg_match('/^\d+(\.\d{1,8})?$/', $taxPct)) {
                $taxPct = '0';
            }
            $tax = RatingService::round(bcmul($sub, bcdiv($taxPct, '100', 10), 10), 4);
            $total = RatingService::round(bcadd($sub, $tax, 10), 4);
            $this->pdo->prepare(
                "INSERT INTO invoices (number, reseller_id, customer_id, period_start, period_end, currency, subtotal, tax, total, status, issued_at, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?, 'issued', UTC_TIMESTAMP(), ?)"
            )->execute([$number, $cust['reseller_id'], $customerId, $s->format('Y-m-d'), $e->format('Y-m-d'), $cust['currency'], $sub, $tax, $total, $userId]);
            $invId = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, seconds, amount) VALUES (?,?,?,?,?,?)');
            foreach ($lines as $i => [$desc, $qty, $secs, $amt]) {
                $ins->execute([$invId, $i + 1, $desc, $qty, $secs, $amt]);
            }
            return ['id' => $invId, 'number' => $number, 'total' => $total];
        });
    }
}
