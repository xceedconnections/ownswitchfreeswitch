<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** CallerID groups (staff-managed, tenantMode none) + their prefix → CLI rows. */
final class CallerIdGroupRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'callerid_groups',
            ['name', 'customer_id', 'status'],
            'none',
            ['name'],
            ['id', 'name', 'status', 'created_at']
        );
    }

    /** @return list<array<string,mixed>> */
    public function numbers(int $groupId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM callerid_group_numbers WHERE callerid_group_id = ? ORDER BY prefix, id');
        $st->execute([$groupId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function numberCount(int $groupId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM callerid_group_numbers WHERE callerid_group_id = ?');
        $st->execute([$groupId]);
        return (int) $st->fetchColumn();
    }

    /** @param array{prefix:string,cli_number:string,name:?string,status:string} $row */
    public function addNumber(int $groupId, array $row): int
    {
        $this->pdo->prepare(
            'INSERT INTO callerid_group_numbers (callerid_group_id, prefix, cli_number, name, status) VALUES (?,?,?,?,?)'
        )->execute([$groupId, $row['prefix'], $row['cli_number'], $row['name'], $row['status']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findNumber(int $groupId, int $numberId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM callerid_group_numbers WHERE id = ? AND callerid_group_id = ? LIMIT 1');
        $st->execute([$numberId, $groupId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public function deleteNumber(int $groupId, int $numberId): bool
    {
        $st = $this->pdo->prepare('DELETE FROM callerid_group_numbers WHERE id = ? AND callerid_group_id = ?');
        $st->execute([$numberId, $groupId]);
        return $st->rowCount() > 0;
    }
}
