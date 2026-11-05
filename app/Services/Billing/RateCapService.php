<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use PDO;

/**
 * Two independent CAPs:
 * - SIP user CAP → compared to the customer's tariff (sell) rate for the dialled prefix.
 * - Trunk CAP → compared to the provider (buy) rate for that trunk/carrier.
 *
 * Example: user CAP 0.006 blocks tariff 1204@0.007 and 1456@0.01, allows 1213@0.0055.
 * Trunk CAP 0.008 allows buy 1213@0.005, skips buy 1456@0.009 on that trunk.
 */
final class RateCapService
{
    public function __construct(private PDO $pdo, private RateResolver $rates)
    {
    }

    /**
     * @return array{
     *   allow:bool,reason:string,
     *   sell_rate:?string,buy_rate:?string,
     *   user_cap:?string,trunk_cap:?string
     * }
     */
    public function authorize(?string $sipUsername, string $destination, int $trunkId): array
    {
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        if ($dst === '' || $trunkId <= 0) {
            return [
                'allow' => true,
                'reason' => 'skip',
                'sell_rate' => null,
                'buy_rate' => null,
                'user_cap' => null,
                'trunk_cap' => null,
            ];
        }

        $userCap = $this->userCap($sipUsername);
        $sellRate = null;
        if ($userCap !== null) {
            $customerId = $this->customerIdForSip($sipUsername);
            if ($customerId === null) {
                return [
                    'allow' => false,
                    'reason' => 'User CAP set but SIP account has no customer',
                    'sell_rate' => null,
                    'buy_rate' => null,
                    'user_cap' => $userCap,
                    'trunk_cap' => null,
                ];
            }
            $sell = $this->rates->customer($customerId, $dst);
            $sellRate = $sell !== null ? (string) $sell['rate'] : null;
            if ($sellRate === null) {
                return [
                    'allow' => false,
                    'reason' => 'User CAP set but no customer tariff for destination',
                    'sell_rate' => null,
                    'buy_rate' => null,
                    'user_cap' => $userCap,
                    'trunk_cap' => null,
                ];
            }
            if (bccomp($sellRate, $userCap, 8) === 1) {
                return [
                    'allow' => false,
                    'reason' => 'User CAP exceeded (tariff ' . $sellRate . ' > cap ' . $userCap . ')',
                    'sell_rate' => $sellRate,
                    'buy_rate' => null,
                    'user_cap' => $userCap,
                    'trunk_cap' => null,
                ];
            }
        }

        $buy = $this->rates->carrier($trunkId, $dst);
        $buyRate = $buy !== null ? (string) $buy['rate'] : null;
        $trunkCap = $this->trunkCap($trunkId);

        if ($trunkCap !== null && $buyRate !== null && bccomp($buyRate, $trunkCap, 8) === 1) {
            return [
                'allow' => false,
                'reason' => 'Trunk CAP exceeded (buy ' . $buyRate . ' > cap ' . $trunkCap . ')',
                'sell_rate' => $sellRate,
                'buy_rate' => $buyRate,
                'user_cap' => $userCap,
                'trunk_cap' => $trunkCap,
            ];
        }
        if ($trunkCap !== null && $buyRate === null) {
            return [
                'allow' => false,
                'reason' => 'Trunk CAP set but no provider rate for destination',
                'sell_rate' => $sellRate,
                'buy_rate' => null,
                'user_cap' => $userCap,
                'trunk_cap' => $trunkCap,
            ];
        }

        return [
            'allow' => true,
            'reason' => 'ok',
            'sell_rate' => $sellRate,
            'buy_rate' => $buyRate,
            'user_cap' => $userCap,
            'trunk_cap' => $trunkCap,
        ];
    }

    private function customerIdForSip(?string $sipUsername): ?int
    {
        $u = trim((string) $sipUsername);
        if ($u === '') {
            return null;
        }
        $st = $this->pdo->prepare(
            "SELECT customer_id FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$u]);
        $id = $st->fetchColumn();
        return ($id !== false && $id !== null) ? (int) $id : null;
    }

    private function userCap(?string $sipUsername): ?string
    {
        $u = trim((string) $sipUsername);
        if ($u === '') {
            return null;
        }
        $st = $this->pdo->prepare(
            "SELECT rate_cap FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$u]);
        return $this->capOrNull($st->fetchColumn());
    }

    private function trunkCap(int $trunkId): ?string
    {
        $st = $this->pdo->prepare('SELECT rate_cap FROM carrier_trunks WHERE id = ? LIMIT 1');
        $st->execute([$trunkId]);
        return $this->capOrNull($st->fetchColumn());
    }

    private function capOrNull(mixed $v): ?string
    {
        if ($v === false || $v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s) || (float) $s <= 0) {
            return null;
        }
        return number_format((float) $s, 8, '.', '');
    }
}
