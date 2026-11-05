<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Tenant-aware table gateway. Identifiers come only from constructor whitelists,
 * values are always bound parameters.
 */
class BaseRepository
{
    /**
     * @param list<string> $columns writable + filterable columns
     * @param string $tenantMode none|customer|reseller|owned (see TenantScope::where)
     * @param list<string> $searchColumns columns for free-text LIKE search
     * @param list<string> $sortable
     */
    public function __construct(
        protected PDO $pdo,
        protected string $table,
        protected array $columns,
        protected string $tenantMode = 'none',
        protected array $searchColumns = [],
        protected array $sortable = ['id'],
    ) {
    }

    public function table(): string
    {
        return $this->table;
    }

    public function tenantMode(): string
    {
        return $this->tenantMode;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, TenantScope $scope): ?array
    {
        [$tw, $tp] = $scope->where($this->tenantMode);
        $st = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ? AND ({$tw}) LIMIT 1");
        $st->execute([$id, ...$tp]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Internal/system use only (workers, services that have already authorised). */
    public function findInternal(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $filters column => value (only whitelisted columns honoured)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int}
     */
    public function paginate(
        TenantScope $scope,
        array $filters = [],
        int $page = 1,
        int $perPage = 25,
        string $sort = 'id',
        string $dir = 'desc',
        ?string $search = null,
    ): array {
        $page = max(1, $page);
        $perPage = min(1000, max(1, $perPage));
        [$where, $params] = $this->buildWhere($scope, $filters, $search);
        $sort = in_array($sort, $this->sortable, true) ? $sort : 'id';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $c = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}");
        $c->execute($params);
        $total = (int) $c->fetchColumn();

        $st = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE {$where} ORDER BY `{$sort}` {$dir} LIMIT ? OFFSET ?");
        $i = 1;
        foreach ($params as $p) {
            $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue($i++, $perPage, PDO::PARAM_INT);
        $st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();

        return [
            'rows'    => $st->fetchAll(PDO::FETCH_ASSOC),
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function all(TenantScope $scope, array $filters = [], int $limit = 500, string $sort = 'id'): array
    {
        return $this->paginate($scope, $filters, 1, $limit, $sort, 'asc')['rows'];
    }

    public function count(TenantScope $scope, array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($scope, $filters, null);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}");
        $c->execute($params);
        return (int) $c->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        $data = $this->only($data);
        if ($data === []) {
            throw new \InvalidArgumentException('No writable columns supplied');
        }
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(',', array_map(fn ($c) => "`{$c}`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, TenantScope $scope): bool
    {
        $data = $this->only($data);
        if ($data === []) {
            return false;
        }
        [$tw, $tp] = $scope->where($this->tenantMode);
        $set = implode(',', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        $st = $this->pdo->prepare("UPDATE `{$this->table}` SET {$set} WHERE id = ? AND ({$tw})");
        $st->execute([...array_values($data), $id, ...$tp]);
        return $st->rowCount() > 0;
    }

    /** Internal/system update without tenant scope (provisioning, CSV import). */
    public function updateInternal(int $id, array $data): bool
    {
        $data = $this->only($data);
        if ($data === []) {
            return false;
        }
        $set = implode(',', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        $st = $this->pdo->prepare("UPDATE `{$this->table}` SET {$set} WHERE id = ?");
        $st->execute([...array_values($data), $id]);
        return $st->rowCount() > 0;
    }

    public function delete(int $id, TenantScope $scope): bool
    {
        [$tw, $tp] = $scope->where($this->tenantMode);
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id = ? AND ({$tw})");
        $st->execute([$id, ...$tp]);
        return $st->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    protected function buildWhere(TenantScope $scope, array $filters, ?string $search): array
    {
        [$sql, $params] = $scope->where($this->tenantMode);
        $parts = ["({$sql})"];
        foreach ($filters as $col => $val) {
            if ($val === null || $val === '' || !in_array($col, $this->columns, true)) {
                continue;
            }
            $parts[] = "`{$col}` = ?";
            $isId = $col === 'id' || str_ends_with($col, '_id');
            $params[] = $isId && ctype_digit((string) $val) ? (int) $val : (string) $val;
        }
        if ($search !== null && $search !== '' && $this->searchColumns !== []) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $or = [];
            foreach ($this->searchColumns as $c) {
                $or[] = "`{$c}` LIKE ?";
                $params[] = $like;
            }
            $parts[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode(' AND ', $parts), $params];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function only(array $data): array
    {
        $allowed = array_flip($this->columns);
        $phys = array_flip($this->physicalColumns());
        $out = [];
        foreach ($data as $k => $v) {
            if (isset($allowed[$k]) && isset($phys[$k])) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return list<string> */
    protected function physicalColumns(): array
    {
        static $cache = [];
        if (isset($cache[$this->table])) {
            return $cache[$this->table];
        }
        try {
            $rows = $this->pdo->query("SHOW COLUMNS FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $cache[$this->table] = array_values(array_map(static fn ($r) => (string) $r['Field'], $rows));
        } catch (\Throwable) {
            $cache[$this->table] = $this->columns;
        }
        return $cache[$this->table];
    }
}
