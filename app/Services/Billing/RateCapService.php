<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use PDO;

/**
 * Softswitch-style rate CAP: block dials when the selected trunk's buy rate
 * exceeds the SIP user's CAP and/or the trunk's own CAP.
 *
 * Example: user CAP 0.005, vendor 1213@0.004 → allow; vendor 1401@0.009 → deny.
 */
final class RateCapService
{
    public function __construct(private PDO $pdo, private RateResolver $rates)
    {
    }

    /**
     * @return array{allow:bool,reason:string,buy_rate:?string,user_cap:?string,trunk_cap:?string}
     */
    public function authorize(?string $sipUsername, string $destination, int $trunkId): array
    {
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        if ($dst === '' || $trunkId <= 0) {
            return ['allow' => true, 'reason' => 'skip', 'buy_rate' => null, 'user_cap' => null, 'trunk_cap' => null];
        }

        $buy = $this->rates->carrier($trunkId, $dst);
        $buyRate = $buy !== null ? (string) $buy['rate'] : null;

        $trunkCap = $this->trunkCap($trunkId);
        if ($trunkCap !== null && $buyRate !== null && bccomp($buyRate, $trunkCap, 8) === 1) {
            return [
                'allow' => false,
                'reason' => 'Trunk CAP exceeded (buy ' . $buyRate . ' > cap ' . $trunkCap . ')',
                'buy_rate' => $buyRate,
                'user_cap' => null,
                'trunk_cap' => $trunkCap,
            ];
        }
        // No provider rate for destination on this trunk: treat as uncapped buy (allow) unless CAP requires known rate
        if ($trunkCap !== null && $buyRate === null) {
            return [
                'allow' => false,
                'reason' => 'Trunk CAP set but no provider rate for destination',
                'buy_rate' => null,
                'user_cap' => null,
                'trunk_cap' => $trunkCap,
            ];
        }

        $userCap = $this->userCap($sipUsername);
        if ($userCap !== null && $buyRate !== null && bccomp($buyRate, $userCap, 8) === 1) {
            return [
                'allow' => false,
                'reason' => 'User CAP exceeded (buy ' . $buyRate . ' > cap ' . $userCap . ')',
                'buy_rate' => $buyRate,
                'user_cap' => $userCap,
                'trunk_cap' => $trunkCap,
            ];
        }
        if ($userCap !== null && $buyRate === null) {
            return [
                'allow' => false,
                'reason' => 'User CAP set but no provider rate for destination',
                'buy_rate' => null,
                'user_cap' => $userCap,
                'trunk_cap' => $trunkCap,
            ];
        }

        return [
            'allow' => true,
            'reason' => 'ok',
            'buy_rate' => $buyRate,
            'user_cap' => $userCap,
            'trunk_cap' => $trunkCap,
        ];
    }

    private function userCap(?string $sipUsername): ?string
    {
        $u = trim((string) $sipUsername);
        if ($u === '') {
            return null;
        }
        $st = $this->pdo->prepare("SELECT rate_cap FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1");
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
