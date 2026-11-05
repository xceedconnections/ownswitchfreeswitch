#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Deploy-compatible admin creator (hyphenated name expected by deploy/stages/90_app_deploy.sh).
 *
 * Exit codes: 0 created | 10 already exists | 2 validation | 1 other failure
 *
 *   php bin/create-admin.php --email=ops@example.com --password-stdin
 *   php bin/create-admin.php --email=ops@example.com --password='...'
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Services\Auth\AuthService;
use Ownswitch\Support\TenantScope;

$flags = [];
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--password-stdin' || $a === '--force-password-change') {
        $flags[$a] = true;
        continue;
    }
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/s', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$email = mb_strtolower(trim((string) ($opts['email'] ?? '')));
$name = (string) ($opts['name'] ?? ($email !== '' ? explode('@', $email)[0] : 'Admin'));
$role = (string) ($opts['role'] ?? 'super_admin');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid or missing --email\n");
    exit(2);
}
if (!in_array($role, TenantScope::ROLES, true)) {
    fwrite(STDERR, 'Role must be one of: ' . implode(', ', TenantScope::ROLES) . "\n");
    exit(2);
}

$password = $opts['password'] ?? null;
if (isset($flags['--password-stdin'])) {
    $raw = stream_get_contents(STDIN);
    $password = is_string($raw) ? rtrim($raw, "\r\n") : '';
}
if ($password === null || $password === '') {
    fwrite(STDERR, "Password required via --password= or --password-stdin\n");
    exit(2);
}
if ($problems = AuthService::passwordProblems($password)) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(2);
}

try {
    $users = new UserRepository(App::db());
    if ($users->findByEmail($email) !== null) {
        fwrite(STDERR, "A user with this email already exists.\n");
        exit(10);
    }
    $id = $users->insert([
        'email' => $email,
        'name' => mb_substr($name, 0, 120),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $users->roleId($role),
        'reseller_id' => null,
        'customer_id' => null,
        'status' => 'active',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (isset($flags['--force-password-change'])) {
    fwrite(STDERR, "Note: --force-password-change accepted for installers; force flag is not persisted in this schema.\n");
}

echo "Created {$role} #{$id}: {$email}\n";
exit(0);
