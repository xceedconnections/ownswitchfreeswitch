<?php

declare(strict_types=1);

namespace Ownswitch\Services\Auth;

use Ownswitch\App;
use Ownswitch\Repositories\UserRepository;
use PDO;

final class AuthService
{
    /** Hash verified against when the user does not exist (uniform timing). */
    private static ?string $dummyHash = null;

    private static function dummy(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }

    public function __construct(private UserRepository $users, private SessionService $session, private ?PDO $pdo = null)
    {
    }

    /**
     * @return array{ok:bool,error?:string,user?:array<string,mixed>}  error messages are deliberately generic.
     */
    public function attempt(string $login, string $password, string $ip): array
    {
        $cfg = (array) App::config('security.auth', []);
        $login = trim($login);
        $generic = ['ok' => false, 'error' => 'Invalid username/email or password.'];

        if ($this->ipThrottled($ip, (int) ($cfg['ip_window_minutes'] ?? 15), (int) ($cfg['ip_max_failures'] ?? 30))) {
            return ['ok' => false, 'error' => 'Too many attempts. Please try again later.'];
        }
        $user = $this->users->findByLogin($login);
        if ($user === null) {
            password_verify($password, self::dummy());
            $this->logAttempt($ip, $login, false);
            return $generic;
        }
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until'] . ' UTC') > time()) {
            password_verify($password, self::dummy());
            $this->logAttempt($ip, $login, false);
            return $generic;
        }
        $status = strtolower((string) ($user['status'] ?? ''));
        if ($status !== 'active') {
            password_verify($password, self::dummy());
            $this->logAttempt($ip, $login, false);
            return ['ok' => false, 'error' => 'This account is disabled. Ask an admin to set the user Active and reset the portal password.'];
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->users->recordFailure((int) $user['id'], (int) ($cfg['max_failed_logins'] ?? 5), (int) ($cfg['lockout_minutes'] ?? 15));
            $this->logAttempt($ip, $login, false);
            return $generic;
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->setPasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        $this->users->recordSuccess((int) $user['id'], $ip);
        $this->logAttempt($ip, $login, true);
        $this->session->login((int) $user['id']);   // regenerates session id
        unset($user['password_hash']);
        return ['ok' => true, 'user' => $user];
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /** @return list<string> policy violations */
    public static function passwordProblems(string $password): array
    {
        $min = (int) App::config('security.auth.min_password_length', 12);
        $p = [];
        if (mb_strlen($password) < $min) {
            $p[] = "Password must be at least {$min} characters.";
        }
        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
            $p[] = 'Password must contain upper-case, lower-case letters and a digit.';
        }
        return $p;
    }

    public function changePassword(int $userId, string $current, string $new): array
    {
        $user = $this->users->findWithRole($userId);
        if (!$user || !password_verify($current, (string) $user['password_hash'])) {
            return ['ok' => false, 'errors' => ['Current password is incorrect.']];
        }
        $problems = self::passwordProblems($new);
        if ($problems) {
            return ['ok' => false, 'errors' => $problems];
        }
        $this->users->setPasswordHash($userId, password_hash($new, PASSWORD_DEFAULT));
        $this->session->regenerate();   // privilege-relevant change => new session id
        return ['ok' => true, 'errors' => []];
    }

    /** Record a failed portal attempt (CAPTCHA / throttle) for DB throttle + fail2ban. */
    public function recordPortalFail(string $ip, string $loginHint = ''): void
    {
        $this->logAttempt($ip, $loginHint !== '' ? $loginHint : 'portal', false);
    }

    private function logAttempt(string $ip, string $email, bool $ok): void
    {
        if ($this->pdo !== null) {
            $this->pdo->prepare('INSERT INTO login_attempts (ip, email_hash, success, attempted_at) VALUES (?,?,?,UTC_TIMESTAMP())')
                ->execute([$ip, hash('sha256', $email), $ok ? 1 : 0]);
        }
        // fail2ban jail ownswitch-portal watches syslog for this line
        if (!$ok) {
            $safeIp = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: 'unknown';
            if (function_exists('openlog') && function_exists('syslog')) {
                openlog('ownswitch-portal', LOG_PID | LOG_ODELAY, LOG_AUTH);
                syslog(LOG_WARNING, "Portal auth failed from {$safeIp}");
                closelog();
            }
            error_log("ownswitch-portal: Portal auth failed from {$safeIp}");
        }
    }

    private function ipThrottled(string $ip, int $windowMin, int $max): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)');
        $st->execute([$ip, $windowMin]);
        return (int) $st->fetchColumn() >= $max;
    }
}
