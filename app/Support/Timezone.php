<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** User timezone helpers — storage stays UTC; display/filters convert. */
final class Timezone
{
    /** Common IANA zones for the profile picker. */
    public const OPTIONS = [
        'UTC' => 'UTC',
        'America/New_York' => 'America/New York (EST/EDT)',
        'America/Chicago' => 'America/Chicago (CST/CDT)',
        'America/Denver' => 'America/Denver (MST/MDT)',
        'America/Los_Angeles' => 'America/Los Angeles (PST/PDT)',
        'America/Toronto' => 'America/Toronto',
        'Europe/London' => 'Europe/London',
        'Europe/Paris' => 'Europe/Paris',
        'Europe/Berlin' => 'Europe/Berlin',
        'Asia/Karachi' => 'Asia/Karachi (PKT)',
        'Asia/Dubai' => 'Asia/Dubai',
        'Asia/Kolkata' => 'Asia/Kolkata (IST)',
        'Asia/Singapore' => 'Asia/Singapore',
        'Asia/Tokyo' => 'Asia/Tokyo',
        'Asia/Shanghai' => 'Asia/Shanghai',
        'Australia/Sydney' => 'Australia/Sydney',
        'Pacific/Auckland' => 'Pacific/Auckland',
    ];

    public static function normalize(?string $tz): string
    {
        $tz = trim((string) $tz);
        if ($tz === '') {
            return 'UTC';
        }
        try {
            new \DateTimeZone($tz);
            return $tz;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    public static function fromUser(?array $user): string
    {
        return self::normalize(is_array($user) ? (string) ($user['timezone'] ?? 'UTC') : 'UTC');
    }

    /** Format a UTC datetime string for display in $tz. */
    public static function format(?string $utc, string $tz, string $format = 'Y-m-d H:i:s'): string
    {
        if ($utc === null || trim($utc) === '') {
            return '—';
        }
        $raw = trim($utc);
        // Accept "Y-m-d H:i:s" or with fractional seconds
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            return $raw;
        }
        try {
            $dt = new \DateTimeImmutable(substr($raw, 0, 19), new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone(self::normalize($tz)))->format($format);
        } catch (\Throwable) {
            return substr($raw, 0, 19);
        }
    }

    /**
     * Convert a local calendar day range (Y-m-d in user TZ) to UTC bounds for SQL.
     *
     * @return array{0:string,1:string} UTC datetimes [fromInclusive, toExclusive)
     */
    public static function localDayRangeToUtc(string $fromYmd, string $toYmd, string $tz): array
    {
        $tz = self::normalize($tz);
        $zone = new \DateTimeZone($tz);
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromYmd, $zone)
            ?: new \DateTimeImmutable('today', $zone);
        $toExclusive = (\DateTimeImmutable::createFromFormat('!Y-m-d', $toYmd, $zone)
            ?: $from)->modify('+1 day');
        return [
            $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $toExclusive->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /** Today's Y-m-d in user timezone. */
    public static function today(string $tz): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::normalize($tz))))->format('Y-m-d');
    }

    public static function daysAgo(string $tz, int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::normalize($tz))))
            ->modify("-{$days} days")->format('Y-m-d');
    }
}
