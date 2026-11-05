<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use Ownswitch\App;

/** Session math CAPTCHA for portal login (no third-party JS / CSP changes). */
final class LoginCaptcha
{
    private const KEY = '_login_captcha';

    /** @return array{a:int,b:int,token:string} */
    public static function issue(): array
    {
        App::session()->start();
        $a = random_int(2, 12);
        $b = random_int(1, 9);
        $token = bin2hex(random_bytes(8));
        $_SESSION[self::KEY] = [
            'sum' => $a + $b,
            'token' => $token,
            'exp' => time() + 600,
        ];
        return ['a' => $a, 'b' => $b, 'token' => $token];
    }

    public static function verify(?string $answer, ?string $token): bool
    {
        App::session()->start();
        $stored = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);
        if (!is_array($stored) || !isset($stored['sum'], $stored['token'], $stored['exp'])) {
            return false;
        }
        if ((int) $stored['exp'] < time()) {
            return false;
        }
        if (!is_string($token) || !hash_equals((string) $stored['token'], $token)) {
            return false;
        }
        $ans = is_string($answer) ? trim($answer) : '';
        if ($ans === '' || !preg_match('/^-?\d{1,3}$/', $ans)) {
            return false;
        }
        return (int) $ans === (int) $stored['sum'];
    }
}
