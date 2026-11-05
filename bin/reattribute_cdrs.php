<?php

declare(strict_types=1);

/**
 * Backfill customer / SIP / trunk on CDRs and rate buy+sell (margin).
 *
 *   php bin/reattribute_cdrs.php
 *   php bin/reattribute_cdrs.php --limit=500
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Settings\SettingsService;

$limit = 500;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d{1,5})$/', $a, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$pdo = App::db();
$settings = new SettingsService($pdo);
$rating = new RatingService();
$resolver = new RateResolver($pdo, $settings);
$ledger = new LedgerService($pdo);

$sole = $pdo->query("SELECT id, customer_id, username FROM sip_accounts WHERE status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$soleSip = count($sole) === 1 ? $sole[0] : null;

$soleTrunkRows = $pdo->query("SELECT id FROM carrier_trunks WHERE status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$soleTrunkId = count($soleTrunkRows) === 1 ? (int) $soleTrunkRows[0] : null;

$st = $pdo->prepare(
    "SELECT id, src, dst_normalized, duration_actual, disposition, customer_id, sip_account_id,
            carrier_trunk_id, carrier_id, settlement_status, sip_call_id, external_cdr_key, currency,
            customer_charge, carrier_cost
     FROM cdrs
     WHERE customer_id IS NULL
        OR sip_account_id IS NULL
        OR carrier_trunk_id IS NULL
        OR settlement_status IN ('unattributed','unrated','no_charge')
        OR (disposition = 'ANSWERED' AND duration_actual > 0 AND CAST(carrier_cost AS DECIMAL(18,6)) = 0
            AND carrier_trunk_id IS NOT NULL)
     ORDER BY id DESC
     LIMIT {$limit}"
);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$fixed = 0;
$charged = 0;
$costed = 0;

foreach ($rows as $cdr) {
    $sipId = $cdr['sip_account_id'] !== null ? (int) $cdr['sip_account_id'] : null;
    $customerId = $cdr['customer_id'] !== null ? (int) $cdr['customer_id'] : null;
    $trunkId = $cdr['carrier_trunk_id'] !== null ? (int) $cdr['carrier_trunk_id'] : null;

    if ($sipId === null && $soleSip !== null) {
        $sipId = (int) $soleSip['id'];
        $customerId = (int) $soleSip['customer_id'];
    }
    if ($sipId === null && !empty($cdr['src'])) {
        $q = $pdo->prepare('SELECT id, customer_id FROM sip_accounts WHERE username = ? LIMIT 1');
        $q->execute([(string) $cdr['src']]);
        $hit = $q->fetch(PDO::FETCH_ASSOC);
        if ($hit) {
            $sipId = (int) $hit['id'];
            $customerId = (int) $hit['customer_id'];
        }
    }
    if ($trunkId === null && $soleTrunkId !== null) {
        $trunkId = $soleTrunkId;
    }

    if ($customerId === null && $trunkId === null) {
        continue;
    }

    $customer = null;
    if ($customerId !== null) {
        $cust = $pdo->prepare('SELECT id, reseller_id, currency, billing_mode, credit_limit FROM customers WHERE id = ?');
        $cust->execute([$customerId]);
        $customer = $cust->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$customer) {
            $customerId = null;
        }
    }

    if ($customerId !== null) {
        $pdo->prepare(
            'UPDATE cdrs SET customer_id = ?, reseller_id = ?, sip_account_id = COALESCE(sip_account_id, ?),
                carrier_trunk_id = COALESCE(?, carrier_trunk_id), currency = COALESCE(currency, ?)
             WHERE id = ?'
        )->execute([
            $customerId,
            $customer['reseller_id'],
            $sipId,
            $trunkId,
            $customer['currency'],
            (int) $cdr['id'],
        ]);
        $fixed++;
    } elseif ($trunkId !== null) {
        $pdo->prepare('UPDATE cdrs SET carrier_trunk_id = ? WHERE id = ? AND carrier_trunk_id IS NULL')
            ->execute([$trunkId, (int) $cdr['id']]);
        $fixed++;
    }

    $answered = ($cdr['disposition'] === 'ANSWERED' && (int) $cdr['duration_actual'] > 0);
    if (!$answered || $customerId === null || $customer === null) {
        if ($customerId !== null && ($cdr['settlement_status'] ?? '') === 'unattributed') {
            $pdo->prepare("UPDATE cdrs SET settlement_status = 'no_charge' WHERE id = ?")->execute([(int) $cdr['id']]);
        }
        continue;
    }

    $chk = $pdo->prepare(
        'SELECT customer_charge, carrier_cost, settlement_status, external_cdr_key, carrier_trunk_id, currency
         FROM cdrs WHERE id = ?'
    );
    $chk->execute([(int) $cdr['id']]);
    $cur = $chk->fetch(PDO::FETCH_ASSOC) ?: [];
    $trunkId = $cur['carrier_trunk_id'] !== null ? (int) $cur['carrier_trunk_id'] : $trunkId;
    $dst = (string) $cdr['dst_normalized'];
    $duration = (int) $cdr['duration_actual'];
    $key = (string) ($cur['external_cdr_key'] ?? ('cdr:' . $cdr['id']));

    $needSell = bccomp((string) ($cur['customer_charge'] ?? '0'), '0', 6) <= 0;
    $needBuy = $trunkId !== null && bccomp((string) ($cur['carrier_cost'] ?? '0'), '0', 6) <= 0;

    if (!$needSell && !$needBuy) {
        continue;
    }

    $crate = $needSell ? $resolver->customer($customerId, $dst) : null;
    if ($needSell && $crate === null) {
        $pdo->prepare("UPDATE cdrs SET settlement_status = 'unrated' WHERE id = ?")->execute([(int) $cdr['id']]);
        echo "CDR #{$cdr['id']}: attributed but unrated (no customer tariff for {$dst})\n";
        $needSell = false;
    }

    $c = null;
    if ($needSell && $crate !== null) {
        $c = $rating->rate($crate['rate'], $crate['initial'], $crate['subsequent'], $duration, $crate['connect_fee'], $crate['charge_connect_on_zero'], $crate['min_billable']);
    }

    $carrierTotal = (string) ($cur['carrier_cost'] ?? '0.000000');
    $carrierId = $cdr['carrier_id'] !== null ? (int) $cdr['carrier_id'] : null;
    $carrierFields = null;
    if ($needBuy && $trunkId !== null) {
        $k = $resolver->carrier($trunkId, $dst);
        if ($k !== null) {
            $kr = $rating->rate($k['rate'], $k['initial'], $k['subsequent'], $duration, $k['connect_fee']);
            $carrierTotal = $kr['total'];
            $carrierId = $k['carrier_id'];
            $carrierFields = [
                'carrier_rate' => $k['rate'],
                'carrier_initial' => $k['initial'],
                'carrier_subsequent' => $k['subsequent'],
                'duration_billable_carrier' => $kr['billable_seconds'],
                'carrier_cost' => $kr['total'],
                'carrier_connect_fee' => $kr['connect_fee'],
            ];
        } else {
            $t = $pdo->prepare('SELECT carrier_id FROM carrier_trunks WHERE id = ?');
            $t->execute([$trunkId]);
            $carrierId = ($v = $t->fetchColumn()) !== false ? (int) $v : null;
            echo "CDR #{$cdr['id']}: trunk #{$trunkId} has no matching carrier tariff for {$dst}\n";
        }
    }

    $sellTotal = $c !== null ? $c['total'] : (string) ($cur['customer_charge'] ?? '0');
    $margin = $rating->margin($sellTotal, $carrierTotal);

    $ledger->tx(function () use (
        $pdo, $cdr, $customer, $customerId, $c, $crate, $carrierId, $carrierFields, $margin, $key, $ledger,
        $needSell, $trunkId, $sellTotal
    ): void {
        if ($needSell && $c !== null && $crate !== null) {
            $pdo->prepare(
                "UPDATE cdrs SET
                    dst_prefix = COALESCE(?, dst_prefix),
                    customer_rate = ?, customer_initial = ?, customer_subsequent = ?,
                    duration_billable_customer = ?, customer_charge = ?, customer_connect_fee = ?,
                    customer_rate_plan_version_id = ?, rating_source = ?, rated_at = UTC_TIMESTAMP(3),
                    carrier_trunk_id = COALESCE(?, carrier_trunk_id),
                    carrier_id = COALESCE(?, carrier_id),
                    settlement_status = 'settled', currency = ?
                 WHERE id = ?"
            )->execute([
                $crate['prefix'] !== '' ? $crate['prefix'] : null,
                $crate['rate'], $crate['initial'], $crate['subsequent'],
                $c['billable_seconds'], $c['total'], $c['connect_fee'],
                $crate['version_id'], 'portal:reattribute:' . $crate['source'],
                $trunkId, $carrierId, $crate['currency'],
                (int) $cdr['id'],
            ]);
            if (bccomp($c['total'], '0', 6) > 0) {
                $ledger->post([
                    'customer_id' => $customerId,
                    'reseller_id' => $customer['reseller_id'] !== null ? (int) $customer['reseller_id'] : null,
                    'entry_type' => 'call_charge',
                    'amount' => bcmul($c['total'], '-1', 6),
                    'currency' => (string) $crate['currency'],
                    'cdr_id' => (int) $cdr['id'],
                    'source_system' => 'reattribute',
                    'external_ref' => $key,
                    'run_type' => 'customer',
                    'idempotency_key' => 'cdr:' . $key . ':customer',
                    'description' => 'Call ' . $cdr['dst_normalized'],
                ]);
            }
        }

        if ($carrierFields !== null) {
            $pdo->prepare(
                "UPDATE cdrs SET
                    carrier_trunk_id = COALESCE(?, carrier_trunk_id),
                    carrier_id = ?,
                    carrier_rate = ?, carrier_initial = ?, carrier_subsequent = ?,
                    duration_billable_carrier = ?, carrier_cost = ?, carrier_connect_fee = ?,
                    gross_margin = ?
                 WHERE id = ?"
            )->execute([
                $trunkId,
                $carrierId,
                $carrierFields['carrier_rate'],
                $carrierFields['carrier_initial'],
                $carrierFields['carrier_subsequent'],
                $carrierFields['duration_billable_carrier'],
                $carrierFields['carrier_cost'],
                $carrierFields['carrier_connect_fee'],
                $margin,
                (int) $cdr['id'],
            ]);
        } elseif ($trunkId !== null) {
            $pdo->prepare(
                'UPDATE cdrs SET carrier_trunk_id = COALESCE(?, carrier_trunk_id), carrier_id = COALESCE(?, carrier_id), gross_margin = ?
                 WHERE id = ?'
            )->execute([$trunkId, $carrierId, $margin, (int) $cdr['id']]);
        }
    });

    if ($needSell && $c !== null) {
        $charged++;
        echo "CDR #{$cdr['id']}: sell={$c['total']} buy={$carrierTotal} margin={$margin}\n";
    } elseif ($carrierFields !== null) {
        $costed++;
        echo "CDR #{$cdr['id']}: buy={$carrierTotal} margin={$margin} (sell kept {$sellTotal})\n";
    }
}

echo gmdate('c') . " attributed={$fixed} charged={$charged} costed={$costed} scanned=" . count($rows) . "\n";
