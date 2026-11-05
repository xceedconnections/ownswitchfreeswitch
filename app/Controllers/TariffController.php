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
            : 'No tariffs yet. Magnus: Plan + Destination + Trunk group + sell price + blocks.';
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
            [
                'name' => 'prefix',
                'label' => 'Destination',
                'type' => 'select',
                'required' => true,
                'options' => $prefixes,
                'readonly_on_edit' => true,
                'list' => true,
                'wide' => true,
                'help' => $prefixes === [] ? 'Create Prefixes first (Rates → Prefixes).' : 'From Rates → Prefixes.',
            ],
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
        return [
            'bulkDelete' => true,
            'bulkRateUpdate' => true,
            'ownerFilter' => ['name' => $ownerKey, 'label' => $ownerLabel, 'options' => $opts],
        ];
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
                $mode,
                $value,
                $prefix !== '' ? $prefix : null,
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
            'mode' => $mode, 'value' => $value, 'prefix_filter' => $prefix, 'ids' => $idList, 'updated' => $res['updated'],
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
