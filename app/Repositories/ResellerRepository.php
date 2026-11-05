<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class ResellerRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'resellers',
            ['name', 'code', 'contact_email', 'status', 'can_view_rates', 'notes', 'is_demo'],
            'reseller',
            ['name', 'code', 'contact_email'],
            ['id', 'name', 'code', 'status', 'created_at']
        );
    }
}
