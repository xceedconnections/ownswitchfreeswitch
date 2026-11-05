<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Human labels for common SIP response codes (VICIdial / Asterisk style). */
final class SipCode
{
    private const MAP = [
        100 => 'Trying',
        180 => 'Ringing',
        183 => 'Session Progress',
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        408 => 'Request Timeout',
        480 => 'Temporarily Unavailable',
        482 => 'Loop Detected',
        483 => 'Too Many Hops',
        484 => 'Address Incomplete',
        486 => 'Busy Here',
        487 => 'Request Terminated',
        488 => 'Not Acceptable Here',
        500 => 'Server Internal Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Server Time-out',
        600 => 'Busy Everywhere',
        603 => 'Decline',
        604 => 'Does Not Exist Anywhere',
        606 => 'Not Acceptable',
    ];

    /** Asterisk-style short tags for CDR lists. */
    private const SHORT = [
        486 => 'BUSY',
        480 => 'CHANUNAVAIL',
        503 => 'CONGESTION',
        408 => 'NOANSWER',
        487 => 'CANCEL',
        403 => 'FORBIDDEN',
        404 => 'NOTFOUND',
        500 => 'SERVERERROR',
        502 => 'BADGATEWAY',
        504 => 'TIMEOUT',
        600 => 'BUSY',
        603 => 'DECLINE',
    ];

    public static function label(int $code): string
    {
        if ($code <= 0) {
            return '—';
        }
        $name = self::MAP[$code] ?? 'SIP';
        $short = self::SHORT[$code] ?? null;
        if ($short !== null) {
            return $short . ' ' . $code . ' (' . $name . ')';
        }
        return $code . ' ' . $name;
    }

    public static function short(int $code): string
    {
        if ($code <= 0) {
            return '—';
        }
        return (self::SHORT[$code] ?? 'SIP') . ' ' . $code;
    }
}
