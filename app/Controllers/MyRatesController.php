<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;

/** Customer-facing sell rates + CAP (read-only). */
final class MyRatesController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = $scope->customerId;
        if ($cid === null || !$scope->isCustomerRole()) {
            // Staff/reseller: send to full tariff admin
            if ($scope->isGlobal() || $scope->resellerId !== null) {
                return Response::redirect(url('/tariffs'));
            }
            return $this->forbidden();
        }

        $plan = null;
        $tariffs = [];
        $overrides = [];
        $sipCaps = [];
        try {
            $st = $this->db()->prepare(
                'SELECT c.id, c.name, c.rate_plan_id, c.currency, rp.name AS plan_name, rp.code AS plan_code,
                        rp.default_rate, rp.initial_increment, rp.subsequent_increment, rp.connect_fee
                 FROM customers c
                 LEFT JOIN rate_plans rp ON rp.id = c.rate_plan_id
                 WHERE c.id = ? LIMIT 1'
            );
            $st->execute([$cid]);
            $plan = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

            if ($plan && !empty($plan['rate_plan_id'])) {
                try {
                    $t = $this->db()->prepare(
                        "SELECT t.prefix, t.destination, t.rate, t.initial_increment, t.subsequent_increment, t.connect_fee, t.status,
                                pp.prefix AS dial_prefix, pp.name AS dial_name
                         FROM rate_plan_tariffs t
                         LEFT JOIN plan_prefixes pp ON pp.id = t.plan_prefix_id
                         WHERE t.rate_plan_id = ? AND t.status = 'active'
                         ORDER BY pp.prefix, LENGTH(t.prefix) DESC, t.prefix ASC
                         LIMIT 5000"
                    );
                    $t->execute([(int) $plan['rate_plan_id']]);
                    $tariffs = $t->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable) {
                    $t = $this->db()->prepare(
                        "SELECT prefix, destination, rate, initial_increment, subsequent_increment, connect_fee, status
                         FROM rate_plan_tariffs
                         WHERE rate_plan_id = ? AND status = 'active'
                         ORDER BY LENGTH(prefix) DESC, prefix ASC
                         LIMIT 5000"
                    );
                    $t->execute([(int) $plan['rate_plan_id']]);
                    $tariffs = $t->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }
            }

            $o = $this->db()->prepare(
                "SELECT prefix, rate, initial_increment, subsequent_increment, connect_fee, status
                 FROM customer_rate_overrides
                 WHERE customer_id = ? AND status = 'active'
                 ORDER BY LENGTH(prefix) DESC, prefix ASC
                 LIMIT 1000"
            );
            $o->execute([$cid]);
            $overrides = $o->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $s = $this->db()->prepare(
                "SELECT username, max_channels, max_cps, rate_cap, status
                 FROM sip_accounts
                 WHERE customer_id = ?
                 ORDER BY username ASC
                 LIMIT 500"
            );
            $s->execute([$cid]);
            $sipCaps = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] my-rates: ' . $e->getMessage());
        }

        return $this->view('rates/my_rates', [
            'title' => 'My Tariff',
            'plan' => $plan,
            'tariffs' => $tariffs,
            'overrides' => $overrides,
            'sipCaps' => $sipCaps,
        ]);
    }

    /** CSV of the customer's plan rates (opens in Excel). */
    public function export(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = $scope->customerId;
        if ($cid === null || !$scope->isCustomerRole()) {
            return $this->forbidden();
        }
        $rows = [];
        try {
            $st = $this->db()->prepare(
                "SELECT t.prefix, t.destination, t.rate, t.initial_increment, t.subsequent_increment, t.connect_fee,
                        pp.prefix AS dial_prefix, pp.name AS dial_name
                 FROM customers c
                 JOIN rate_plan_tariffs t ON t.rate_plan_id = c.rate_plan_id AND t.status = 'active'
                 LEFT JOIN plan_prefixes pp ON pp.id = t.plan_prefix_id
                 WHERE c.id = ?
                 ORDER BY pp.prefix, t.prefix"
            );
            $st->execute([$cid]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $st = $this->db()->prepare(
                "SELECT t.prefix, t.destination, t.rate, t.initial_increment, t.subsequent_increment, t.connect_fee
                 FROM customers c
                 JOIN rate_plan_tariffs t ON t.rate_plan_id = c.rate_plan_id AND t.status = 'active'
                 WHERE c.id = ?
                 ORDER BY t.prefix"
            );
            $st->execute([$cid]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return $this->view('error', ['title' => 'Export', 'message' => 'Could not build the file.', 'code' => 500], 500);
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Prefix', 'Name', 'Code', 'Destination', 'Sell price', 'Initial block', 'Billing block', 'Connection charge']);
        foreach ($rows as $row) {
            fputcsv($fh, [
                (string) ($row['dial_prefix'] ?? ''),
                (string) ($row['dial_name'] ?? ''),
                (string) ($row['prefix'] ?? ''),
                (string) ($row['destination'] ?? ''),
                (string) ($row['rate'] ?? ''),
                (string) ($row['initial_increment'] ?? ''),
                (string) ($row['subsequent_increment'] ?? ''),
                (string) ($row['connect_fee'] ?? ''),
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plan-rates.csv"',
        ]);
    }
}
