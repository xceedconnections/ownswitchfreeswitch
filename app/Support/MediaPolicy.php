<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use Ownswitch\Services\Settings\SettingsService;
use PDO;

/**
 * Resolve effective media policy. Trunk/customer value of null / '' / 'default'
 * always follows Settings → Default media policy (live — no per-row copy).
 */
final class MediaPolicy
{
    public const CHOICES = ['direct', 'relay', 'record'];

    public static function isDefault(?string $stored): bool
    {
        $v = strtolower(trim((string) $stored));
        return $v === '' || $v === 'default' || $v === 'inherit';
    }

    public static function normalizeStored(?string $raw): ?string
    {
        $v = strtolower(trim((string) $raw));
        if ($v === '' || $v === 'default' || $v === 'inherit') {
            return null; // NULL = follow Settings default
        }
        return in_array($v, self::CHOICES, true) ? $v : null;
    }

    public static function resolve(?string $stored, ?PDO $pdo = null): string
    {
        if (!self::isDefault($stored)) {
            $v = strtolower(trim((string) $stored));
            if (in_array($v, self::CHOICES, true)) {
                return $v;
            }
        }
        if ($pdo !== null) {
            $fromSettings = (new SettingsService($pdo))->get('default_media_policy', 'relay');
            if (in_array((string) $fromSettings, self::CHOICES, true)) {
                return (string) $fromSettings;
            }
        }
        $cfg = \Ownswitch\App::config('app.defaults.media_policy', 'relay');
        $cfg = is_string($cfg) ? $cfg : 'relay';
        return in_array($cfg, self::CHOICES, true) ? $cfg : 'relay';
    }
}
