<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use Ownswitch\Services\Settings\SettingsService;
use Ownswitch\Support\Destination;
use PDO;

/**
 * Resolves rate + increments with the precedence in BILLING_RULES section 3
 * (route-specific overrides are not implemented yet).
 *
 * Customer: tariff row (longest prefix) > customer override > rate-plan default > global default I/S.
 * Carrier:  carrier tariff (longest prefix) > trunk default.
 */
final class RateResolver
{
    public function __construct(private PDO $pdo, private SettingsService $settings)
    {
    }

    /**
     * @return array{rate:string,initial:int,subsequent:int,connect_fee:string,min_billable:int,charge_connect_on_zero:bool,
     *   prefix:string,destination:?string,source:string,rate_plan_id:int,version_id:?int,currency:string,routing_plan_id:?int}|null
     */
    public function customer(int $customerId, string $dst): ?array
    {
        $c = $this->pdo->prepare('SELECT rate_plan_id FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        $planId = $c->fetchColumn();
        if (!$planId) {
            return null;
        }
        $p = $this->pdo->prepare("SELECT * FROM rate_plans WHERE id = ? AND status = 'active'");
        $p->execute([$planId]);
        $plan = $p->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return null;
        }
        $defI = $this->positive($plan['initial_increment'], $this->settings->int('default_customer_initial', 6));
        $defS = $this->positive($plan['subsequent_increment'], $this->settings->int('default_customer_subsequent', 6));
        $cands = Destination::candidates($dst);
        if ($cands === []) {
            return null;
        }
        $in = Destination::placeholders($cands);
        $now = gmdate('Y-m-d H:i:s');

        $row = $this->longest(
            "SELECT * FROM rate_plan_tariffs WHERE rate_plan_id = ? AND status = 'active' AND prefix IN ({$in})
             AND (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to > ?)",
            [(int) $planId, ...$cands, $now, $now]
        );
        $source = 'tariff';
        if ($row === null) {
            $row = $this->longest(
                "SELECT * FROM customer_rate_overrides WHERE customer_id = ? AND status = 'active' AND prefix IN ({$in})",
                [$customerId, ...$cands]
            );
            $source = 'override';
        }
        $versionId = $this->versionId((int) $planId);
        if ($row === null) {
            if ($plan['default_rate'] === null) {
                return null;
            }
            return $this->shape((string) $plan['default_rate'], $defI, $defS, (string) $plan['connect_fee'], $plan, '', null, 'plan_default', $versionId);
        }
        $shaped = $this->shape(
            (string) $row['rate'],
            $this->positive($row['initial_increment'], $defI),
            $this->positive($row['subsequent_increment'], $defS),
            $row['connect_fee'] !== null ? (string) $row['connect_fee'] : (string) $plan['connect_fee'],
            $plan, (string) $row['prefix'], $row['destination'] ?? null, $source, $versionId
        );
        if ($source === 'tariff' && !empty($row['routing_plan_id'])) {
            $shaped['routing_plan_id'] = (int) $row['routing_plan_id'];
        }
        return $shaped;
    }

    /**
     * @return array{rate:string,initial:int,subsequent:int,connect_fee:string,prefix:string,source:string,carrier_id:int}|null
     */
    public function carrier(int $trunkId, string $dst): ?array
    {
        $t = $this->pdo->prepare('SELECT * FROM carrier_trunks WHERE id = ?');
        $t->execute([$trunkId]);
        $trunk = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trunk) {
            return null;
        }
        $defI = $this->positive($trunk['initial_increment'], $this->settings->int('default_carrier_initial', 6));
        $defS = $this->positive($trunk['subsequent_increment'], $this->settings->int('default_carrier_subsequent', 6));
        $cands = Destination::candidates($dst);
        $in = Destination::placeholders($cands);
        $now = gmdate('Y-m-d H:i:s');
        $row = $cands === [] ? null : $this->longest(
            "SELECT * FROM carrier_tariffs WHERE carrier_id = ? AND status = 'active' AND prefix IN ({$in})
             AND (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to > ?)",
            [(int) $trunk['carrier_id'], ...$cands, $now, $now]
        );
        if ($row !== null) {
            return [
                'rate' => (string) $row['rate'],
                'initial' => $this->positive($row['initial_increment'], $defI),
                'subsequent' => $this->positive($row['subsequent_increment'], $defS),
                'connect_fee' => $row['connect_fee'] !== null ? (string) $row['connect_fee'] : (string) $trunk['connect_fee'],
                'prefix' => (string) $row['prefix'], 'source' => 'carrier_tariff', 'carrier_id' => (int) $trunk['carrier_id'],
            ];
        }
        if ($trunk['default_rate'] !== null) {
            return [
                'rate' => (string) $trunk['default_rate'], 'initial' => $defI, 'subsequent' => $defS,
                'connect_fee' => (string) $trunk['connect_fee'], 'prefix' => '', 'source' => 'trunk_default', 'carrier_id' => (int) $trunk['carrier_id'],
            ];
        }
        return null;
    }

    /** Pick the longest-prefix row among query results. @param list<mixed> $params */
    private function longest(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $best = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($best === null || strlen((string) $r['prefix']) > strlen((string) $best['prefix'])) {
                $best = $r;
            }
        }
        return $best;
    }

    private function positive(mixed $v, int $fallback): int
    {
        return $v !== null && (int) $v > 0 ? (int) $v : max(1, $fallback);
    }

    private function versionId(int $planId): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM rate_plan_versions WHERE rate_plan_id = ? ORDER BY version_no DESC LIMIT 1');
        $st->execute([$planId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** @param array<string,mixed> $plan */
    private function shape(string $rate, int $i, int $s, string $fee, array $plan, string $prefix, ?string $dest, string $source, ?int $versionId): array
    {
        return [
            'rate' => $rate, 'initial' => $i, 'subsequent' => $s, 'connect_fee' => $fee,
            'min_billable' => (int) $plan['min_billable_seconds'],
            'charge_connect_on_zero' => (bool) $plan['charge_connect_on_zero'] || $this->settings->bool('charge_connect_on_zero'),
            'prefix' => $prefix, 'destination' => $dest, 'source' => $source,
            'rate_plan_id' => (int) $plan['id'], 'version_id' => $versionId, 'currency' => (string) $plan['currency'],
            'routing_plan_id' => null,
        ];
    }
}
