<?php

declare(strict_types=1);

namespace Ownswitch\Database;

use PDO;

final class Connection
{
    /**
     * @param array<string,mixed> $cfg host,port,database,username,password,charset
     */
    public static function make(array $cfg, bool $withDatabase = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $withDatabase ? 'dbname=' . ($cfg['database'] ?? '') . ';' : '',
            $cfg['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements (SQLi hardening)
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // Migrations use SELECT/EXECUTE that return rows; unbuffered mode causes HY000/2014.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");       // all event times stored in UTC
        $pdo->exec("SET NAMES utf8mb4");
        return $pdo;
    }
}
