<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use PDO;

/** How the dialled destination is rewritten before send (SIP user and/or trunk). */
final class DstFormat
{
    public const AS_IS = 'as_is';
    public const E164_PLUS = 'e164_plus';
    public const E164_DIGITS = 'e164_digits';

    /** @var array<string,string> */
    public const OPTIONS = [
        self::AS_IS => 'As dialed (no change)',
        self::E164_PLUS => 'E.164 with +  (12135550123 → +12135550123)',
        self::E164_DIGITS => 'E.164 digits only  (+12135550123 → 12135550123)',
    ];

    public static function normalize(?string $raw): string
    {
        $v = strtolower(trim((string) $raw));
        return isset(self::OPTIONS[$v]) ? $v : self::AS_IS;
    }

    /** Short code stored in OpenSIPS attrs / subscriber (plus|digits|as_is). */
    public static function wireCode(string $format): string
    {
        return match (self::normalize($format)) {
            self::E164_PLUS => 'plus',
            self::E164_DIGITS => 'digits',
            default => 'as_is',
        };
    }

    /** Idempotent ALTER for portal Update when migrate was skipped. */
    public static function ensureColumns(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        foreach (['sip_accounts', 'carrier_trunks'] as $table) {
            try {
                $st = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . " AND COLUMN_NAME = 'dst_format'"
                );
                if ((int) $st->fetchColumn() === 0) {
                    try {
                        $pdo->exec(
                            "ALTER TABLE `{$table}` ADD COLUMN dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"
                        );
                    } catch (\Throwable $e) {
                        error_log('[ownswitch] dst_format ensure ' . $table . ': ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log('[ownswitch] dst_format ensure ' . $table . ': ' . $e->getMessage());
            }
        }
        $done = true;
    }
}
