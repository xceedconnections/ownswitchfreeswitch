<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** Staff-managed prepaid vouchers (global table, tenantMode none). */
final class VoucherRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'vouchers',
            ['code', 'amount', 'currency', 'status', 'created_by', 'used_by_customer_id', 'used_at', 'expires_at', 'notes'],
            'none',
            ['code', 'notes'],
            ['id', 'code', 'amount', 'status', 'expires_at', 'used_at', 'created_at']
        );
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM vouchers WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function codeExists(string $code): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM vouchers WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        return $st->fetchColumn() !== false;
    }
}
