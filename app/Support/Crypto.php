<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use Ownswitch\App;

/** AES-256-GCM helper for reversible secrets (carrier trunk credentials). Key = APP_KEY (hex). */
final class Crypto
{
    private static function key(): string
    {
        $k = (string) App::config('app.key', '');
        if ($k === '' || strlen($k) < 32) {
            throw new \RuntimeException('APP_KEY is not configured (need >= 32 chars).');
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($pt === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $pt;
    }
}
