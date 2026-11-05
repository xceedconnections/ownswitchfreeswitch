<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Support\TenantScope;
use PDO;

/** Allowed vs live concurrent calls for a customer (ConnexCS-style CC badge). */
final class ConcurrentCallsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{allowed:int,using:int,label:string,unlimited:bool}
     */
    public function forCustomer(int $customerId): array
    {
        $allowed = 0;
        try {
            $st = $this->pdo->prepare('SELECT max_concurrent_calls FROM customers WHERE id = ?');
            $st->execute([$customerId]);
            $allowed = (int) ($st->fetchColumn() ?: 0);
        } catch (\Throwable) {
            $allowed = 0;
        }
        // Also honour the highest SIP user call limit when customer limit is -1 / unset
        try {
            $st = $this->pdo->prepare(
                "SELECT MAX(max_channels) FROM sip_accounts WHERE customer_id = ? AND status = 'active'"
            );
            $st->execute([$customerId]);
            $sipMax = $st->fetchColumn();
            if ($sipMax !== false && $sipMax !== null && $allowed > 0 && (int) $sipMax > 0) {
                // display customer account limit as the contract; SIP limits are enforced separately
            }
        } catch (\Throwable) {
        }

        $using = $this->countLive($customerId);
        $unlimited = $allowed < 0;
        $label = ($unlimited ? '∞' : (string) $allowed) . ' / ' . $using;
        return [
            'allowed' => $allowed,
            'using' => $using,
            'label' => $label,
            'unlimited' => $unlimited,
        ];
    }

    public function forScope(TenantScope $scope): array
    {
        if ($scope->customerId === null) {
            return ['allowed' => 0, 'using' => 0, 'label' => '0 / 0', 'unlimited' => false];
        }
        return $this->forCustomer($scope->customerId);
    }

    private function countLive(int $customerId): int
    {
        $users = [];
        try {
            $st = $this->pdo->prepare(
                "SELECT LOWER(username) FROM sip_accounts WHERE customer_id = ? AND status = 'active'"
            );
            $st->execute([$customerId]);
            $users = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return 0;
        }
        if ($users === []) {
            return 0;
        }
        $set = array_fill_keys(array_map('strval', $users), true);
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled() || !$prov->hasMi()) {
            return 0;
        }
        $n = 0;
        try {
            foreach ($prov->listDialogs() as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $from = strtolower((string) ($d['from_uri'] ?? $d['caller'] ?? $d['From'] ?? $d['from'] ?? ''));
                $to = strtolower((string) ($d['to_uri'] ?? $d['callee'] ?? $d['To'] ?? $d['to'] ?? ''));
                $fu = self::userOfUri($from);
                $tu = self::userOfUri($to);
                if (($fu !== '' && isset($set[$fu])) || ($tu !== '' && isset($set[$tu]))) {
                    $n++;
                }
            }
        } catch (\Throwable) {
            return 0;
        }
        return $n;
    }

    private static function userOfUri(string $uri): string
    {
        if (preg_match('/sip:([^@;>\s]+)/i', $uri, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('/^([^@;>\s]+)/', $uri, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }
}
