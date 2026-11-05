<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class IpEndpointRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'ip_endpoints',
            ['reseller_id', 'customer_id', 'label', 'ip_address', 'mask', 'port', 'proto', 'status'],
            'owned',
            ['label', 'ip_address'],
            ['id', 'label', 'ip_address', 'status', 'created_at']
        );
    }

    /** @return array<string,mixed>|null active endpoint matching a source IP (exact or CIDR). */
    public function findActiveByIp(string $ip): ?array
    {
        $st = $this->pdo->query("SELECT * FROM ip_endpoints WHERE status = 'active'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::ipInCidr($ip, (string) $row['ip_address'], (int) $row['mask'])) {
                return $row;
            }
        }
        return null;
    }

    public static function ipInCidr(string $ip, string $net, int $mask): bool
    {
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = strlen($a) * 8;
        $mask = max(0, min($bits, $mask));
        $bytes = intdiv($mask, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $mask % 8;
        if ($rem === 0) {
            return true;
        }
        $m = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($a[$bytes]) & $m) === (ord($b[$bytes]) & $m);
    }
}
