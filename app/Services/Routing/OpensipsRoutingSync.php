<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Settings\SettingsService;
use PDO;

/** Write Sofia gateways. Routing rules stay in the Ownswitch database for the dialplan. */
final class OpensipsRoutingSync
{
    public function __construct(private PDO $own, private FreeswitchProvisioner $prov)
    {
    }

    /** @return array{trunks:int,rules:int,warnings:list<string>} */
    public function syncAll(): array
    {
        $warnings = [];
        if (!$this->prov->enabled()) {
            return ['trunks' => 0, 'rules' => 0, 'warnings' => ['FreeSWITCH ESL is not configured']];
        }

        $trunks = $this->own->query('SELECT * FROM carrier_trunks')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($trunks as $t) {
            try {
                $this->prov->syncTrunk($t);
                $n++;
            } catch (\Throwable $e) {
                $warnings[] = 'Trunk #' . $t['id'] . ' (' . $t['name'] . '): ' . $e->getMessage();
            }
        }

        $rules = $this->own->query(
            "SELECT r.prefix, r.carrier_trunk_id, r.priority, r.status, r.routing_plan_id,
                    COALESCE(p.strategy, 'priority') AS strategy
             FROM routing_rules r
             LEFT JOIN routing_plans p ON p.id = r.routing_plan_id
             WHERE r.status = 'active'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Order each plan's trunks by strategy before sync (LCR / ASR / priority / random marker)
        $rules = $this->orderRulesForSync($rules);

        try {
            $this->prov->syncRoutingRules($rules);
        } catch (\Throwable $e) {
            $warnings[] = 'Route sync: ' . $e->getMessage();
        }

        $this->prov->reloadRouting();
        $this->prov->reloadAddresses();
        $this->prov->reloadRegistrant();

        return ['trunks' => $n, 'rules' => count($rules), 'warnings' => $warnings];
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    private function orderRulesForSync(array $rules): array
    {
        $byPlan = [];
        foreach ($rules as $r) {
            $pid = (int) ($r['routing_plan_id'] ?? 0);
            $byPlan[$pid][] = $r;
        }
        $out = [];
        $resolver = new RateResolver($this->own, new SettingsService($this->own));
        $rating = new RatingService();
        foreach ($byPlan as $pid => $list) {
            $strategy = (string) ($list[0]['strategy'] ?? 'priority');
            if ($strategy === 'lcr') {
                foreach ($list as &$rr) {
                    $k = $resolver->carrier((int) $rr['carrier_trunk_id'], '12125550100')
                        ?? $resolver->carrier((int) $rr['carrier_trunk_id'], '1');
                    $rr['_cost'] = $k
                        ? (string) $rating->rate($k['rate'], $k['initial'], $k['subsequent'], 60, $k['connect_fee'])['total']
                        : '999999';
                }
                unset($rr);
                usort($list, static function (array $a, array $b): int {
                    $c = bccomp((string) ($a['_cost'] ?? '999'), (string) ($b['_cost'] ?? '999'), 6);
                    return $c !== 0 ? $c : ((int) $a['priority'] <=> (int) $b['priority']);
                });
                $prio = 10;
                foreach ($list as &$rr) {
                    $rr['priority'] = $prio;
                    $prio += 10;
                    unset($rr['_cost']);
                }
                unset($rr);
            } elseif ($strategy === 'asr') {
                $ids = array_map(static fn ($x) => (int) $x['carrier_trunk_id'], $list);
                $stats = (new TrunkHourStatsService($this->own))->lastHour($ids);
                usort($list, static function (array $a, array $b) use ($stats): int {
                    $sa = $stats[(string) (int) $a['carrier_trunk_id']]['asr'] ?? -1.0;
                    $sb = $stats[(string) (int) $b['carrier_trunk_id']]['asr'] ?? -1.0;
                    if ($sa !== $sb) {
                        return $sb <=> $sa;
                    }
                    return (int) $a['priority'] <=> (int) $b['priority'];
                });
                $prio = 10;
                foreach ($list as &$rr) {
                    $rr['priority'] = $prio;
                    $prio += 10;
                }
                unset($rr);
            } elseif ($strategy === 'random') {
                foreach ($list as &$rr) {
                    $rr['sort_alg'] = 'R';
                }
                unset($rr);
            }
            foreach ($list as $rr) {
                $out[] = $rr;
            }
        }
        return $out;
    }
}
