<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;

/** Magnus “User Custom Rates” — per-customer prefix overrides. */
final class CustomRateController extends CrudController
{
    protected string $base = '/custom-rates';
    protected string $singular = 'Custom rate';
    protected string $plural = 'User Custom Rates';
    protected string $emptyHint = 'No custom rates. Override a user’s plan tariff for a specific Prefix (sell price / blocks).';

    protected function repo(): BaseRepository
    {
        return new BaseRepository(
            $this->db(),
            'customer_rate_overrides',
            ['customer_id', 'prefix', 'rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'status'],
            'none',
            ['prefix'],
            ['id', 'prefix', 'rate', 'status', 'customer_id']
        );
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $users = [];
        foreach ($this->db()->query('SELECT id, name FROM customers ORDER BY name LIMIT 500')->fetchAll() as $c) {
            $users[$c['id']] = $c['name'];
        }
        $prefixes = (new PrefixRepository($this->db()))->options();
        return [
            ['name' => 'customer_id', 'label' => 'User', 'type' => 'select', 'required' => true, 'options' => $users, 'readonly_on_edit' => true, 'list' => true],
            [
                'name' => 'prefix',
                'label' => 'Destination',
                'type' => 'select',
                'required' => true,
                'options' => $prefixes,
                'readonly_on_edit' => true,
                'list' => true,
                'wide' => true,
                'help' => $prefixes === [] ? 'Create Prefixes first (Rates → Prefixes).' : 'Prefix from the global Prefixes catalogue.',
            ],
            ['name' => 'rate', 'label' => 'Sell price', 'type' => 'number', 'required' => true, 'list' => true],
            ['name' => 'initial_increment', 'label' => 'Initial block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true],
            ['name' => 'subsequent_increment', 'label' => 'Billing block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true],
            ['name' => 'connect_fee', 'label' => 'Connection charge', 'type' => 'number', 'default' => '0'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $prefix = (string) ($data['prefix'] ?? ($existing['prefix'] ?? ''));
        if ((new PrefixRepository($this->db()))->destinationFor($prefix) === null) {
            throw new \DomainException('Select a Destination from Prefixes.');
        }
        $data['prefix'] = $prefix;
        return $data;
    }
}
