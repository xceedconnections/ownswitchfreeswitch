<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\TenantScope;
use PDO;

/** Customer sell tariffs (rate_plan_tariffs) or carrier buy tariffs (carrier_tariffs). */
final class TariffRepository extends BaseRepository
{
    public static function customer(PDO $pdo): self
    {
        return new self($pdo, 'rate_plan_tariffs',
            ['rate_plan_id', 'prefix', 'destination', 'routing_plan_id', 'rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'status', 'effective_from', 'effective_to', 'version_id'],
            'none', ['prefix', 'destination'], ['id', 'prefix', 'destination', 'rate', 'status', 'routing_plan_id']);
    }

    public static function carrier(PDO $pdo): self
    {
        return new self($pdo, 'carrier_tariffs',
            ['carrier_id', 'prefix', 'destination', 'rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'status', 'effective_from', 'effective_to'],
            'none', ['prefix', 'destination'], ['id', 'prefix', 'destination', 'rate', 'status']);
    }

    /** @param list<int> $ids */
    public function deleteByIds(TenantScope $scope, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        [$tw, $tp] = $scope->where($this->tenantMode());
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id IN ({$ph}) AND ({$tw})");
        $st->execute([...$ids, ...$tp]);
        return $st->rowCount();
    }

    /**
     * Delete all rows matching the same filters/search as the list page (not just current page).
     *
     * @param array<string,mixed> $filters
     */
    public function deleteFiltered(TenantScope $scope, array $filters, ?string $search): int
    {
        [$where, $params] = $this->buildWhere($scope, $filters, $search);
        // Safety: require at least one filter or search (never wipe entire table by accident)
        $hasFilter = false;
        foreach ($filters as $v) {
            if ($v !== null && $v !== '') {
                $hasFilter = true;
                break;
            }
        }
        if (!$hasFilter && ($search === null || trim($search) === '')) {
            return 0;
        }
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE {$where}");
        $st->execute($params);
        return $st->rowCount();
    }

    /** @return list<array{id:int,rate_plan_id?:int,carrier_id?:int}> */
    public function findIdsFiltered(TenantScope $scope, array $filters, ?string $search, int $limit = 50000): array
    {
        [$where, $params] = $this->buildWhere($scope, $filters, $search);
        $st = $this->pdo->prepare("SELECT id FROM `{$this->table}` WHERE {$where} LIMIT " . max(1, min(100000, $limit)));
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
