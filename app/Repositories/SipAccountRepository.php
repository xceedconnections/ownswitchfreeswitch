<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class SipAccountRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'sip_accounts',
            [
                'reseller_id', 'customer_id', 'username', 'domain', 'ha1', 'ha1b', 'password_enc',
                'caller_id', 'caller_id_mode', 'cid_min_len', 'cid_max_len',
                'sip_host', 'connection_mode', 'sip_port', 'codecs', 'disallow', 'nat', 'directmedia', 'qualify',
                'routing_plan_id', 'dtmfmode', 'dtmf_pass', 'insecure', 'peer_type', 'allowtransfer',
                'max_channels', 'max_cps', 'max_call_duration', 'rate_cap', 'dst_format', 'dnc_group_id', 'media_policy', 'status', 'notes', 'password_changed_at',
            ],
            'owned',
            ['username', 'caller_id'],
            ['id', 'username', 'sip_host', 'media_policy', 'max_channels', 'max_call_duration', 'status', 'created_at']
        );
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username, string $domain): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM sip_accounts WHERE username = ? AND domain = ? LIMIT 1');
        $st->execute([$username, $domain]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
}
