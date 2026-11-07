<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PlanPrefixRepository;

/** Dial prefix on a plan (digits + name). Each one has its own tariff codes. */
final class PlanPrefixController extends CrudController
{
    protected string $base = '/plan-prefixes';
    protected string $singular = 'Plan prefix';
    protected string $plural = 'Plan Prefixes';
    protected string $emptyHint = 'No plan prefixes yet. Add dial digits and a name on a plan (1 = CC, 2 = Convo route). Tariffs then pick that prefix and a code such as 203.';
    protected string $defaultSort = 'prefix';

    protected function repo(): BaseRepository
    {
        return new PlanPrefixRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['rate_plan_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $plans = [];
        foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $x) {
            $plans[$x['id']] = $x['name'];
        }
        return [
            [
                'name' => 'rate_plan_id',
                'label' => 'Plan',
                'type' => 'select',
                'required' => true,
                'options' => $plans,
                'readonly_on_edit' => true,
                'list' => true,
            ],
            [
                'name' => 'prefix',
                'label' => 'Prefix',
                'type' => 'prefix',
                'required' => false,
                'default_empty' => '',
                'list' => true,
                'help' => 'Optional. Digits the customer dials before the code. Leave blank for no prefix. You can change these later; the trunk group stays on the code.',
            ],
            [
                'name' => 'name',
                'label' => 'Name',
                'required' => true,
                'max' => 120,
                'list' => true,
                'help' => 'Shown with the prefix. Example: CC, Convo route.',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['active' => 'Active', 'disabled' => 'Inactive'],
                'list' => true,
                'default' => 'active',
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['name'] = trim((string) ($data['name'] ?? ''));
        if ($data['name'] === '') {
            throw new \DomainException('Name is required.');
        }
        $prefix = preg_replace('/\D+/', '', (string) ($data['prefix'] ?? '')) ?? '';
        $data['prefix'] = $prefix;
        if ($existing !== null) {
            $data['rate_plan_id'] = (int) $existing['rate_plan_id'];
        }
        return $data;
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        foreach ($rows as &$row) {
            if (trim((string) ($row['prefix'] ?? '')) === '') {
                $row['prefix'] = 'No prefix';
            }
        }
        unset($row);
        return $rows;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        $st = $this->db()->prepare('SELECT COUNT(*) FROM rate_plan_tariffs WHERE plan_prefix_id = ?');
        $st->execute([(int) $row['id']]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            return 'This plan prefix is used by ' . $n . ' tariff' . ($n === 1 ? '' : 's') . '. Move or delete those tariffs first.';
        }
        return null;
    }

    protected function extra(Request $r): array
    {
        $opts = ['' => 'All plans'];
        foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $x) {
            $opts[(string) $x['id']] = (string) $x['name'];
        }
        return [
            'ownerFilter' => ['name' => 'rate_plan_id', 'label' => 'Plan', 'options' => $opts],
        ];
    }
}
