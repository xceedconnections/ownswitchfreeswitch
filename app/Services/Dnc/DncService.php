<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dnc;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Support\Destination;
use Ownswitch\Support\RedisClient;
use PDO;

/**
 * DNC groups/numbers + hot-path cache sync (Redis SET + OpenSIPS MySQL dnc_cache).
 * Call lookups never hit PHP — OpenSIPS uses Redis SISMEMBER or indexed SQL.
 */
final class DncService
{
    public const REDIS_KEY_PREFIX = 'ownswitch:dnc:';

    public function __construct(private PDO $pdo)
    {
    }

    public static function redisKey(int $groupId): string
    {
        return self::REDIS_KEY_PREFIX . $groupId;
    }

    /**
     * Create DNC tables / trunk column if missing (portal Update sometimes skips migrate).
     * Idempotent — safe on every DNC page hit.
     */
    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS dnc_groups (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  name VARCHAR(120) NOT NULL,
                  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                  notes VARCHAR(255) NULL,
                  number_count INT UNSIGNED NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_dnc_groups_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] DNC ensure groups: ' . $e->getMessage());
            throw $e;
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS dnc_numbers (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  dnc_group_id BIGINT UNSIGNED NOT NULL,
                  number_digits VARCHAR(32) NOT NULL,
                  note VARCHAR(255) NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_dnc_group_number (dnc_group_id, number_digits),
                  KEY idx_dnc_number_digits (number_digits),
                  CONSTRAINT fk_dnc_numbers_group FOREIGN KEY (dnc_group_id) REFERENCES dnc_groups(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // Retry without named FK (older partial tables / privilege quirks)
            try {
                $this->pdo->exec(
                    "CREATE TABLE IF NOT EXISTS dnc_numbers (
                      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      dnc_group_id BIGINT UNSIGNED NOT NULL,
                      number_digits VARCHAR(32) NOT NULL,
                      note VARCHAR(255) NULL,
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_dnc_group_number (dnc_group_id, number_digits),
                      KEY idx_dnc_number_digits (number_digits)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } catch (\Throwable $e2) {
                error_log('[ownswitch] DNC ensure numbers: ' . $e->getMessage() . ' / ' . $e2->getMessage());
                throw $e2;
            }
        }
        try {
            $st = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'dnc_group_id'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->pdo->exec(
                    'ALTER TABLE carrier_trunks ADD COLUMN dnc_group_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER rate_cap, ADD KEY idx_carrier_trunks_dnc (dnc_group_id)'
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] DNC ensure trunk column: ' . $e->getMessage());
        }
        $done = true;
    }

    /** @return list<array<string,mixed>> */
    public function listGroups(): array
    {
        $this->ensureSchema();
        return $this->pdo->query(
            'SELECT id, name, status, notes, number_count, updated_at FROM dnc_groups ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findGroup(int $id): ?array
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare('SELECT * FROM dnc_groups WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,string> id => name for selects */
    public function groupOptions(bool $activeOnly = true): array
    {
        $this->ensureSchema();
        $sql = 'SELECT id, name FROM dnc_groups';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= ' ORDER BY name';
        $out = ['' => '— None —'];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    public function createGroup(string $name, string $status = 'active', ?string $notes = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \DomainException('Group name is required.');
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }
        $st = $this->pdo->prepare('INSERT INTO dnc_groups (name, status, notes) VALUES (?,?,?)');
        $st->execute([$name, $status, $notes !== null && trim($notes) !== '' ? trim($notes) : null]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateGroup(int $id, string $name, string $status, ?string $notes): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \DomainException('Group name is required.');
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }
        $st = $this->pdo->prepare('UPDATE dnc_groups SET name = ?, status = ?, notes = ? WHERE id = ?');
        $st->execute([$name, $status, $notes !== null && trim($notes) !== '' ? trim($notes) : null, $id]);
        $this->rebuildGroupCache($id);
    }

    public function deleteGroup(int $id): void
    {
        $this->pdo->prepare('UPDATE carrier_trunks SET dnc_group_id = NULL WHERE dnc_group_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM dnc_groups WHERE id = ?')->execute([$id]);
        $this->dropGroupCache($id);
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int}
     */
    public function listNumbers(int $groupId, string $q = '', int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $q = trim($q);
        $where = 'dnc_group_id = ?';
        $params = [$groupId];
        if ($q !== '') {
            $digits = Destination::normalize($q);
            if ($digits !== '') {
                $where .= ' AND number_digits LIKE ?';
                $params[] = '%' . $digits . '%';
            } else {
                $where .= ' AND (note LIKE ?)';
                $params[] = '%' . $q . '%';
            }
        }
        $cst = $this->pdo->prepare("SELECT COUNT(*) FROM dnc_numbers WHERE {$where}");
        $cst->execute($params);
        $total = (int) $cst->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $st = $this->pdo->prepare(
            "SELECT id, number_digits, note, created_at FROM dnc_numbers WHERE {$where}
             ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $st->execute($params);
        return [
            'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => $pages,
        ];
    }

    public function addNumber(int $groupId, string $raw, ?string $note = null): string
    {
        $digits = Destination::normalize($raw);
        if ($digits === '' || strlen($digits) < 3 || strlen($digits) > 32) {
            throw new \DomainException('Enter a valid phone number (3–32 digits).');
        }
        if ($this->findGroup($groupId) === null) {
            throw new \DomainException('DNC group not found.');
        }
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO dnc_numbers (dnc_group_id, number_digits, note) VALUES (?,?,?)'
            );
            $st->execute([
                $groupId,
                $digits,
                $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new \DomainException("Number {$digits} is already in this group.");
            }
            throw $e;
        }
        $this->bumpCount($groupId, 1);
        $this->cacheAdd($groupId, self::nanpAliases($digits));
        return $digits;
    }

    /**
     * NANP dial variants so 13234462244 and 3234462244 both hit the same DNC entry.
     *
     * @return list<string>
     */
    public static function nanpAliases(string $digits): array
    {
        $digits = Destination::normalize($digits);
        if ($digits === '') {
            return [];
        }
        $out = [$digits];
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $out[] = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $out[] = '1' . $digits;
        }
        return array_values(array_unique($out));
    }

    /** @param list<int> $ids */
    public function deleteNumbersById(int $groupId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT number_digits FROM dnc_numbers WHERE dnc_group_id = ? AND id IN ({$in})");
        $st->execute([$groupId, ...$ids]);
        $digits = array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'number_digits');
        $del = $this->pdo->prepare("DELETE FROM dnc_numbers WHERE dnc_group_id = ? AND id IN ({$in})");
        $del->execute([$groupId, ...$ids]);
        $n = $del->rowCount();
        if ($n > 0) {
            $this->bumpCount($groupId, -$n);
            $this->cacheRemove($groupId, $digits);
        }
        return $n;
    }

    /**
     * Bulk add or delete from spreadsheet rows.
     *
     * @param list<array{number:string,note?:string}> $rows
     * @return array{ok:int,skipped:int,errors:list<string>}
     */
    public function importRows(int $groupId, array $rows, string $mode = 'add'): array
    {
        if ($this->findGroup($groupId) === null) {
            throw new \DomainException('DNC group not found.');
        }
        $mode = $mode === 'delete' ? 'delete' : 'add';
        $ok = 0;
        $skipped = 0;
        $errors = [];
        $batch = [];
        $flushAdd = function () use (&$batch, &$ok, &$skipped, $groupId): void {
            if ($batch === []) {
                return;
            }
            $unique = [];
            foreach ($batch as $row) {
                $unique[$row['number']] = $row['note'];
            }
            $digits = array_keys($unique);
            $existing = $this->existingDigits($groupId, $digits);
            $insert = [];
            foreach ($unique as $num => $note) {
                if (isset($existing[$num])) {
                    $skipped++;
                    continue;
                }
                $insert[] = [$groupId, $num, $note];
            }
            if ($insert !== []) {
                $this->insertBatch($insert);
                $this->bumpCount($groupId, count($insert));
                $this->cacheAdd($groupId, array_column($insert, 1));
                $ok += count($insert);
            }
            $batch = [];
        };

        foreach ($rows as $i => $row) {
            $raw = (string) ($row['number'] ?? '');
            $digits = Destination::normalize($raw);
            if ($digits === '' || strlen($digits) < 3 || strlen($digits) > 32) {
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . ($i + 1) . ': invalid number';
                }
                $skipped++;
                continue;
            }
            $note = isset($row['note']) ? mb_substr(trim((string) $row['note']), 0, 255) : null;
            if ($note === '') {
                $note = null;
            }
            if ($mode === 'delete') {
                $st = $this->pdo->prepare('DELETE FROM dnc_numbers WHERE dnc_group_id = ? AND number_digits = ?');
                $st->execute([$groupId, $digits]);
                if ($st->rowCount() > 0) {
                    $ok++;
                    $this->cacheRemove($groupId, [$digits]);
                } else {
                    $skipped++;
                }
                continue;
            }
            $batch[] = ['number' => $digits, 'note' => $note];
            if (count($batch) >= 400) {
                $flushAdd();
            }
        }
        if ($mode === 'add') {
            $flushAdd();
        } elseif ($ok > 0) {
            $this->recount($groupId);
        }
        return ['ok' => $ok, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function isBlocked(int $groupId, string $rawDst): bool
    {
        $digits = Destination::normalize($rawDst);
        if ($digits === '' || $groupId <= 0) {
            return false;
        }
        $g = $this->findGroup($groupId);
        if ($g === null || ($g['status'] ?? '') !== 'active') {
            return false;
        }
        $aliases = self::nanpAliases($digits);
        $redis = RedisClient::fromConfig();
        if ($redis !== null) {
            try {
                $key = self::redisKey($groupId);
                foreach ($aliases as $a) {
                    if ($redis->sismember($key, $a)) {
                        return true;
                    }
                }
                // Fall through to MySQL if set missing (cold cache)
            } catch (\Throwable) {
            }
        }
        $ph = implode(',', array_fill(0, count($aliases), '?'));
        $st = $this->pdo->prepare(
            "SELECT 1 FROM dnc_numbers WHERE dnc_group_id = ? AND number_digits IN ({$ph}) LIMIT 1"
        );
        $st->execute([$groupId, ...$aliases]);
        return (bool) $st->fetchColumn();
    }

    /** Full rebuild of Redis + OpenSIPS dnc_cache for one group (or all). */
    public function rebuildGroupCache(?int $groupId = null): void
    {
        if ($groupId !== null) {
            $groups = [$this->findGroup($groupId)];
            $groups = array_values(array_filter($groups));
        } else {
            $groups = $this->listGroups();
        }
        $this->ensureOpensipsCacheTable();
        foreach ($groups as $g) {
            $gid = (int) $g['id'];
            $active = ($g['status'] ?? '') === 'active';
            $st = $this->pdo->prepare('SELECT number_digits FROM dnc_numbers WHERE dnc_group_id = ?');
            $st->execute([$gid]);
            $nums = array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'number_digits');
            $expanded = [];
            foreach ($nums as $n) {
                foreach (self::nanpAliases((string) $n) as $a) {
                    $expanded[$a] = true;
                }
            }
            $nums = array_keys($expanded);
            $redis = RedisClient::fromConfig();
            if ($redis !== null) {
                try {
                    if (!$active || $nums === []) {
                        $redis->del(self::redisKey($gid));
                    } else {
                        $redis->replaceSet(self::redisKey($gid), $nums);
                    }
                } catch (\Throwable $e) {
                    error_log('[ownswitch] DNC redis rebuild: ' . $e->getMessage());
                }
            }
            $this->replaceOpensipsCache($gid, $active ? $nums : []);
            $this->recount($gid);
        }
    }

    private function dropGroupCache(int $groupId): void
    {
        $redis = RedisClient::fromConfig();
        if ($redis !== null) {
            try {
                $redis->del(self::redisKey($groupId));
            } catch (\Throwable) {
            }
        }
        $this->replaceOpensipsCache($groupId, []);
    }

    /** @param list<string> $digits */
    private function cacheAdd(int $groupId, array $digits): void
    {
        $g = $this->findGroup($groupId);
        if ($g === null || ($g['status'] ?? '') !== 'active' || $digits === []) {
            return;
        }
        $expanded = [];
        foreach ($digits as $d) {
            foreach (self::nanpAliases((string) $d) as $a) {
                $expanded[$a] = true;
            }
        }
        $digits = array_keys($expanded);
        $redis = RedisClient::fromConfig();
        if ($redis !== null) {
            try {
                $redis->sadd(self::redisKey($groupId), $digits);
            } catch (\Throwable $e) {
                error_log('[ownswitch] DNC redis sadd: ' . $e->getMessage());
            }
        }
        $this->ensureOpensipsCacheTable();
        $os = OpensipsProvisioner::fromConfig()->pdo();
        if ($os === null) {
            return;
        }
        $ins = $os->prepare('INSERT IGNORE INTO dnc_cache (group_id, number_digits) VALUES (?,?)');
        foreach ($digits as $d) {
            $ins->execute([$groupId, $d]);
        }
    }

    /** @param list<string> $digits */
    private function cacheRemove(int $groupId, array $digits): void
    {
        if ($digits === []) {
            return;
        }
        $expanded = [];
        foreach ($digits as $d) {
            foreach (self::nanpAliases((string) $d) as $a) {
                $expanded[$a] = true;
            }
        }
        $digits = array_keys($expanded);
        $redis = RedisClient::fromConfig();
        if ($redis !== null) {
            try {
                $redis->srem(self::redisKey($groupId), $digits);
            } catch (\Throwable) {
            }
        }
        $os = OpensipsProvisioner::fromConfig()->pdo();
        if ($os === null) {
            return;
        }
        try {
            $del = $os->prepare('DELETE FROM dnc_cache WHERE group_id = ? AND number_digits = ?');
            foreach ($digits as $d) {
                $del->execute([$groupId, $d]);
            }
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $nums */
    private function replaceOpensipsCache(int $groupId, array $nums): void
    {
        $os = OpensipsProvisioner::fromConfig()->pdo();
        if ($os === null) {
            return;
        }
        try {
            $os->prepare('DELETE FROM dnc_cache WHERE group_id = ?')->execute([$groupId]);
            if ($nums === []) {
                return;
            }
            $ins = $os->prepare('INSERT IGNORE INTO dnc_cache (group_id, number_digits) VALUES (?,?)');
            foreach (array_chunk($nums, 500) as $chunk) {
                foreach ($chunk as $d) {
                    $ins->execute([$groupId, $d]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] DNC opensips cache: ' . $e->getMessage());
        }
    }

    private function ensureOpensipsCacheTable(): void
    {
        $os = OpensipsProvisioner::fromConfig()->pdo();
        if ($os === null) {
            return;
        }
        try {
            $os->exec(
                'CREATE TABLE IF NOT EXISTS dnc_cache (
                    group_id INT UNSIGNED NOT NULL,
                    number_digits VARCHAR(32) NOT NULL,
                    PRIMARY KEY (group_id, number_digits)
                ) ENGINE=InnoDB DEFAULT CHARSET=latin1'
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] DNC ensure opensips table: ' . $e->getMessage());
        }
    }

    private function bumpCount(int $groupId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        if ($delta > 0) {
            $this->pdo->prepare('UPDATE dnc_groups SET number_count = number_count + ? WHERE id = ?')->execute([$delta, $groupId]);
            return;
        }
        $this->pdo->prepare(
            'UPDATE dnc_groups SET number_count = IF(number_count > ?, number_count - ?, 0) WHERE id = ?'
        )->execute([abs($delta), abs($delta), $groupId]);
    }

    private function recount(int $groupId): void
    {
        $this->pdo->prepare(
            'UPDATE dnc_groups g SET number_count = (SELECT COUNT(*) FROM dnc_numbers n WHERE n.dnc_group_id = g.id) WHERE g.id = ?'
        )->execute([$groupId]);
    }

    /**
     * @param list<string> $digits
     * @return array<string,true>
     */
    private function existingDigits(int $groupId, array $digits): array
    {
        if ($digits === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($digits, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare("SELECT number_digits FROM dnc_numbers WHERE dnc_group_id = ? AND number_digits IN ({$in})");
            $st->execute([$groupId, ...$chunk]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(string) $r['number_digits']] = true;
            }
        }
        return $out;
    }

    /** @param list<array{0:int,1:string,2:?string}> $rows */
    private function insertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($rows), '(?,?,?)'));
        $vals = [];
        foreach ($rows as $r) {
            $vals[] = $r[0];
            $vals[] = $r[1];
            $vals[] = $r[2];
        }
        $this->pdo->prepare(
            "INSERT IGNORE INTO dnc_numbers (dnc_group_id, number_digits, note) VALUES {$placeholders}"
        )->execute($vals);
    }
}
