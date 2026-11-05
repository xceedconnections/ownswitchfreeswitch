<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Routing\OpensipsRoutingSync;
use Ownswitch\Services\Routing\RouteSimulator;
use Ownswitch\Services\Routing\TrunkHourStatsService;
use Ownswitch\Services\Settings\SettingsService;

/**
 * Trunk Groups (kind=plans) at /trunk-groups — multi-trunk + Priority/LCR/ASR/Random.
 * Routes (kind=rules) at /routing — optional prefix→trunk overrides.
 */
final class RoutingController extends CrudController
{
    /** @var list<array{id:int,status:string}> */
    private array $pendingMembers = [];

    private int $statsWindow = 60;

    public function __construct(private string $kind = 'rules')
    {
        $plans = $kind === 'plans';
        $this->base = $plans ? '/trunk-groups' : '/routing';
        $this->singular = $plans ? 'Trunk group' : 'Route';
        $this->plural = $plans ? 'Trunk Groups' : 'Routes';
        $this->emptyHint = $plans
            ? 'No trunk groups yet. Pick a type, drag trunks into your waterfall, then select the group on a Tariff.'
            : 'Optional prefix overrides. Prefer adding trunks on the Trunk Group for Magnus-style failover.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'plans'
            ? new BaseRepository($this->db(), 'routing_plans', ['code', 'name', 'strategy', 'status', 'description', 'is_demo'], 'none', ['code', 'name'], ['id', 'code', 'name', 'strategy', 'status'])
            : new BaseRepository($this->db(), 'routing_rules', ['routing_plan_id', 'prefix', 'carrier_trunk_id', 'priority', 'status'], 'none', ['prefix'], ['id', 'prefix', 'priority', 'routing_plan_id']);
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'plans' ? ['status'] : ['routing_plan_id', 'status'];
    }

    /** @return array<string,string> */
    private function strategyOptions(): array
    {
        return [
            'priority' => 'Priority',
            'lcr' => 'LCR',
            'asr' => 'Quality-Based Routing (ASR)',
            'random' => 'Random',
        ];
    }

