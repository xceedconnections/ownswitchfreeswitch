<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class PlanPrefixRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'plan_prefixes',
            ['rate_plan_id', 'prefix', 'name', 'status'],
            'none',
            ['prefix', 'name'],
            ['id', 'prefix', 'name', 'status', 'rate_plan_id']
        );
    }
}
