<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\ResellerRepository;

final class ResellerController extends CrudController
{
    protected string $base = '/resellers';
    protected string $singular = 'Reseller';
    protected string $plural = 'Resellers';
    protected string $emptyHint = 'No resellers. Add a reseller if you sell through partners who manage their own customers.';

    protected function repo(): BaseRepository
    {
        return new ResellerRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status'];
    }

    protected function fields(Request $r): array
    {
        return [
            ['name' => 'name', 'label' => 'Reseller name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'contact_email', 'label' => 'Email', 'type' => 'email', 'max' => 190, 'list' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'suspended' => 'Suspended'], 'list' => true],
            ['name' => 'can_view_rates', 'label' => 'May view customer rates', 'type' => 'checkbox'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'max' => 2000],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data = $this->autoCode($data, $existing, 'resellers');
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        return $data;
    }
}
