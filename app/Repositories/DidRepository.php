<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class DidRepository extends BaseRepository
{
    public static function providers(PDO $pdo): self
    {
        return new self($pdo, 'did_providers', ['name', 'carrier_id', 'status', 'notes'], 'none', ['name'], ['id', 'name', 'status']);
    }

    public static function dids(PDO $pdo): self
    {
        return new self(
            $pdo,
            'dids',
            ['did_provider_id', 'number_e164', 'number_norm', 'country', 'status', 'setup_fee', 'monthly_fee', 'per_minute', 'currency', 'notes'],
            'none',
            ['number_e164', 'number_norm', 'country'],
            ['id', 'number_norm', 'status', 'monthly_fee']
        );
    }

    public static function assignments(PDO $pdo): self
    {
        return new self(
            $pdo,
            'did_assignments',
            ['did_id', 'reseller_id', 'customer_id', 'sip_account_id', 'dest_type', 'destination', 'status', 'assigned_at', 'next_billing_at'],
            'owned',
            ['destination'],
            ['id', 'did_id', 'customer_id', 'status']
        );
    }
}
