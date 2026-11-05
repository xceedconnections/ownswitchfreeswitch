<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;

/** Block/allow destination prefixes (global or per customer). */
final class DestinationPolicyController extends CrudController
{
    protected string $base = '/destination-policies';
    protected string $singular = 'Destination policy';
    protected string $plural = 'Destination Policies';
    protected string $emptyHint = 'Block or allow destination prefixes globally or per user. Longest matching prefix wins on a call.';

    protected function repo(): BaseRepository
    {
        return new BaseRepository(
            $this->db(),
            'destination_policies',
            ['customer_id', 'prefix', 'action', 'note'],
            'none',
            ['prefix', 'note'],
            ['id', 'prefix', 'action', 'customer_id']
        );
    }

    protected function filterColumns(): array
    {
        return ['action'];
    }

    protected function fields(Request $r): array
    {
        $customers = ['' => '— Global (all users) —'] + $this->customerOptions($r);
        return [
            ['name' => 'customer_id', 'label' => 'User', 'type' => 'select', 'options' => $customers, 'list' => true, 'help' => 'Blank = applies to every user'],
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'prefix', 'required' => true, 'list' => true, 'help' => 'Digits only; longest match wins'],
            ['name' => 'action', 'label' => 'Action', 'type' => 'select', 'required' => true, 'options' => ['block' => 'Block', 'allow' => 'Allow'], 'list' => true, 'default' => 'block'],
            ['name' => 'note', 'label' => 'Note', 'max' => 255, 'list' => true],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $cid = $data['customer_id'] ?? null;
        $data['customer_id'] = ($cid === null || $cid === '') ? null : (int) $cid;
        if ($data['customer_id'] !== null) {
            $opts = $this->customerOptions($r);
            if (!isset($opts[$data['customer_id']])) {
                throw new \DomainException('Select a valid user.');
            }
        }
        $data['note'] = ($data['note'] ?? null) === '' ? null : ($data['note'] ?? null);
        return $data;
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $names = $this->customerOptions($r);
        foreach ($rows as &$row) {
            $cid = $row['customer_id'] ?? null;
            $row['customer_id'] = $cid === null || $cid === '' ? 'Global' : ($names[(int) $cid] ?? ('#' . $cid));
        }
        unset($row);
        return $rows;
    }
}
