<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\TenantScope;
use PDO;

/** Read access to the append-only ledger. Writes go through LedgerService only. */
final class LedgerRepository extends BaseRepository
{
    public const COLUMNS = ['reseller_id', 'customer_id', 'entry_type', 'amount', 'currency', 'affects_balance', 'reference_entry_id',
        'cdr_id', 'source_system', 'external_ref', 'run_type', 'idempotency_key', 'description', 'created_by'];

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'ledger_entries', self::COLUMNS, 'owned', ['description', 'external_ref'],
            ['id', 'created_at', 'amount', 'entry_type']);
    }

    public function update(int $id, array $data, TenantScope $scope): bool
    {
        throw new \LogicException('Ledger entries are immutable. Post a reversing entry instead.');
    }

    public function delete(int $id, TenantScope $scope): bool
    {
        throw new \LogicException('Ledger entries are immutable. Post a reversing entry instead.');
    }
}
