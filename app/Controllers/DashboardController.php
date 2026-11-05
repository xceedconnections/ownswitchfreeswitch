<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Reports\ConcurrentCallsService;
use Ownswitch\Services\Reports\DashboardMetricsService;

/** Real, tenant-scoped dashboard KPIs (Xceed-style). */
final class DashboardController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $pdo = $this->db();

        try {
            $metrics = (new DashboardMetricsService($pdo))->build($scope);
        } catch (\Throwable $e) {
            error_log('[ownswitch] dashboard build: ' . $e->getMessage());
            $metrics = DashboardMetricsService::empty();
        }

        $hasDemo = false;
        try {
            $hasDemo = (int) $pdo->query(
                'SELECT (SELECT COUNT(*) FROM customers WHERE is_demo = 1) + (SELECT COUNT(*) FROM cdrs WHERE is_demo = 1)'
            )->fetchColumn() > 0;
        } catch (\Throwable) {
        }

        $data = [
            'title' => 'Dashboard',
            'metrics' => $metrics,
            'showDemo' => $hasDemo && $scope->isGlobal(),
            'isGlobal' => $scope->isGlobal(),
            'isStaff' => $scope->isStaff(),
            'counts' => [
                'customers' => 0,
                'sip' => 0,
                'resellers' => null,
                'carriers' => null,
                'openFraud' => 0,
            ],
        ];

        if (!$scope->isCustomerRole()) {
            $count = static function (\PDO $pdo, string $sql, array $params = []): int {
                try {
                    $s = $pdo->prepare($sql);
                    $s->execute($params);
                    return (int) $s->fetchColumn();
                } catch (\Throwable) {
                    return 0;
                }
            };
            [$cuw, $cup] = $scope->where('customer');
            [$sw, $sp] = $scope->where('owned');
            $data['counts'] = [
                'customers' => $count($pdo, "SELECT COUNT(*) FROM customers WHERE ({$cuw})", $cup),
                'sip' => $count($pdo, "SELECT COUNT(*) FROM sip_accounts WHERE status = 'active' AND ({$sw})", $sp),
                'resellers' => $scope->isStaff() ? $count($pdo, 'SELECT COUNT(*) FROM resellers') : null,
                'carriers' => $scope->isGlobal() ? $count($pdo, "SELECT COUNT(*) FROM carriers WHERE status = 'active'") : null,
                'openFraud' => $count($pdo, "SELECT COUNT(*) FROM fraud_events WHERE status = 'open' AND ({$sw})", $sp),
            ];
            return $this->view('dashboard/admin', $data);
        }

        if ($scope->customerId !== null) {
            try {
                $c = $pdo->prepare('SELECT name, billing_mode, credit_limit, currency, status FROM customers WHERE id = ?');
                $c->execute([$scope->customerId]);
                $cust = $c->fetch() ?: null;
            } catch (\Throwable) {
                $cust = null;
            }
            $balance = '0';
            $available = '0';
            try {
                $ledger = new LedgerService($pdo);
                $balance = $ledger->balance($scope->customerId);
                $available = $cust
                    ? $ledger->availableCredit($scope->customerId, (string) $cust['billing_mode'], (string) $cust['credit_limit'])
                    : '0';
            } catch (\Throwable) {
            }
            $daySpend = (string) ($metrics['today']['spend'] ?? '0');
            $runwayDays = null;
            if (bccomp($daySpend, '0', 6) > 0 && bccomp($available, '0', 6) > 0) {
                $runwayDays = (float) bcdiv($available, $daySpend, 2);
            }
            $sipCount = 0;
            $ipCount = 0;
            try {
                $sip = $pdo->prepare("SELECT COUNT(*) FROM sip_accounts WHERE customer_id = ? AND status = 'active'");
                $sip->execute([$scope->customerId]);
                $sipCount = (int) $sip->fetchColumn();
            } catch (\Throwable) {
            }
            try {
                $ip = $pdo->prepare("SELECT COUNT(*) FROM ip_endpoints WHERE customer_id = ? AND status = 'active'");
                $ip->execute([$scope->customerId]);
                $ipCount = (int) $ip->fetchColumn();
            } catch (\Throwable) {
            }
            $cc = ['label' => '— / —', 'allowed' => 0, 'using' => 0, 'unlimited' => false];
            try {
                $cc = (new ConcurrentCallsService($pdo))->forCustomer((int) $scope->customerId);
            } catch (\Throwable) {
            }
            $cpsAllowed = 0;
            $cpsUnlimited = false;
            $sipLimits = [];
            try {
                $st = $pdo->prepare(
                    "SELECT username, max_channels, max_cps, rate_cap
                     FROM sip_accounts WHERE customer_id = ? AND status = 'active' ORDER BY username LIMIT 50"
                );
                $st->execute([(int) $scope->customerId]);
                $sipLimits = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($sipLimits as $s) {
                    $cps = (int) ($s['max_cps'] ?? 0);
                    if ($cps < 0) {
                        $cpsUnlimited = true;
                    } elseif ($cps > $cpsAllowed) {
                        $cpsAllowed = $cps;
                    }
                }
            } catch (\Throwable) {
            }
            $billingMode = strtolower(trim((string) ($cust['billing_mode'] ?? 'prepaid')));
            $status = strtolower(trim((string) ($cust['status'] ?? 'active')));
            $currency = strtoupper(trim((string) ($cust['currency'] ?? 'USD')));
            // Normalize money strings for reliable bccomp (null/empty → 0)
            $balance = bcadd((string) ($balance !== '' ? $balance : '0'), '0', 6);
            $available = bcadd((string) ($available !== '' ? $available : '0'), '0', 6);
            // Prepaid: pause when available OR ledger balance is 0 or negative (matches OpenSIPS allow_calls)
            $fundsEmpty = bccomp($available, '0', 6) <= 0 || bccomp($balance, '0', 6) <= 0;
            $callsPaused = $status !== 'active' || ($billingMode !== 'postpaid' && $fundsEmpty);
            // Orange warning: still callable but under $10 (or 10 units of account currency)
            $lowBalanceWarn = !$callsPaused && $billingMode !== 'postpaid'
                && bccomp($available, '0', 6) > 0 && bccomp($available, '10', 6) < 0;
            $lowRunway = !$callsPaused && !$lowBalanceWarn && $runwayDays !== null && $runwayDays < 2;

            $attention = [];
            if ($callsPaused) {
                $attention[] = [
                    'level' => 'critical',
                    'title' => 'Calling is paused',
                    'body' => $status !== 'active'
                        ? 'Your account is not active. New calls may be refused until support reactivates it.'
                        : ('Your available balance is below the level needed to place calls. Your balance is '
                            . fmt_money($available, 2) . ($currency !== '' ? ' ' . $currency : '') . '.'),
                ];
            } elseif ($lowBalanceWarn) {
                $attention[] = [
                    'level' => 'warn',
                    'title' => 'Low balance',
                    'body' => 'Your available balance is '
                        . fmt_money($available, 2) . ($currency !== '' ? ' ' . $currency : '')
                        . '. Add funds before it reaches zero so calling is not paused.',
                ];
            } elseif ($lowRunway) {
                $attention[] = [
                    'level' => 'warn',
                    'title' => 'Low runway',
                    'body' => 'At today’s spend pace you have about '
                        . number_format((float) $runwayDays, 1) . ' days of calling left. Add funds to avoid a pause.',
                ];
            }

            $data['title'] = 'Mission Control';
            $data['customer'] = $cust;
            $data['balance'] = $balance;
            $data['available'] = $available;
            $data['runwayDays'] = $runwayDays;
            $data['sipCount'] = $sipCount;
            $data['ipCount'] = $ipCount;
            $data['ccLabel'] = $cc['label'] ?? '— / —';
            $data['ccAllowed'] = $cc['allowed'] ?? 0;
            $data['ccUsing'] = $cc['using'] ?? 0;
            $data['ccUnlimited'] = !empty($cc['unlimited']);
            $data['cpsAllowed'] = $cpsAllowed;
            $data['cpsUnlimited'] = $cpsUnlimited;
            $data['sipLimits'] = $sipLimits;
            $data['callsPaused'] = $callsPaused;
            $data['lowBalanceWarn'] = $lowBalanceWarn;
            $data['lowRunway'] = $lowRunway;
            $data['attention'] = $attention;
            return $this->view('dashboard/customer', $data);
        }

        return $this->view('dashboard/admin', $data);
    }
}