    protected function fields(Request $r): array
    {
        $this->statsWindow = TrunkHourStatsService::normalizeMinutes((int) $r->input('stats_window', $r->query('stats_window', 60)));
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'];
        if ($this->kind === 'plans') {
            $meta = $this->trunkMeta();
            $opts = [];
            foreach ($meta as $id => $m) {
                $opts[$id] = $m['label'];
            }
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'strategy', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => $this->strategyOptions(), 'list' => true, 'default' => 'priority', 'help' => 'Priority = drag order · LCR = cheapest first · Quality-Based Routing (ASR) = highest answer rate in the stats window · Random = shuffle each call'],
                [
                    'name' => 'trunks',
                    'label' => 'Trunks',
                    'type' => 'text',
                    'list' => true,
                    'list_only' => true,
                ],
                $status,
                [
                    'name' => 'trunk_ids',
                    'label' => 'Your waterfall',
                    'type' => 'trunk_waterfall',
                    'wide' => true,
                    'required' => true,
                    'options' => $opts,
                    'trunk_meta' => $meta,
                    'stats_window' => $this->statsWindow,
                    'help' => 'Connected results for the selected window. Drag to set order; toggle off to keep a trunk in the group without using it.',
                ],
            ];
        }
        $plans = [];
        foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
            $plans[$p['id']] = $p['name'];
        }
        $trunks = [];
        foreach ($this->db()->query('SELECT t.id, t.name, c.name AS carrier FROM carrier_trunks t JOIN carriers c ON c.id = t.carrier_id ORDER BY c.name, t.name LIMIT 500')->fetchAll() as $t) {
            $trunks[$t['id']] = "{$t['carrier']} / {$t['name']}";
        }
        return [
            ['name' => 'routing_plan_id', 'label' => 'Trunk group', 'type' => 'select', 'required' => true, 'options' => $plans, 'list' => true],
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'prefix', 'required' => true, 'list' => true],
            ['name' => 'carrier_trunk_id', 'label' => 'Trunk', 'type' => 'select', 'required' => true, 'options' => $trunks, 'list' => true],
            ['name' => 'priority', 'label' => 'Order', 'type' => 'int', 'min' => 0, 'max' => 100000, 'required' => true, 'default' => '100', 'list' => true],
            $status,
        ];
    }

    /**
     * @return array<string,array{id:string,name:string,carrier:string,label:string,asr:float,acd:float,attempts:int,answered:int,share:float,acd_label:string,window_label:string}>
     */
    private function trunkMeta(): array
    {
        $minutes = $this->statsWindow;
        $rows = [];
        try {
            $rows = $this->db()->query(
                "SELECT t.id, t.name, c.name AS carrier
                 FROM carrier_trunks t
                 JOIN carriers c ON c.id = t.carrier_id
                 WHERE t.status = 'active'
                 ORDER BY c.name, t.name
                 LIMIT 500"
            )->fetchAll() ?: [];
        } catch (\Throwable) {
            $rows = [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $stats = (new TrunkHourStatsService($this->db()))->window($minutes, $ids === [] ? [] : $ids);
        $label = TrunkHourStatsService::windowLabel($minutes);
        $meta = [];
        foreach ($rows as $t) {
            $id = (string) (int) $t['id'];
            $s = $stats[$id] ?? null;
            $acd = $s['acd'] ?? 0.0;
            $longAcd = (float) ($s['long_acd'] ?? 0);
            $meta[$id] = [
                'id' => $id,
                'name' => (string) $t['name'],
                'carrier' => (string) $t['carrier'],
                'label' => $t['carrier'] . ' / ' . $t['name'],
                'asr' => (float) ($s['asr'] ?? 0),
                'acd' => (float) $acd,
                'attempts' => (int) ($s['attempts'] ?? 0),
                'answered' => (int) ($s['answered'] ?? 0),
                'failed' => (int) ($s['failed'] ?? 0),
                'share' => (float) ($s['share'] ?? 0),
                'acd_label' => self::formatTalk($acd),
                'long_answered' => (int) ($s['long_answered'] ?? 0),
                'long_acd' => $longAcd,
                'long_acd_label' => self::formatTalk($longAcd),
                'window_label' => $label,
            ];
        }
        return $meta;
    }

    private static function formatTalk(float $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        if ($seconds < 60) {
            return ((int) round($seconds)) . 's';
        }
        $m = (int) floor($seconds / 60);
        $s = (int) round($seconds - ($m * 60));
        return $m . 'm' . ($s > 0 ? $s . 's' : '');
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'plans') {
            $this->statsWindow = TrunkHourStatsService::normalizeMinutes((int) $r->input('stats_window', $r->query('stats_window', 60)));
            $data = $this->autoCode($data, $existing, 'routing_plans');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
            unset($data['trunks']);
            $rawOn = $data['trunk_ids'] ?? '';
            unset($data['trunk_ids']);
            $on = $this->parseIdList(is_string($rawOn) ? $rawOn : '');
            $off = $this->parseIdList((string) $r->input('trunk_off_ids', ''));
            $off = array_values(array_diff($off, $on));
            $members = [];
            foreach ($on as $id) {
                $members[] = ['id' => (int) $id, 'status' => 'active'];
            }
            foreach ($off as $id) {
                $members[] = ['id' => (int) $id, 'status' => 'disabled'];
            }
            if ($on === []) {
                throw new \DomainException('Turn on at least one trunk in your waterfall.');
            }
            $this->pendingMembers = $members;
            $strategy = (string) ($data['strategy'] ?? 'priority');
            if (!isset($this->strategyOptions()[$strategy])) {
                throw new \DomainException('Type must be Priority, LCR, Best connectivity or Random.');
            }
            // For ASR / LCR, re-order active members at save when strategy needs auto order
            if ($strategy === 'asr' || $strategy === 'lcr') {
                $this->pendingMembers = $this->autoOrderMembers($this->pendingMembers, $strategy);
            }
        }
        return $data;
    }

    /**
     * @param list<array{id:int,status:string}> $members
     * @return list<array{id:int,status:string}>
     */
    private function autoOrderMembers(array $members, string $strategy): array
    {
        $active = array_values(array_filter($members, static fn ($m) => $m['status'] === 'active'));
        $off = array_values(array_filter($members, static fn ($m) => $m['status'] !== 'active'));
        if ($strategy === 'asr') {
            $stats = (new TrunkHourStatsService($this->db()))->window($this->statsWindow, array_column($active, 'id'));
            usort($active, static function (array $a, array $b) use ($stats): int {
                $sa = $stats[(string) $a['id']]['asr'] ?? -1.0;
                $sb = $stats[(string) $b['id']]['asr'] ?? -1.0;
                if ($sa === $sb) {
                    $aa = $stats[(string) $a['id']]['attempts'] ?? 0;
                    $ab = $stats[(string) $b['id']]['attempts'] ?? 0;
                    return $ab <=> $aa;
                }
                return $sb <=> $sa;
            });
        } elseif ($strategy === 'lcr') {
            $costs = [];
            $resolver = new RateResolver($this->db(), new SettingsService($this->db()));
            $rating = new RatingService();
            foreach ($active as $m) {
                $tid = (int) $m['id'];
                // Sample a common NANP-style destination to rank trunks by buy rate
                $k = $resolver->carrier($tid, '12125550100');
                if ($k === null) {
                    $k = $resolver->carrier($tid, '1');
                }
                $costs[(string) $tid] = $k
                    ? (string) $rating->rate($k['rate'], $k['initial'], $k['subsequent'], 60, $k['connect_fee'])['total']
                    : null;
            }
            usort($active, static function (array $a, array $b) use ($costs): int {
                $ca = $costs[(string) $a['id']] ?? null;
                $cb = $costs[(string) $b['id']] ?? null;
                if ($ca === null xor $cb === null) {
                    return $ca === null ? 1 : -1;
                }
                if ($ca !== null && ($c = bccomp((string) $ca, (string) $cb, 6)) !== 0) {
                    return $c;
                }
                return $a['id'] <=> $b['id'];
            });
        }
        return array_merge($active, $off);
    }

    /** @return list<string> */
    private function parseIdList(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p)) {
                $ids[] = $p;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'plans') {
            $this->syncGroupTrunks($id, $this->pendingMembers);
            $this->pushRoutes();
            $on = count(array_filter($this->pendingMembers, static fn ($m) => $m['status'] === 'active'));
            return 'Trunk Group saved with ' . $on . ' active trunk(s). Select it on a Tariff.';
        }
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'plans') {
            $this->db()->prepare('DELETE FROM routing_rules WHERE routing_plan_id = ?')->execute([(int) $row['id']]);
            $this->pushRoutes();
        }
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
    }

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if ($this->kind === 'plans' && $existing !== null && !isset($values['trunk_ids'])) {
            $st = $this->db()->prepare(
                "SELECT carrier_trunk_id, status FROM routing_rules
                 WHERE routing_plan_id = ? AND prefix = '*'
                 ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, priority ASC, id ASC"
            );
            $st->execute([(int) $existing['id']]);
            $on = [];
            $off = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $tid = (string) (int) $row['carrier_trunk_id'];
                if (($row['status'] ?? '') === 'active') {
                    $on[] = $tid;
                } else {
                    $off[] = $tid;
                }
            }
            $values['trunk_ids'] = implode(',', $on);
            $values['trunk_off_ids'] = implode(',', $off);
        }
        if ($this->kind === 'plans' && !isset($values['trunk_off_ids'])) {
            $values['trunk_off_ids'] = (string) ($r->input('trunk_off_ids', '') ?? '');
        }
        return $values;
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($this->kind !== 'plans' || $rows === []) {
            return $rows;
        }
        $ids = array_map(static fn ($row) => (int) $row['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db()->prepare(
            "SELECT r.routing_plan_id, r.status, t.name AS trunk_name, c.name AS carrier
             FROM routing_rules r
             JOIN carrier_trunks t ON t.id = r.carrier_trunk_id
             JOIN carriers c ON c.id = t.carrier_id
             WHERE r.routing_plan_id IN ({$ph}) AND r.prefix = '*'
             ORDER BY r.routing_plan_id ASC,
                      CASE WHEN r.status = 'active' THEN 0 ELSE 1 END,
                      r.priority ASC, r.id ASC"
        );
        $st->execute($ids);
        $byPlan = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['routing_plan_id'];
            $name = (string) $row['trunk_name'];
            $on = ($row['status'] ?? '') === 'active';
            $byPlan[$pid][] = $on ? $name : ($name . ' (off)');
        }
        foreach ($rows as &$row) {
            $list = $byPlan[(int) $row['id']] ?? [];
            $row['trunks'] = $list === [] ? '—' : implode(' → ', $list);
        }
        unset($row);
        return $rows;
    }

    /** @param list<array{id:int,status:string}> $members */
    private function syncGroupTrunks(int $planId, array $members): void
    {
        $pdo = $this->db();
        $pdo->prepare("DELETE FROM routing_rules WHERE routing_plan_id = ? AND prefix = '*'")->execute([$planId]);
        $ins = $pdo->prepare('INSERT INTO routing_rules (routing_plan_id, prefix, carrier_trunk_id, priority, status) VALUES (?,?,?,?,?)');
        $prio = 10;
        foreach ($members as $m) {
            $ins->execute([$planId, '*', $m['id'], $prio, $m['status']]);
            $prio += 10;
        }
    }

    private function pushRoutes(): void
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return;
        }
        try {
            (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
        } catch (\Throwable $e) {
            App::session()->flash('warning', 'Saved, but FreeSWITCH gateway sync failed: ' . (App::config('app.debug') ? $e->getMessage() : 'run php bin/sync-opensips-trunks.php'));
        }
    }

    public function simulateForm(Request $r, array $p = []): Response
    {
        return $this->view('routing/simulate', [
            'title' => 'Route Simulator', 'customers' => $this->customerOptions($r), 'result' => null,
            'values' => ['customer_id' => '', 'dst' => '', 'seconds' => '60'], 'error' => null,
        ]);
    }

    public function simulate(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = (string) $r->input('customer_id', '');
        $dst = (string) $r->input('dst', '');
        $secs = (string) $r->input('seconds', '60');
        $values = ['customer_id' => $cid, 'dst' => $dst, 'seconds' => $secs];
        $error = null;
        $result = null;
        if (!ctype_digit($cid) || !preg_match('/^\d{1,3}$/', $secs) || preg_match('/\d/', $dst) !== 1 || strlen($dst) > 40) {
            $error = 'Choose a customer, enter a destination number and a sample duration (0-999s).';
        } elseif ((new CustomerRepository($this->db()))->find((int) $cid, $scope) === null) {
            $error = 'Customer not found.';
        } else {
            $sim = new RouteSimulator($this->db(), new RateResolver($this->db(), new SettingsService($this->db())), new RatingService());
            $result = $sim->simulate($scope, (int) $cid, $dst, (int) $secs);
        }
        return $this->view('routing/simulate', [
            'title' => 'Route Simulator', 'customers' => $this->customerOptions($r), 'result' => $result, 'values' => $values, 'error' => $error,
        ], $error ? 422 : 200);
    }
}
