<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use PDO;

/**
 * Internal unique codes are auto-generated from the friendly name so operators
 * never have to invent Magnus-style "codes" by hand.
 */
final class EntityCode
{
    public static function fromName(string $name, int $max = 28): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'ITEM';
        }
        if (strlen($slug) > $max) {
            $slug = rtrim(substr($slug, 0, $max), '-');
        }
        if (strlen($slug) < 2) {
            $slug .= 'X';
        }
        return $slug;
    }

    /** Ensure uniqueness against an existing table.code column. */
    public static function unique(PDO $db, string $table, string $base, ?int $ignoreId = null): string
    {
        $candidate = $base;
        $n = 2;
        while (self::exists($db, $table, $candidate, $ignoreId)) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, max(2, 32 - strlen($suffix))) . $suffix;
            $n++;
            if ($n > 9999) {
                $candidate = substr($base, 0, 20) . '-' . bin2hex(random_bytes(4));
                break;
            }
        }
        return $candidate;
    }

    private static function exists(PDO $db, string $table, string $code, ?int $ignoreId): bool
    {
        $allowed = ['customers', 'resellers', 'carriers', 'rate_plans', 'routing_plans'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported table for EntityCode');
        }
        if ($ignoreId !== null) {
            $st = $db->prepare("SELECT 1 FROM {$table} WHERE code = ? AND id <> ? LIMIT 1");
            $st->execute([$code, $ignoreId]);
        } else {
            $st = $db->prepare("SELECT 1 FROM {$table} WHERE code = ? LIMIT 1");
            $st->execute([$code]);
        }
        return (bool) $st->fetchColumn();
    }
}
