<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** User timezone helpers — storage stays UTC; display/filters convert. */
final class Timezone
{
    /** Stored on a user when they follow Settings → System timezone. */
    public const FOLLOW_SYSTEM = 'system';

    /**
     * City names for the profile and system-settings pickers.
     *
     * @var array<string,array<string,string>>
     */
    public const GROUPS = [
        'Universal' => [
            'UTC' => 'UTC',
        ],
        'Americas' => [
            'America/New_York' => 'New York (US Eastern)',
            'America/Chicago' => 'Chicago (US Central)',
            'America/Denver' => 'Denver (US Mountain)',
            'America/Los_Angeles' => 'Los Angeles (US Pacific)',
            'America/Toronto' => 'Toronto',
            'America/Mexico_City' => 'Mexico City',
            'America/Sao_Paulo' => 'São Paulo',
        ],
        'Europe' => [
            'Europe/London' => 'London (UK)',
            'Europe/Dublin' => 'Dublin',
            'Europe/Paris' => 'Paris',
            'Europe/Berlin' => 'Berlin',
            'Europe/Madrid' => 'Madrid',
            'Europe/Rome' => 'Rome',
            'Europe/Amsterdam' => 'Amsterdam',
            'Europe/Moscow' => 'Moscow',
        ],
        'Asia' => [
            'Asia/Karachi' => 'Karachi (Pakistan)',
            'Asia/Dubai' => 'Dubai',
            'Asia/Kolkata' => 'Kolkata (India)',
            'Asia/Dhaka' => 'Dhaka',
            'Asia/Bangkok' => 'Bangkok',
            'Asia/Singapore' => 'Singapore',
            'Asia/Hong_Kong' => 'Hong Kong',
            'Asia/Shanghai' => 'Shanghai',
            'Asia/Tokyo' => 'Tokyo',
            'Asia/Seoul' => 'Seoul',
        ],
        'Africa' => [
            'Africa/Cairo' => 'Cairo',
            'Africa/Johannesburg' => 'Johannesburg',
            'Africa/Lagos' => 'Lagos',
        ],
        'Pacific' => [
            'Australia/Sydney' => 'Sydney',
            'Australia/Melbourne' => 'Melbourne',
            'Pacific/Auckland' => 'Auckland',
        ],
    ];

    /** @var array<string,string> */
    public const OPTIONS = [
        'UTC' => 'UTC',
        'America/New_York' => 'New York (US Eastern)',
        'America/Chicago' => 'Chicago (US Central)',
        'America/Denver' => 'Denver (US Mountain)',
        'America/Los_Angeles' => 'Los Angeles (US Pacific)',
        'America/Toronto' => 'Toronto',
        'America/Mexico_City' => 'Mexico City',
        'America/Sao_Paulo' => 'São Paulo',
        'Europe/London' => 'London (UK)',
        'Europe/Dublin' => 'Dublin',
        'Europe/Paris' => 'Paris',
        'Europe/Berlin' => 'Berlin',
        'Europe/Madrid' => 'Madrid',
        'Europe/Rome' => 'Rome',
        'Europe/Amsterdam' => 'Amsterdam',
        'Europe/Moscow' => 'Moscow',
        'Asia/Karachi' => 'Karachi (Pakistan)',
        'Asia/Dubai' => 'Dubai',
        'Asia/Kolkata' => 'Kolkata (India)',
        'Asia/Dhaka' => 'Dhaka',
        'Asia/Bangkok' => 'Bangkok',
        'Asia/Singapore' => 'Singapore',
        'Asia/Hong_Kong' => 'Hong Kong',
        'Asia/Shanghai' => 'Shanghai',
        'Asia/Tokyo' => 'Tokyo',
        'Asia/Seoul' => 'Seoul',
        'Africa/Cairo' => 'Cairo',
        'Africa/Johannesburg' => 'Johannesburg',
        'Africa/Lagos' => 'Lagos',
        'Australia/Sydney' => 'Sydney',
        'Australia/Melbourne' => 'Melbourne',
        'Pacific/Auckland' => 'Auckland',
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

    /** Zone used for this login: the profile choice, or the system setting when they follow it. */
    public static function fromUser(?array $user): string
    {
        $raw = is_array($user) ? trim((string) ($user['timezone'] ?? '')) : '';
        if ($raw === '' || strcasecmp($raw, self::FOLLOW_SYSTEM) === 0) {
            return self::systemDefault();
        }
        return self::normalize($raw);
    }

    /** Value stored on the user row (`system` or an IANA zone). */
    public static function stored(?array $user): string
    {
        $raw = is_array($user) ? trim((string) ($user['timezone'] ?? '')) : '';
        if ($raw === '' || strcasecmp($raw, self::FOLLOW_SYSTEM) === 0) {
            return self::FOLLOW_SYSTEM;
        }
        return self::normalize($raw);
    }

    public static function systemDefault(): string
    {
        try {
            $v = (new \Ownswitch\Services\Settings\SettingsService(\Ownswitch\App::db()))->get('system_timezone', 'UTC');
            return self::normalize(is_string($v) ? $v : 'UTC');
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    public static function label(string $tz): string
    {
        $tz = self::normalize($tz);
        return self::OPTIONS[$tz] ?? str_replace('_', ' ', $tz);
    }

    /** UTC instant of local midnight on $ymd (Y-m-d) in $tz. */
    public static function localMidnightUtc(string $ymd, string $tz): string
    {
        return self::localDayRangeToUtc($ymd, $ymd, $tz)[0];
    }

    /** Monday 00:00 in $tz, as a UTC datetime. */
    public static function startOfWeekUtc(string $tz): string
    {
        $zone = new \DateTimeZone(self::normalize($tz));
        $now = new \DateTimeImmutable('now', $zone);
        $dow = (int) $now->format('N');
        $monday = $now->setTime(0, 0, 0)->modify('-' . ($dow - 1) . ' days');
        return $monday->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** First of this month 00:00 in $tz, as a UTC datetime. */
    public static function startOfMonthUtc(string $tz): string
    {
        $zone = new \DateTimeZone(self::normalize($tz));
        $now = new \DateTimeImmutable('now', $zone);
        $first = $now->modify('first day of this month')->setTime(0, 0, 0);
        return $first->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Seconds to add to a UTC timestamp so DATE() lands on the local calendar day. */
    public static function offsetSeconds(string $tz): int
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::normalize($tz))))->getOffset();
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
