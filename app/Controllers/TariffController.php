<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;

/**
 * Magnus Tariffs / Provider Rates field set.
 * Sell: Plan, Destination, Trunk group, Sell price, Initial/Billing block, Connection charge, Status.
 * Buy: Provider, Destination, Buy price, Initial/Billing block, Status.
 */
final class TariffController extends CrudController
{
    public function __construct(private string $kind = 'customer')
    {
        $carrier = $kind === 'carrier';
        $this->base = $carrier ? '/provider-rates' : '/tariffs';
        $this->singular = $carrier ? 'Provider rate' : 'Tariff';
        $this->plural = $carrier ? 'Provider Rates' : 'Tariffs';
        $this->emptyHint = $carrier
            ? 'No provider rates. Magnus: Provider + Destination + buy price.'
            : 'No tariffs yet. Choose a plan, then a plan prefix (1 · CC), then a code such as 203.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'carrier' ? TariffRepository::carrier($this->db()) : TariffRepository::customer($this->db());
    }

    protected function filterColumns(): array
    {
        return [$this->kind === 'carrier' ? 'carrier_id' : 'rate_plan_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $carrier = $this->kind === 'carrier';
        $opts = [];
        $sql = $carrier
            ? 'SELECT id, name FROM carriers ORDER BY name LIMIT 500'
            : 'SELECT id, name FROM rate_plans ORDER BY name LIMIT 500';
        foreach ($this->db()->query($sql)->fetchAll() as $x) {
            $opts[$x['id']] = $x['name'];
        }
        $prefixes = (new PrefixRepository($this->db()))->options();
        $fields = [
            [
                'name' => $carrier ? 'carrier_id' : 'rate_plan_id',
                'label' => $carrier ? 'Provider' : 'Plan',
                'type' => 'select',
                'required' => true,
                'options' => $opts,
                'readonly_on_edit' => true,
                'list' => true,
            ],
        ];
        if (!$carrier) {
            $planPrefixes = [];
            $planPrefixData = [];
            try {
                $pp = $this->db()->query(
                    'SELECT pp.id, pp.prefix, pp.name, pp.rate_plan_id
                     FROM plan_prefixes pp
                     ORDER BY pp.prefix, pp.name
                     LIMIT 2000'
                )->fetchAll();
                foreach ($pp as $row) {
                    $id = (string) $row['id'];
                    $planPrefixes[$id] = (string) $row['prefix'] . ' · ' . (string) $row['name'];
                    $planPrefixData[$id] = ['plan' => (string) $row['rate_plan_id']];
                }
            } catch (\Throwable) {
                $planPrefixes = [];
            }
            $fields[] = [
                'name' => 'plan_prefix_id',
                'label' => 'Plan prefix',
                'type' => 'select',
                'required' => false,
                'options' => $planPrefixes,
                'option_data' => $planPrefixData,
                'list' => true,
                'help' => $planPrefixes === []
                    ? 'Add a plan prefix first (Rates → Plan Prefixes), for example prefix 1 named CC.'
                    : 'Required on a new tariff. Pick the plan first, then one of its prefixes (1 · CC).',
            ];
        }
        $fields[] = [
                'name' => 'prefix',
                'label' => $carrier ? 'Destination' : 'Code',
                'type' => 'select',
                'required' => true,
                'options' => $prefixes,
                'readonly_on_edit' => true,
                'list' => true,
                'wide' => true,
                'help' => $prefixes === []
                    ? 'Create Prefixes first (Rates → Prefixes).'
                    : ($carrier ? 'From Rates → Prefixes.' : 'Digits after the plan prefix, from Prefixes. Example: 203.'),
            ];
        if (!$carrier) {
            $groups = ['' => '— use user’s Trunk Group —'];
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $g) {
                $groups[$g['id']] = $g['name'];
            }
            $fields[] = [
                'name' => 'routing_plan_id',
                'label' => 'Trunk groups',
                'type' => 'select',
                'options' => $groups,
                'list' => true,
                'help' => 'Trunks used to send this call. Blank = user’s Trunk Group.',
            ];
        }
        return array_merge($fields, [
            [
                'name' => 'rate',
                'label' => $carrier ? 'Buy price' : 'Sell price',
                'type' => 'number',
                'required' => true,
                'list' => true,
                'help' => 'Per minute',
            ],
            ['name' => 'initial_increment', 'label' => 'Initial block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true, 'help' => 'Seconds'],
            ['name' => 'subsequent_increment', 'label' => 'Billing block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true, 'help' => 'Seconds'],
            ['name' => 'connect_fee', 'label' => 'Connection charge', 'type' => 'number', 'default' => '0'],
            ['name' => 'effective_from', 'label' => 'Effective from', 'type' => 'datetime', 'help' => 'Optional. Format YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC). Blank = immediate.'],
            ['name' => 'effective_to', 'label' => 'Effective to', 'type' => 'datetime', 'help' => 'Optional end of window. Blank = no end. Use for scheduled rate changes.'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
        ]);
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $prefix = (string) ($data['prefix'] ?? ($existing['prefix'] ?? ''));
        $dest = (new PrefixRepository($this->db()))->destinationFor($prefix);
        if ($dest === null) {
            throw new \DomainException('Select a Destination from Prefixes.');
        }
        $data['prefix'] = $prefix;
        $data['destination'] = $dest;
        if ($this->kind === 'customer') {
            $tg = $data['routing_plan_id'] ?? null;
            $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;
            $planId = (int) ($data['rate_plan_id'] ?? ($existing['rate_plan_id'] ?? 0));
            $ppid = $data['plan_prefix_id'] ?? null;
            if ($ppid === null || $ppid === '') {
                if ($existing === null) {
                    throw new \DomainException('Select a plan prefix.');
                }
                $data['plan_prefix_id'] = $existing['plan_prefix_id'] ?? null;
                $ppid = $data['plan_prefix_id'];
            }
            if ($ppid === null || $ppid === '') {
                $ppid = null;
            }
            if ($ppid !== null) {
                $st = $this->db()->prepare('SELECT rate_plan_id, prefix, name, status FROM plan_prefixes WHERE id = ? LIMIT 1');
                $st->execute([(int) $ppid]);
                $pp = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$pp || (int) $pp['rate_plan_id'] !== $planId) {
                    throw new \DomainException('That plan prefix does not belong to the selected plan.');
                }
                if (strtolower((string) $pp['status']) !== 'active') {
                    throw new \DomainException('That plan prefix is inactive.');
                }
                $data['plan_prefix_id'] = (int) $ppid;
            }
        }
        if (($data['initial_increment'] ?? null) === null || $data['initial_increment'] === '') {
            $data['initial_increment'] = 6;
        }
        if (($data['subsequent_increment'] ?? null) === null || $data['subsequent_increment'] === '') {
            $data['subsequent_increment'] = 6;
        }
        if (($data['connect_fee'] ?? null) === null || $data['connect_fee'] === '') {
            $data['connect_fee'] = '0';
        }
        foreach (['effective_from', 'effective_to'] as $col) {
            if (($data[$col] ?? null) === '' || ($data[$col] ?? null) === null) {
                $data[$col] = null;
            }
        }
        if ($data['effective_from'] !== null && $data['effective_to'] !== null
            && (string) $data['effective_to'] < (string) $data['effective_from']) {
            throw new \DomainException('Effective to must be on or after effective from.');
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'customer') {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                (new RatePlanRepository($this->db()))->createVersion((int) $row['rate_plan_id'], (int) ($this->user($r)['id'] ?? 0) ?: null);
            }
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'customer') {
            (new RatePlanRepository($this->db()))->createVersion((int) $row['rate_plan_id'], (int) ($this->user($r)['id'] ?? 0) ?: null);
        }
    }

    protected function extra(Request $r): array
    {
        $ownerKey = $this->kind === 'carrier' ? 'carrier_id' : 'rate_plan_id';
        $ownerLabel = $this->kind === 'carrier' ? 'Provider' : 'Plan';
        $opts = ['' => 'All'];
        $sql = $this->kind === 'carrier'
            ? 'SELECT id, name FROM carriers ORDER BY name LIMIT 500'
            : 'SELECT id, name FROM rate_plans ORDER BY name LIMIT 500';
        foreach ($this->db()->query($sql)->fetchAll() as $x) {
            $opts[(string) $x['id']] = (string) $x['name'];
        }
        $extra = [
            'bulkDelete' => true,
            'bulkRateUpdate' => true,
            'ownerFilter' => ['name' => $ownerKey, 'label' => $ownerLabel, 'options' => $opts],
            'batchPriceLabel' => $this->kind === 'carrier' ? 'Buy price' : 'Sell price',
        ];
        if ($this->kind === 'customer') {
            $groups = ['' => 'Leave unchanged', '0' => 'Use user’s trunk group'];
            try {
                foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $g) {
                    $groups[(string) $g['id']] = (string) $g['name'];
                }
            } catch (\Throwable) {
            }
            $extra['batchTrunkGroups'] = $groups;
            $prefixes = ['' => 'Leave unchanged', '0' => 'No plan prefix'];
            try {
                $pp = $this->db()->query(
                    'SELECT pp.id, pp.prefix, pp.name, rp.name AS plan_name
                     FROM plan_prefixes pp
                     JOIN rate_plans rp ON rp.id = pp.rate_plan_id
                     ORDER BY rp.name, pp.prefix, pp.name
                     LIMIT 2000'
                )->fetchAll();
                foreach ($pp ?: [] as $row) {
                    $digits = trim((string) ($row['prefix'] ?? ''));
                    $prefixes[(string) $row['id']] = (string) $row['plan_name'] . ' — '
                        . ($digits === '' ? 'No prefix' : $digits) . ' · ' . (string) $row['name'];
                }
            } catch (\Throwable) {
            }
            $extra['batchPlanPrefixes'] = $prefixes;
        }
        return $extra;
    }

