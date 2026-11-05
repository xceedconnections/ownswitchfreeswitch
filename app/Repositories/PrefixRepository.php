<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class PrefixRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'prefixes', ['prefix', 'destination'], 'none', ['prefix', 'destination'], ['id', 'prefix', 'destination']);
    }

    /** @return array<string,string> prefix => "prefix — Destination" */
    public function options(int $limit = 5000): array
    {
        $st = $this->pdo->query('SELECT prefix, destination FROM prefixes ORDER BY LENGTH(prefix) DESC, prefix ASC LIMIT ' . max(1, $limit));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = (string) $r['prefix'];
            $d = trim((string) ($r['destination'] ?? ''));
            $out[$p] = $d !== '' ? ($p . ' — ' . $d) : $p;
        }
        return $out;
    }

    public function destinationFor(string $prefix): ?string
    {
        $st = $this->pdo->prepare('SELECT destination FROM prefixes WHERE prefix = ? LIMIT 1');
        $st->execute([$prefix]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** Ensure a prefix row exists (used when importing tariffs). */
    public function upsert(string $prefix, string $destination): void
    {
        $prefix = preg_replace('/\D+/', '', $prefix) ?? '';
        if ($prefix === '') {
            return;
        }
        $destination = trim($destination);
        if ($destination === '') {
            $destination = 'Prefix ' . $prefix;
        }
        $this->pdo->prepare(
            'INSERT INTO prefixes (prefix, destination) VALUES (?,?)
             ON DUPLICATE KEY UPDATE destination = IF(VALUES(destination) <> "" AND VALUES(destination) NOT LIKE "Prefix %", VALUES(destination), destination)'
        )->execute([$prefix, $destination]);
    }
}
