<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class UserRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'users',
            ['email', 'name', 'password_hash', 'role_id', 'reseller_id', 'customer_id', 'status', 'timezone', 'failed_logins', 'locked_until', 'last_login_at', 'last_login_ip'],
            'none',
            ['email', 'name'],
            ['id', 'email', 'name', 'created_at']
        );
    }

    /** @return array<string,mixed>|null user row incl. `role` code */
    public function findWithRole(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1');
        $st->execute([mb_strtolower(trim($email))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Login by email or portal username (`users.name` / customers.Username). */
    public function findByLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $byEmail = $this->findByEmail(mb_strtolower($login));
        if ($byEmail !== null) {
            return $byEmail;
        }
        $st = $this->pdo->prepare(
            'SELECT u.*, r.code AS role FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE LOWER(u.name) = LOWER(?) LIMIT 1'
        );
        $st->execute([$login]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function roleId(string $code): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM roles WHERE code = ?');
        $st->execute([$code]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public function recordFailure(int $userId, int $max, int $lockMinutes): void
    {
        $this->pdo->prepare(
            'UPDATE users SET failed_logins = failed_logins + 1,
                locked_until = CASE WHEN failed_logins + 1 >= ? THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) ELSE locked_until END
             WHERE id = ?'
        )->execute([$max, $lockMinutes, $userId]);
    }

    public function recordSuccess(int $userId, string $ip): void
    {
        $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')
            ->execute([$ip, $userId]);
    }

    public function setPasswordHash(int $userId, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    }

    /** Portal login row for a customer (customer_owner), if any. */
    public function findPortalByCustomerId(int $customerId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT u.*, r.code AS role FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.customer_id = ? AND r.code IN ('customer_owner','customer_tech')
             ORDER BY CASE r.code WHEN 'customer_owner' THEN 0 ELSE 1 END, u.id
             LIMIT 1"
        );
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Create or update the customer portal login (email + password).
     *
     * @return array{ok:bool,detail:string,user_id?:int}
     */
    public function upsertCustomerPortal(
        int $customerId,
        string $email,
        string $displayName,
        ?string $plainPassword,
        ?int $resellerId,
        string $status = 'active',
    ): array {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'detail' => 'A valid email is required for portal login.'];
        }
        $roleId = $this->roleId('customer_owner');
        if ($roleId === null) {
            return ['ok' => false, 'detail' => 'customer_owner role missing — run migrations.'];
        }

        $existing = $this->findPortalByCustomerId($customerId);
        $byEmail = $this->findByEmail($email);
        if ($byEmail !== null && ($existing === null || (int) $byEmail['id'] !== (int) $existing['id'])) {
            return ['ok' => false, 'detail' => 'That email is already used by another portal login.'];
        }

        $portalStatus = in_array($status, ['active', 'suspended', 'closed'], true)
            ? ($status === 'active' ? 'active' : 'disabled')
            : 'active';

        if ($existing === null) {
            if ($plainPassword === null || $plainPassword === '') {
                return ['ok' => false, 'detail' => 'Set a portal password so the user can sign in.'];
            }
            $problems = \Ownswitch\Services\Auth\AuthService::passwordProblems($plainPassword);
            if ($problems) {
                return ['ok' => false, 'detail' => $problems[0]];
            }
            $id = $this->insert([
                'email' => $email,
                'name' => mb_substr($displayName !== '' ? $displayName : $email, 0, 120),
                'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                'role_id' => $roleId,
                'reseller_id' => $resellerId,
                'customer_id' => $customerId,
                'status' => $portalStatus,
                'failed_logins' => 0,
                'locked_until' => null,
            ]);
            return ['ok' => true, 'detail' => 'Portal login created. Sign in at /login with this email + password.', 'user_id' => $id];
        }

        $upd = [
            'email' => $email,
            'name' => mb_substr($displayName !== '' ? $displayName : $email, 0, 120),
            'reseller_id' => $resellerId,
            'status' => $portalStatus,
        ];
        $this->updateInternal((int) $existing['id'], $upd);
        if ($plainPassword !== null && $plainPassword !== '') {
            $problems = \Ownswitch\Services\Auth\AuthService::passwordProblems($plainPassword);
            if ($problems) {
                return ['ok' => false, 'detail' => $problems[0]];
            }
            // Admin reset: always clear lockouts so the new password works immediately.
            $this->pdo->prepare(
                'UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?'
            )->execute([password_hash($plainPassword, PASSWORD_DEFAULT), (int) $existing['id']]);
            return ['ok' => true, 'detail' => 'Portal login and password updated. Sign in at /login with this email + password.', 'user_id' => (int) $existing['id']];
        }
        return ['ok' => true, 'detail' => 'Portal login updated.', 'user_id' => (int) $existing['id']];
    }
}
