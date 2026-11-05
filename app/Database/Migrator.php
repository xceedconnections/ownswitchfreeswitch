<?php

declare(strict_types=1);

namespace Ownswitch\Database;

use PDO;

/** Forward-only SQL migration runner. Files: database/migrations/NNN_name.sql */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Prefer client-side prepares + buffered results for migration SQL (user vars, PREPARE/EXECUTE). */
    private function tuneForMigrations(): void
    {
        try {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        } catch (\Throwable) {
        }
        try {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        } catch (\Throwable) {
        }
    }

    /** @return list<string> file names sorted */
    public function files(): array
    {
        $files = glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,string> migration => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        $st = $this->pdo->query('SELECT migration, checksum FROM schema_migrations');
        if ($st === false) {
            return [];
        }
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $st->closeCursor();
        return $rows;
    }

    /** @return list<array{migration:string,status:string}> */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $f) {
            $sum = hash_file('sha256', $this->dir . '/' . $f);
            $st = !isset($applied[$f]) ? 'pending' : ($applied[$f] === $sum ? 'applied' : 'applied (file changed!)');
            $out[] = ['migration' => $f, 'status' => $st];
        }
        return $out;
    }

    /**
     * Apply pending migrations. MySQL DDL auto-commits, so migrations are written to be
     * re-runnable (IF NOT EXISTS / INSERT IGNORE); a file is recorded only after full success.
     *
     * @return list<string> applied now
     */
    public function migrate(?callable $log = null): array
    {
        $this->tuneForMigrations();
        $applied = $this->applied();
        $done = [];
        foreach ($this->files() as $f) {
            if (isset($applied[$f])) {
                continue;
            }
            $path = $this->dir . '/' . $f;
            $sql = (string) file_get_contents($path);
            try {
                foreach (self::splitStatements($sql) as $stmt) {
                    try {
                        $this->runStatement(self::scrubMysqlReservedIds($stmt));
                    } catch (\PDOException $e) {
                        // Idempotent DDL: column/key/table already exists from a partial prior run.
                        $msg = $e->getMessage();
                        if (str_contains($msg, 'Duplicate column')
                            || str_contains($msg, 'Duplicate key name')
                            || str_contains($msg, 'already exists')
                            // Ancient 015 copies: unquoted qualify (MySQL 8 reserved) — skip; 018 adds it.
                            || (stripos($msg, 'qualify') !== false && str_contains($msg, '1064'))) {
                            continue;
                        }
                        throw $e;
                    }
                }
            } catch (\PDOException $e) {
                // Ledger triggers need TRIGGER privilege — skip recording so a privileged re-run can apply later,
                // but do not block unrelated later migrations when this is the ledger guard file.
                if (str_contains($f, 'ledger_guard')) {
                    if ($log) {
                        $log($f . ' SKIPPED (' . $e->getMessage() . ')');
                    }
                    continue;
                }
                throw $e;
            }
            $ins = $this->pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
            $ins->execute([$f, hash('sha256', $sql)]);
            $ins->closeCursor();
            $done[] = $f;
            if ($log) {
                $log($f);
            }
        }
        return $done;
    }

    /**
     * MySQL 8 reserves QUALIFY. Old migration copies used unquoted `ADD COLUMN qualify` inside
     * PREPARE strings; rewrite to backticked identifiers before execute.
     */
    public static function scrubMysqlReservedIds(string $sql): string
    {
        $sql = preg_replace('/\bADD\s+COLUMN\s+qualify\b/i', 'ADD COLUMN `qualify`', $sql) ?? $sql;
        $sql = preg_replace('/\bAFTER\s+qualify\b/i', 'AFTER `qualify`', $sql) ?? $sql;
        return $sql;
    }

    /**
     * Run one SQL statement and fully drain any result sets.
     * PREPARE/EXECUTE "SELECT 1" and bare SELECTs leave the connection busy (HY000/2014) if undrained.
     */
    private function runStatement(string $sql): void
    {
        $trim = ltrim($sql);
        // Prefer exec for pure DDL/DML that never returns rows (avoids leftover cursors).
        if (preg_match('/^(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|SET\s+@|SET\s+NAMES|SET\s+time_zone|DO\s|PREPARE\s|DEALLOCATE\s)/i', $trim)
            && !preg_match('/\bSELECT\b/i', $trim)
            && !preg_match('/^EXECUTE\b/i', $trim)
        ) {
            $this->pdo->exec($sql);
            return;
        }

        $st = $this->pdo->query($sql);
        if (!($st instanceof \PDOStatement)) {
            return;
        }
        try {
            $st->fetchAll();
            while ($st->nextRowset()) {
                $st->fetchAll();
            }
        } catch (\PDOException) {
            // Some drivers error on nextRowset() when there is no further set — cursor still closed below.
        } finally {
            $st->closeCursor();
        }
    }

    /**
     * Split SQL on ';' outside quotes and comments. No DELIMITER support (triggers must be
     * single-statement bodies).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if ($quote === null) {
                if ($c === '-' && $n === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n")) {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    $buf .= "\n";
                    continue;
                }
                if ($c === '#') {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($c === '/' && $n === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i = $end === false ? $len : $end + 1;
                    continue;
                }
                // Only ' and " are string quotes. Backticks are MySQL identifiers — must stay in the
                // buffer so ADD COLUMN `qualify` is not corrupted by quote-state handling.
                if ($c === "'" || $c === '"') {
                    $quote = $c;
                    $buf .= $c;
                    continue;
                }
                if ($c === ';') {
                    if (trim($buf) !== '') {
                        $out[] = trim($buf);
                    }
                    $buf = '';
                    continue;
                }
                $buf .= $c;
            } else {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $n;
                    $i++;
                } elseif ($c === $quote) {
                    if ($n === $quote) {
                        $buf .= $n;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
