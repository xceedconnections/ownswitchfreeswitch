<?php

declare(strict_types=1);

/**
 * Create a portal user (default role: super_admin).
 *   php bin/create_admin.php --email=admin@example.com --name="Admin"
 *   php bin/create_admin.php --email=a@b.c --password="Str0ngPassw0rd!" --role=admin
 *   php bin/create_admin.php --email=c@d.e --role=customer_owner --customer=3
 * Without --password a strong random password is generated and printed once.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Services\Auth\AuthService;
use Ownswitch\Support\TenantScope;

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $a, $m)) {
        $o[$m[1]] = $m[2];
    }
}
$prompt = static function (string $q): string {
    echo $q;
    return trim((string) fgets(STDIN));
};

$email = mb_strtolower($o['email'] ?? $prompt('Email: '));
$name = $o['name'] ?? ($email !== '' ? explode('@', $email)[0] : 'Admin');
$role = $o['role'] ?? 'super_admin';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(2);
}
if (!in_array($role, TenantScope::ROLES, true)) {
    fwrite(STDERR, 'Role must be one of: ' . implode(', ', TenantScope::ROLES) . "\n");
    exit(2);
}
$reseller = isset($o['reseller']) && ctype_digit($o['reseller']) ? (int) $o['reseller'] : null;
$customer = isset($o['customer']) && ctype_digit($o['customer']) ? (int) $o['customer'] : null;
if (in_array($role, ['customer_owner', 'customer_tech'], true) && $customer === null) {
    fwrite(STDERR, "--customer=ID is required for customer roles.\n");
    exit(2);
}
if ($role === 'reseller' && $reseller === null) {
    fwrite(STDERR, "--reseller=ID is required for the reseller role.\n");
    exit(2);
}
$generated = false;
$password = $o['password'] ?? null;
if ($password === null) {
    $password = bin2hex(random_bytes(8)) . 'Aa1!';
    $generated = true;
} elseif ($problems = AuthService::passwordProblems($password)) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(2);
}

try {
    $users = new UserRepository(App::db());
    if ($users->findByEmail($email) !== null) {
        fwrite(STDERR, "A user with this email already exists.\n");
        exit(1);
    }
    $id = $users->insert([
        'email' => $email, 'name' => mb_substr($name, 0, 120), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $users->roleId($role), 'reseller_id' => $reseller, 'customer_id' => $customer, 'status' => 'active',
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\nHave you run php bin/migrate.php?\n");
    exit(1);
}
echo "Created {$role} #{$id}: {$email}\n";
if ($generated) {
    echo "Generated password (shown once): {$password}\n";
}
