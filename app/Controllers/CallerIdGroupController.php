<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CallerIdGroupRepository;

/**
 * Clients → CallerID: groups of dialled-prefix → outbound CLI rows, assigned per SIP user.
 * Group CRUD via CrudController; the edit page also lists/adds/deletes numbers.
 */
final class CallerIdGroupController extends CrudController
{
    protected string $base = '/callerid-groups';
    protected string $singular = 'CallerID group';
    protected string $plural = 'CallerID groups';
    protected array $writeRoles = ['super_admin', 'admin'];
    protected string $emptyHint = 'No CallerID groups. Create a group, add prefix → CLI numbers, then assign it to a SIP user.';

    /** @var ?array<string,mixed> group shown on the edit form (drives the numbers panel) */
    private ?array $formGroup = null;

    protected function repo(): BaseRepository
    {
        return new CallerIdGroupRepository($this->db());
    }

    private function groups(): CallerIdGroupRepository
    {
        return new CallerIdGroupRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        return [
            ['name' => 'name', 'label' => 'Group name', 'required' => true, 'max' => 120, 'list' => true],
            [
                'name' => 'customer_id',
                'label' => 'Customer',
                'type' => 'select',
                'options' => ['' => 'Global (any SIP user)'] + $this->customerOptions($r),
                'list' => true,
                'help' => 'Global groups can be assigned to any SIP user. Pick a customer to restrict the group to that customer’s SIP users.',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['active' => 'Active', 'disabled' => 'Disabled'],
                'default' => 'active',
                'list' => true,
            ],
            ['name' => 'number_count', 'label' => 'Numbers', 'list' => true, 'list_only' => true],
        ];
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        try {
            $ids = array_map(static fn ($x) => (int) $x['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db()->prepare("SELECT callerid_group_id, COUNT(*) c FROM callerid_group_numbers WHERE callerid_group_id IN ({$in}) GROUP BY callerid_group_id");
            $st->execute($ids);
            $counts = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $counts[(int) $c['callerid_group_id']] = (int) $c['c'];
            }
            foreach ($rows as &$row) {
                $row['number_count'] = $counts[(int) $row['id']] ?? 0;
            }
            unset($row);
        } catch (\Throwable) {
        }
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $cid = $data['customer_id'] ?? null;
        $data['customer_id'] = ($cid === null || $cid === '') ? null : (int) $cid;
        if ($data['customer_id'] !== null && !isset($this->customerOptions($r)[$data['customer_id']])) {
            throw new \DomainException('Select a valid customer.');
        }
        return $data;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        $st = $this->db()->prepare('SELECT COUNT(*) FROM sip_accounts WHERE callerid_group_id = ?');
        try {
            $st->execute([(int) $row['id']]);
            $n = (int) $st->fetchColumn();
        } catch (\Throwable) {
            return null; // column not migrated yet
        }
        return $n > 0 ? "This group is assigned to {$n} SIP user(s); unassign it first." : null;
    }

    protected function form(Request $r, ?array $existing, array $values, array $errors, int $status = 200): Response
    {
        $this->formGroup = $existing;
        return parent::form($r, $existing, $values, $errors, $status);
    }

    protected function extra(Request $r): array
    {
        if ($this->formGroup === null) {
            return [];
        }
        $gid = (int) $this->formGroup['id'];
        return [
            'afterFormPartial' => 'callerid_groups/_numbers',
            'group' => $this->formGroup,
            'numbers' => $this->groups()->numbers($gid),
        ];
    }

    public function addNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $back = $this->base . '/' . (int) $group['id'] . '/edit';

        $prefix = trim((string) ($r->post['prefix'] ?? ''));
        if ($prefix === '' || $prefix === '*') {
            $prefix = '*';
        } else {
            $prefix = ltrim($prefix, '+');
        }
        $cli = trim((string) ($r->post['cli_number'] ?? ''));
        $name = trim((string) ($r->post['name'] ?? ''));
        $status = (string) ($r->post['status'] ?? 'active');

        if ($prefix !== '*' && !preg_match('/^\d{1,32}$/', $prefix)) {
            return $this->redirect($r, $back, 'Prefix must be digits only (or * for any destination).', 'danger');
        }
        if (!preg_match('/^\+?[0-9]{3,30}$/', $cli)) {
            return $this->redirect($r, $back, 'CLI number must be 3–30 digits (optional leading +).', 'danger');
        }
        if (mb_strlen($name) > 64) {
            return $this->redirect($r, $back, 'Name must be at most 64 characters.', 'danger');
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }

        $row = ['prefix' => $prefix, 'cli_number' => $cli, 'name' => $name !== '' ? $name : null, 'status' => $status];
        $id = $this->groups()->addNumber((int) $group['id'], $row);
        $this->audit($r, 'create', 'callerid_group_numbers', $id, null, $row + ['callerid_group_id' => (int) $group['id']]);
        return $this->redirect($r, $back, 'Number added.');
    }

    public function deleteNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $group = $this->groups()->find((int) $p['id'], $this->scope($r));
        if ($group === null) {
            return $this->notFound();
        }
        $back = $this->base . '/' . (int) $group['id'] . '/edit';
        $numberId = (int) ($p['numberId'] ?? 0);
        $row = $this->groups()->findNumber((int) $group['id'], $numberId);
        if ($row === null) {
            return $this->redirect($r, $back, 'Number not found.', 'danger');
        }
        $this->groups()->deleteNumber((int) $group['id'], $numberId);
        $this->audit($r, 'delete', 'callerid_group_numbers', $numberId, $row, null);
        return $this->redirect($r, $back, 'Number removed.');
    }
}
