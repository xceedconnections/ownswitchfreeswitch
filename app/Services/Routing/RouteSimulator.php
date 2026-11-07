<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Support\Destination;
use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Dry-run of what the router would do for (customer, destination): blocks, customer rate,
 * matching rules (longest prefix + trunk-group “*” members), ordered by strategy
 * (priority | lcr | asr | random).
 * Read-only; never places calls.
 */
final class RouteSimulator
{
    public function __construct(private PDO $pdo, private RateResolver $resolver, private RatingService $rating)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function simulate(TenantScope $scope, int $customerId, string $rawDst, int $sampleSeconds = 60): array
    {
        $dst = Destination::normalize($rawDst);
        $out = ['destination' => $dst, 'blocked' => false, 'notes' => [], 'customer_rate' => null, 'routes' => [], 'strategy' => null];
        if ($dst === '') {
            $out['notes'][] = 'Destination has no digits.';
            return $out;
        }
        $c = $this->pdo->prepare('SELECT id, name, reseller_id, status, routing_plan_id FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        $cust = $c->fetch(PDO::FETCH_ASSOC);
        if (!$cust || !$scope->canAccessCustomer((int) $cust['id'], $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null)) {
            $out['notes'][] = 'Customer not found.';
            return $out;
        }
        $out['customer'] = $cust['name'];
        if ($cust['status'] !== 'active') {
            $out['blocked'] = true;
            $out['notes'][] = "Customer status is '{$cust['status']}': calls rejected.";
            return $out;
        }

        $routeDst = $this->resolver->destinationAfterPlanPrefix($customerId, $dst);
        if ($routeDst !== $dst) {
            $out['routed_destination'] = $routeDst;
            $out['notes'][] = 'Plan prefix removed. Rate and trunk match on ' . $routeDst . '.';
        }
        $cands = Destination::candidates($routeDst);
        $in = Destination::placeholders($cands);
        $pol = $this->pdo->prepare("SELECT prefix, action, note FROM destination_policies WHERE (customer_id IS NULL OR customer_id = ?) AND prefix IN ({$in}) ORDER BY LENGTH(prefix) DESC LIMIT 1");
        $pol->execute([$customerId, ...$cands]);
        if (($p = $pol->fetch(PDO::FETCH_ASSOC)) && $p['action'] === 'block') {
            $out['blocked'] = true;
            $out['notes'][] = "Blocked by destination policy (prefix {$p['prefix']})" . ($p['note'] ? ': ' . $p['note'] : '');
            return $out;
        }

        $rate = $this->resolver->customer($customerId, $dst);
        if ($rate === null) {
            $out['notes'][] = 'No customer rate matches this destination: call would be rejected as unrated.';
        } else {
            $r = $this->rating->rate($rate['rate'], $rate['initial'], $rate['subsequent'], $sampleSeconds, $rate['connect_fee']);
            $out['customer_rate'] = $rate + ['sample_seconds' => $sampleSeconds, 'sample_total' => $r['total'], 'sample_billable' => $r['billable_seconds']];
        }

        // SIP user trunk group overrides Tariff; account-level TG is no longer used.
        $tgId = null;
        $sipTg = null;
        try {
            $sipSt = $this->pdo->prepare(
                "SELECT routing_plan_id FROM sip_accounts
                 WHERE customer_id = ? AND status = 'active' AND routing_plan_id IS NOT NULL
                 ORDER BY id ASC LIMIT 1"
            );
            $sipSt->execute([$customerId]);
            $sipTg = $sipSt->fetchColumn();
        } catch (\Throwable) {
            $sipTg = null;
        }
        if ($sipTg !== false && $sipTg !== null && (int) $sipTg > 0) {
            $tgId = (int) $sipTg;
            $out['notes'][] = 'Using Trunk Group from SIP User (overrides Tariff).';
        } elseif ($rate !== null && !empty($rate['routing_plan_id'])) {
            $tgId = (int) $rate['routing_plan_id'];
            $out['notes'][] = 'Using Trunk Group from matched Tariff.';
        }
        if (!$tgId) {
            $out['notes'][] = 'No Trunk Group on SIP user or tariff — cannot route.';
            return $out;
        }
        $plan = $this->pdo->prepare("SELECT id, name, strategy FROM routing_plans WHERE id = ? AND status = 'active'");
        $plan->execute([$tgId]);
        $pl = $plan->fetch(PDO::FETCH_ASSOC);
        if (!$pl) {
            $out['notes'][] = 'Trunk Group is inactive or missing.';
            return $out;
        }
        $out['strategy'] = $pl['strategy'];
        $out['plan'] = $pl['name'];

        $st = $this->pdo->prepare(
            "SELECT r.id AS rule_id, r.prefix, r.priority, t.id AS trunk_id, t.name AS trunk_name, t.host, t.port, t.max_channels,
                    t.add_prefix, t.remove_prefix, t.from_user, t.from_domain, t.qualify, t.dnc_group_id, ca.name AS carrier_name
             FROM routing_rules r JOIN carrier_trunks t ON t.id = r.carrier_trunk_id AND t.status = 'active'
             JOIN carriers ca ON ca.id = t.carrier_id AND ca.status = 'active'
             WHERE r.routing_plan_id = ? AND r.status = 'active' AND (r.prefix IN ({$in}) OR r.prefix = '*')"
        );
        $st->execute([(int) $pl['id'], ...$cands]);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rules) {
            $out['notes'][] = 'No active trunks in this Trunk Group for the destination.';
            return $out;
        }
        // Prefer longest digit prefix; fall back to trunk-group catch-all (*)
        $digit = array_values(array_filter($rules, static fn ($r) => (string) $r['prefix'] !== '*'));
        if ($digit !== []) {
            $longest = max(array_map(static fn ($r) => strlen((string) $r['prefix']), $digit));
            $rules = array_values(array_filter($digit, static fn ($r) => strlen((string) $r['prefix']) === $longest));
        } else {
            $rules = array_values(array_filter($rules, static fn ($r) => (string) $r['prefix'] === '*'));
            $out['notes'][] = 'Using Trunk Group member trunks (catch-all).';
        }
        foreach ($rules as &$rr) {
            $dial = $routeDst;
            $rawRem = (string) ($rr['remove_prefix'] ?? '');
            $remList = [];
            foreach (preg_split('/[\s,;|]+/', $rawRem) ?: [] as $p) {
                $p = preg_replace('/\D+/', '', $p) ?? '';
                if ($p !== '') {
                    $remList[] = $p;
                }
            }
            usort($remList, static fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($remList as $rem) {
                if ($rem !== '' && str_starts_with($dial, $rem)) {
                    $dial = substr($dial, strlen($rem));
                    break;
                }
            }
            $add = (string) ($rr['add_prefix'] ?? '');
            if ($add !== '') {
                $dial = $add . $dial;
            }
            $rr['dialled_out'] = $dial;
            $rr['from_rewrite'] = trim((string) ($rr['from_user'] ?? '')) !== ''
                ? (string) $rr['from_user'] . '@' . ((string) ($rr['from_domain'] ?? '') !== '' ? $rr['from_domain'] : 'trunk-host')
                : null;
            $dncGid = (int) ($rr['dnc_group_id'] ?? 0);
            $rr['dnc_blocked'] = false;
            if ($dncGid > 0) {
                try {
                    $rr['dnc_blocked'] = (new \Ownswitch\Services\Dnc\DncService($this->pdo))->isBlocked($dncGid, $dst);
                    if ($rr['dnc_blocked']) {
                        $rr['dnc_note'] = 'DNC group #' . $dncGid . ' blocks this destination';
                    }
                } catch (\Throwable) {
                    $rr['dnc_note'] = 'DNC check unavailable';
                }
            }
        }
        unset($rr);

        foreach ($rules as &$r) {
            $k = $this->resolver->carrier((int) $r['trunk_id'], $dst);
            $r['carrier_rate'] = $k['rate'] ?? null;
            $r['carrier_increment'] = $k ? $k['initial'] . '/' . $k['subsequent'] : null;
            $r['carrier_sample_cost'] = $k ? $this->rating->rate($k['rate'], $k['initial'], $k['subsequent'], $sampleSeconds, $k['connect_fee'])['total'] : null;
            $r['sample_margin'] = ($k && $rate && isset($out['customer_rate']['sample_total']))
                ? $this->rating->margin($out['customer_rate']['sample_total'], $r['carrier_sample_cost']) : null;
        }
        unset($r);
        $strategy = (string) $pl['strategy'];
        if ($strategy === 'asr') {
            $stats = (new TrunkHourStatsService($this->pdo))->lastHour(array_map(static fn ($r) => (int) $r['trunk_id'], $rules));
            foreach ($rules as &$r) {
                $sid = (string) (int) $r['trunk_id'];
                $r['hour_asr'] = $stats[$sid]['asr'] ?? -1.0;
                $r['hour_attempts'] = $stats[$sid]['attempts'] ?? 0;
            }
            unset($r);
        }
        $out['routes'] = self::order($rules, $strategy);
        return $out;
    }

    /**
     * Order candidates: priority = list order; lcr = cheapest first; asr = best connectivity; random = shuffle.
     *
     * @param list<array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    public static function order(array $rules, string $strategy): array
    {
        if ($strategy === 'random') {
            shuffle($rules);
        } else {
            usort($rules, static function (array $a, array $b) use ($strategy): int {
                if ($strategy === 'lcr') {
                    $ca = $a['carrier_sample_cost'] ?? null;
                    $cb = $b['carrier_sample_cost'] ?? null;
                    if ($ca === null xor $cb === null) {
                        return $ca === null ? 1 : -1;
                    }
                    if ($ca !== null && ($c = bccomp((string) $ca, (string) $cb, 6)) !== 0) {
                        return $c;
                    }
                }
                if ($strategy === 'asr') {
                    $aa = (float) ($a['hour_asr'] ?? -1);
                    $ba = (float) ($b['hour_asr'] ?? -1);
                    if ($aa !== $ba) {
                        return $ba <=> $aa;
                    }
                    $at = (int) ($a['hour_attempts'] ?? 0);
                    $bt = (int) ($b['hour_attempts'] ?? 0);
                    if ($at !== $bt) {
                        return $bt <=> $at;
                    }
                }
                return ((int) $a['priority'] <=> (int) $b['priority']) ?: ((int) $a['rule_id'] <=> (int) $b['rule_id']);
            });
        }
        foreach ($rules as $i => &$r) {
            $r['attempt_order'] = $i + 1;
        }
        return $rules;
    }
}
