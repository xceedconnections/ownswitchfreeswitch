<?php

declare(strict_types=1);

/**
 * Usage:
 *   php bin/migrate.php                 apply pending migrations
 *   php bin/migrate.php --status        list migration status
 *   php bin/migrate.php --create-db     CREATE DATABASE IF NOT EXISTS (non-destructive) first
 *   php bin/migrate.php --seed          also run the DEMO seeder (labelled demo data; idempotent)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Database\Connection;
use Ownswitch\Database\Migrator;

$opts = array_flip(array_slice($argv, 1));
$cfg = (array) App::config('database.default');

try {
    if (isset($opts['--create-db'])) {
        $name = (string) $cfg['database'];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            fwrite(STDERR, "Refusing to create database with unsafe name.\n");
            exit(2);
        }
        $server = Connection::make($cfg, false);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Database `{$name}` ready.\n";
    }
    $pdo = App::db();
    // Migration SQL uses MySQL user-variables + PREPARE/EXECUTE; native prepares leave unbuffered
    // result sets (HY000/2014). Force emulated + buffered for the CLI migrator only.
    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    } catch (\Throwable) {
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . "\nCheck config/.env (copy config/.env.example) and that MySQL is running. Use --create-db to create the database.\n");
    exit(1);
}

$m = new Migrator($pdo, App::basePath('database/migrations'));

if (isset($opts['--status'])) {
    foreach ($m->status() as $s) {
        printf("  %-40s %s\n", $s['migration'], $s['status']);
    }
    exit(0);
}

try {
    $applied = $m->migrate(static fn (string $f) => print("  applied {$f}\n"));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\nFix the cause and re-run; completed files are not repeated.\n");
    exit(1);
}
echo $applied ? count($applied) . " migration(s) applied.\n" : "Nothing to migrate; schema is up to date.\n";

if (isset($opts['--seed'])) {
    require_once App::basePath('database/seeders/DemoSeeder.php');
    (new \Ownswitch\Seeders\DemoSeeder($pdo))->run(static fn (string $l) => print("  {$l}\n"));
}
