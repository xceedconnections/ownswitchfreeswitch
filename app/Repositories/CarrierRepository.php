<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/** carriers or carrier_trunks. */
final class CarrierRepository extends BaseRepository
{
    public static function carriers(PDO $pdo): self
    {
        return new self($pdo, 'carriers', ['code', 'name', 'status', 'contact_email', 'notes', 'is_demo'], 'none',
            ['code', 'name'], ['id', 'code', 'name', 'status', 'created_at']);
    }

    public static function trunks(PDO $pdo): self
    {
        return new self($pdo, 'carrier_trunks',
            ['carrier_id', 'name', 'host', 'port', 'transport', 'add_prefix', 'remove_prefix',
                'from_user', 'from_domain', 'cid_add_prefix', 'cid_remove_prefix', 'cid_min_len', 'cid_max_len', 'qualify',
                'auth_username', 'auth_secret_enc', 'auth_mode', 'outbound_proxy',
                'register_status', 'register_checked_at', 'max_channels', 'max_call_duration', 'rate_cap', 'dnc_group_id', 'dst_format', 'media_policy',
                'initial_increment', 'subsequent_increment', 'default_rate', 'connect_fee', 'priority', 'status'],
            'none', ['name', 'host'], ['id', 'name', 'host', 'auth_mode', 'status', 'carrier_id']);
    }
}
