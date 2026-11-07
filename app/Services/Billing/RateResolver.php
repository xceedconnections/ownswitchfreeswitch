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
     *   prefix:string,destination:?string,source:string,rate_plan_id:int,version_id:?int,currency:string,routing_plan_id:?int,
     *   dial_prefix:string,plan_prefix_name:string,plan_prefix_id:?int,routed_destination:string}|null
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
        $dial = $this->matchPlanPrefix((int) $planId, $dst);
        if ($dial !== null && $dial['remainder'] === '') {
            return null;
        }
        $lookup = $dst;
        $prefixSql = '';
        $prefixParams = [];
        if ($dial !== null) {
            $lookup = $dial['remainder'];
            $prefixSql = ' AND plan_prefix_id = ?';
            $prefixParams = [$dial['id']];
        } elseif ($this->planPrefixColumn()) {
            $prefixSql = ' AND plan_prefix_id IS NULL';
        }
        $cands = Destination::candidates($lookup);
        if ($cands === []) {
            return null;
        }
        $in = Destination::placeholders($cands);
        $now = gmdate('Y-m-d H:i:s');

        $row = $this->longest(
            "SELECT * FROM rate_plan_tariffs WHERE rate_plan_id = ? AND status = 'active' AND prefix IN ({$in})
             {$prefixSql}
             AND (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to > ?)",
            [(int) $planId, ...$cands, ...$prefixParams, $now, $now]
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
            if ($plan['default_rate'] === null || $dial !== null) {
                return null;
            }
            return $this->annotate(
                $this->shape((string) $plan['default_rate'], $defI, $defS, (string) $plan['connect_fee'], $plan, '', null, 'plan_default', $versionId),
                null,
                $lookup
            );
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
        return $this->annotate($shaped, $dial, $lookup);
    }

    /**
     * Digits used for trunk matching after a plan prefix is removed.
     * Returns the original number when the plan has no matching dial prefix.
     */
    public function destinationAfterPlanPrefix(int $customerId, string $dst): string
    {
        if ($customerId <= 0 || $dst === '') {
            return $dst;
        }
        try {
            $c = $this->pdo->prepare('SELECT rate_plan_id FROM customers WHERE id = ?');
            $c->execute([$customerId]);
            $planId = $c->fetchColumn();
        } catch (\Throwable) {
            return $dst;
        }
        if (!$planId) {
            return $dst;
        }
        $dial = $this->matchPlanPrefix((int) $planId, $dst);
        if ($dial === null || $dial['remainder'] === '') {
            return $dst;
        }
        return $dial['remainder'];
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

    /**
     * Longest active dial prefix on this plan that the number starts with.
     *
     * @return array{id:int,prefix:string,name:string,remainder:string}|null
     */
    private function matchPlanPrefix(int $planId, string $dst): ?array
    {
        if ($planId <= 0 || $dst === '' || !$this->planPrefixColumn()) {
            return null;
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT id, prefix, name FROM plan_prefixes
                 WHERE rate_plan_id = ? AND status = 'active'
                 ORDER BY CHAR_LENGTH(prefix) DESC, id ASC"
            );
            $st->execute([$planId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return null;
        }
        foreach ($rows as $row) {
            $prefix = (string) ($row['prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($dst, $prefix)) {
                return [
                    'id' => (int) $row['id'],
                    'prefix' => $prefix,
                    'name' => (string) ($row['name'] ?? ''),
                    'remainder' => substr($dst, strlen($prefix)),
                ];
            }
        }
        return null;
    }

    private function planPrefixColumn(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $this->pdo->query('SELECT plan_prefix_id FROM rate_plan_tariffs LIMIT 0');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * @param array<string,mixed> $shaped
     * @param array{id:int,prefix:string,name:string,remainder:string}|null $dial
     * @return array<string,mixed>
     */
    private function annotate(array $shaped, ?array $dial, string $lookup): array
    {
        $shaped['dial_prefix'] = $dial['prefix'] ?? '';
        $shaped['plan_prefix_name'] = $dial['name'] ?? '';
        $shaped['plan_prefix_id'] = $dial['id'] ?? null;
        $shaped['routed_destination'] = $lookup;
        return $shaped;
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