    /** Mass-edit rates: percent change and/or absolute new rate on selected rows. */
    public function bulkRateUpdate(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $raw = $r->input('ids', []);
        $ids = [];
        if (is_array($raw)) {
            $ids = $raw;
        } elseif (is_string($raw)) {
            $ids = preg_split('/[\s,]+/', $raw) ?: [];
        }
        $idList = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        $mode = (string) $r->input('rate_mode', 'percent');
        $value = trim((string) $r->input('rate_value', ''));
        $prefix = trim((string) $r->input('prefix_filter', ''));
        $prefixMode = (string) $r->input('prefix_mode', 'starts');
        $initial = trim((string) $r->input('initial_increment', ''));
        $subsequent = trim((string) $r->input('subsequent_increment', ''));
        $trunkRaw = $r->input('routing_plan_id', null);
        $trunk = is_string($trunkRaw) ? $trunkRaw : null;
        $planPrefixRaw = $r->input('plan_prefix_id', null);
        $planPrefix = is_string($planPrefixRaw) ? $planPrefixRaw : null;
        $filters = [];
        foreach ($this->filterColumns() as $c) {
            $v = $r->input($c);
            if (!is_string($v) || $v === '') {
                $v = $r->query($c);
            }
            if (is_string($v) && $v !== '') {
                $filters[$c] = $v;
            }
        }
        $qRaw = $r->input('q');
        if (!is_string($qRaw) || $qRaw === '') {
            $qRaw = $r->query('q');
        }
        $q = is_string($qRaw) ? trim($qRaw) : '';

        if ($idList === []) {
            return $this->redirect($r, $this->base, 'Select at least one row to update.', 'warning');
        }
        try {
            $res = (new \Ownswitch\Services\Billing\BatchRateUpdateService($this->db()))->apply(
                $this->kind,
                $this->scope($r),
                $idList,
                [
                    'rate_mode' => $mode,
                    'rate_value' => $value,
                    'prefix_filter' => $prefix !== '' ? $prefix : null,
                    'prefix_mode' => $prefixMode === 'exact' ? 'exact' : 'starts',
                    'routing_plan_id' => $trunk,
                    'plan_prefix_id' => $planPrefix,
                    'initial_increment' => $initial !== '' ? $initial : null,
                    'subsequent_increment' => $subsequent !== '' ? $subsequent : null,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirect($r, $this->base, $e->getMessage(), 'danger');
        }

        if ($this->kind === 'customer' && $res['updated'] > 0) {
            $planIds = $this->planIdsForTariffIds($idList);
            $ver = new RatePlanRepository($this->db());
            $uid = (int) ($this->user($r)['id'] ?? 0) ?: null;
            foreach (array_unique($planIds) as $pid) {
                if ($pid > 0) {
                    $ver->createVersion($pid, $uid);
                }
            }
        }
        $this->audit($r, 'tariff_bulk_rate_update', $this->kind === 'carrier' ? 'carrier_tariffs' : 'rate_plan_tariffs', null, null, [
            'mode' => $mode, 'value' => $value, 'prefix_filter' => $prefix, 'prefix_mode' => $prefixMode,
            'routing_plan_id' => $trunk, 'plan_prefix_id' => $planPrefix,
            'initial_increment' => $initial, 'subsequent_increment' => $subsequent,
            'ids' => $idList, 'updated' => $res['updated'],
        ]);
        $qs = http_build_query(array_filter($filters + ($q !== '' ? ['q' => $q] : []), static fn ($v) => $v !== null && $v !== ''));
        return $this->redirect(
            $r,
            $this->base . ($qs !== '' ? '?' . $qs : ''),
            "Updated {$res['updated']} " . strtolower($this->plural) . '.',
            $res['updated'] > 0 ? 'success' : 'warning'
        );
    }

    /** Delete selected rows or all rows matching current search/filters. */
    public function bulkDestroy(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        /** @var TariffRepository $repo */
        $repo = $this->repo();
        $scope = $this->scope($r);
        $mode = (string) $r->input('mode', 'selected');
        $filters = [];
        foreach ($this->filterColumns() as $c) {
            $v = $r->input($c);
            if (!is_string($v) || $v === '') {
                $v = $r->query($c);
            }
            if (is_string($v) && $v !== '') {
                $filters[$c] = $v;
            }
        }
        $qRaw = $r->input('q');
        if (!is_string($qRaw) || $qRaw === '') {
            $qRaw = $r->query('q');
        }
        $q = is_string($qRaw) ? trim($qRaw) : '';
        $n = 0;
        $planIds = [];

        if ($mode === 'selected') {
            $raw = $r->input('ids', []);
            $ids = [];
            if (is_array($raw)) {
                $ids = $raw;
            } elseif (is_string($raw)) {
                $ids = preg_split('/[\s,]+/', $raw) ?: [];
            }
            $idList = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
            if ($this->kind === 'customer' && $idList !== []) {
                $planIds = $this->planIdsForTariffIds($idList);
            }
            $n = $repo->deleteByIds($scope, $idList);
        } elseif ($mode === 'filtered') {
            if ($this->kind === 'customer') {
                $rows = $repo->findIdsFiltered($scope, $filters, $q !== '' ? $q : null);
                $idList = array_map(static fn ($row) => (int) $row['id'], $rows);
                $planIds = $this->planIdsForTariffIds($idList);
            }
            $n = $repo->deleteFiltered($scope, $filters, $q !== '' ? $q : null);
        }

        if ($this->kind === 'customer' && $planIds !== []) {
            $ver = new RatePlanRepository($this->db());
            $uid = (int) ($this->user($r)['id'] ?? 0) ?: null;
            foreach (array_unique($planIds) as $pid) {
                if ($pid > 0) {
                    $ver->createVersion($pid, $uid);
                }
            }
        }
        $this->audit($r, 'tariff_bulk_delete', $this->kind === 'carrier' ? 'carrier_tariffs' : 'rate_plan_tariffs', null, null, [
            'mode' => $mode, 'count' => $n, 'filters' => $filters, 'q' => $q,
        ]);
        $qs = http_build_query(array_filter($filters + ($q !== '' ? ['q' => $q] : []), static fn ($v) => $v !== null && $v !== ''));
        return $this->redirect(
            $r,
            $this->base . ($qs !== '' ? '?' . $qs : ''),
            "Deleted {$n} " . strtolower($this->plural) . '.',
            $n > 0 ? 'success' : 'warning'
        );
    }

    /** @param list<int> $ids @return list<int> */
    private function planIdsForTariffIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db()->prepare("SELECT DISTINCT rate_plan_id FROM rate_plan_tariffs WHERE id IN ({$ph})");
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }
}
