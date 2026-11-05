<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;

/**
 * MagnusBilling-style Prefixes: dial code + destination name only (no rates).
 * Sell/buy prices live under Tariffs / Provider Rates and pick a Prefix as Destination.
 */
final class PrefixController extends CrudController
{
    protected string $base = '/prefixes';
    protected string $singular = 'Prefix';
    protected string $plural = 'Prefixes';
    protected string $emptyHint = 'No prefixes yet. Add dial codes here (e.g. 1 = USA, 44 = UK). Tariffs then pick a Prefix as Destination and set the sell rate — same as MagnusBilling.';
    protected string $defaultSort = 'prefix';

    protected function repo(): BaseRepository
    {
        return new PrefixRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return [];
    }

    protected function fields(Request $r): array
    {
        return [
            [
                'name' => 'prefix',
                'label' => 'Prefix',
                'type' => 'prefix',
                'required' => true,
                'readonly_on_edit' => true,
                'list' => true,
            ],
            [
                'name' => 'destination',
                'label' => 'Destination',
                'required' => true,
                'max' => 120,
                'list' => true,
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['destination'] = trim((string) ($data['destination'] ?? ''));
        if ($data['destination'] === '') {
            throw new \DomainException('Destination name is required.');
        }
        if ($existing === null) {
            $data['prefix'] = preg_replace('/\D+/', '', (string) ($data['prefix'] ?? '')) ?? '';
            if ($data['prefix'] === '') {
                throw new \DomainException('Prefix must be digits only.');
            }
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $row = $this->repo()->findInternal($id);
        if ($row) {
            // Keep tariff destination labels in sync when the Prefix name changes.
            $p = (string) $row['prefix'];
            $d = (string) $row['destination'];
            $this->db()->prepare('UPDATE rate_plan_tariffs SET destination = ? WHERE prefix = ?')->execute([$d, $p]);
            $this->db()->prepare('UPDATE carrier_tariffs SET destination = ? WHERE prefix = ?')->execute([$d, $p]);
        }
        return null;
    }
}
