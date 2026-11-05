<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use Ownswitch\Support\Destination;
use Ownswitch\Support\TenantScope;
use PDO;

final class AreaCodeRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'area_codes',
            ['code', 'name', 'notes'],
            'none',
            ['code', 'name'],
            ['id', 'code', 'name']
        );
    }

    public function upsert(string $code, string $name, ?string $notes = null): void
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $name = trim($name);
        if ($code === '' || $name === '') {
            return;
        }
        $notes = $notes !== null ? trim($notes) : null;
        if ($notes === '') {
            $notes = null;
        }
        $this->pdo->prepare(
            'INSERT INTO area_codes (code, name, notes) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), notes = VALUES(notes)'
        )->execute([$code, $name, $notes]);
    }

    /** @param list<int> $ids */
    public function deleteByIds(TenantScope $scope, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        [$tw, $tp] = $scope->where($this->tenantMode());
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id IN ({$ph}) AND ({$tw})");
        $st->execute([...$ids, ...$tp]);
        return $st->rowCount();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function deleteFiltered(TenantScope $scope, array $filters, ?string $search, bool $allowAll = false): int
    {
        $hasFilter = false;
        foreach ($filters as $v) {
            if ($v !== null && $v !== '') {
                $hasFilter = true;
                break;
            }
        }
        if (!$hasFilter && ($search === null || trim($search) === '') && !$allowAll) {
            return 0;
        }
        [$where, $params] = $this->buildWhere($scope, $filters, $search);
        $st = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE {$where}");
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Longest-prefix match for a dialled number → area name (e.g. 1213800… → LA).
     */
    public function nameForNumber(string $dst): ?string
    {
        $dst = Destination::normalize($dst);
        if ($dst === '') {
            return null;
        }
        $cands = Destination::candidates($dst, 15);
        if ($cands === []) {
            return null;
        }
        $in = Destination::placeholders($cands);
        $st = $this->pdo->prepare(
            "SELECT code, name FROM area_codes WHERE code IN ({$in}) ORDER BY LENGTH(code) DESC LIMIT 1"
        );
        $st->execute($cands);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['name'] : null;
    }

    /**
     * Batch lookup for many destinations (CDR list).
     *
     * @param list<string> $destinations
     * @return array<string,string> normalized dst => name
     */
    public function namesForNumbers(array $destinations): array
    {
        $out = [];
        $uniq = [];
        foreach ($destinations as $d) {
            $n = Destination::normalize((string) $d);
            if ($n !== '') {
                $uniq[$n] = true;
            }
        }
        if ($uniq === []) {
            return [];
        }
        // Load all area codes once (typically small); match longest in PHP
        $rows = $this->pdo->query('SELECT code, name FROM area_codes')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        $codes = [];
        foreach ($rows as $r) {
            $code = preg_replace('/\D+/', '', (string) ($r['code'] ?? '')) ?? '';
            $name = trim((string) ($r['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            // Keep longest name if duplicate codes
            if (!isset($codes[$code]) || strlen($name) > strlen($codes[$code])) {
                $codes[$code] = $name;
            }
            // NANP: also index NPA without leading country 1 (323 ↔ 1323)
            if (strlen($code) >= 4 && str_starts_with($code, '1')) {
                $short = substr($code, 1);
                if ($short !== '' && !isset($codes[$short])) {
                    $codes[$short] = $name;
                }
            } elseif (strlen($code) === 3) {
                $long = '1' . $code;
                if (!isset($codes[$long])) {
                    $codes[$long] = $name;
                }
            }
        }
        uksort($codes, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach (array_keys($uniq) as $dst) {
            foreach ($codes as $code => $name) {
                if (str_starts_with($dst, (string) $code)) {
                    $out[$dst] = $name;
                    break;
                }
            }
            // Fallback: strip leading 1 on 11-digit NANP and retry
            if (!isset($out[$dst]) && strlen($dst) === 11 && str_starts_with($dst, '1')) {
                $rest = substr($dst, 1);
                foreach ($codes as $code => $name) {
                    if (str_starts_with($rest, (string) $code)) {
                        $out[$dst] = $name;
                        break;
                    }
                }
            }
        }
        return $out;
    }
}
