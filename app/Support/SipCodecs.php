<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Canonical SIP codec catalogue for trunk multi-select (Magnus-style). */
final class SipCodecs
{
    /** value => label */
    public const OPTIONS = [
        'G729' => 'g729',
        'G723' => 'g723',
        'GSM' => 'gsm',
        'G726' => 'g726',
        'OPUS' => 'opus',
        'iLBC' => 'ilbc',
        'PCMA' => 'alaw',
        'PCMU' => 'ulaw',
        'G722' => 'g722',
        'SPEEX' => 'speex',
        'H261' => 'h261',
        'H263' => 'h263',
        'H263P' => 'h263p',
        'H264' => 'h264',
        'VP8' => 'vp8',
        'G729A' => 'g729a',
        'AMR' => 'amr',
        'AMR-WB' => 'amr-wb',
        'telephone-event' => 'telephone-event',
    ];

    /** @var array<string,string> lowercase alias => canonical */
    private const ALIASES = [
        'g711' => 'PCMU',
        'g.711' => 'PCMU',
        'ulaw' => 'PCMU',
        'u-law' => 'PCMU',
        'alaw' => 'PCMA',
        'a-law' => 'PCMA',
        'g711u' => 'PCMU',
        'g711a' => 'PCMA',
        'pcmu' => 'PCMU',
        'pcma' => 'PCMA',
        'g729' => 'G729',
        'g.729' => 'G729',
        'g729a' => 'G729A',
        'g723' => 'G723',
        'g722' => 'G722',
        'g726' => 'G726',
        'gsm' => 'GSM',
        'opus' => 'OPUS',
        'speex' => 'SPEEX',
        'ilbc' => 'iLBC',
        'h261' => 'H261',
        'h263' => 'H263',
        'h263p' => 'H263P',
        'h264' => 'H264',
        'vp8' => 'VP8',
        'amr' => 'AMR',
        'amr-wb' => 'AMR-WB',
        'amrwb' => 'AMR-WB',
        'telephone-event' => 'telephone-event',
        'rfc2833' => 'telephone-event',
        'dtmf' => 'telephone-event',
    ];

    /** @return list<string> */
    public static function normalizeList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;|]+/', (string) $raw) ?: [];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $key = strtolower($p);
            $canon = self::ALIASES[$key] ?? (isset(self::OPTIONS[$p]) ? $p : null);
            if ($canon === null) {
                // allow exact canonical case-insensitive match
                foreach (self::OPTIONS as $code => $_) {
                    if (strcasecmp($code, $p) === 0) {
                        $canon = $code;
                        break;
                    }
                }
            }
            if ($canon !== null && !in_array($canon, $out, true)) {
                $out[] = $canon;
            }
        }
        return $out;
    }

    public static function toStorage(mixed $raw): ?string
    {
        $list = self::normalizeList($raw);
        return $list === [] ? null : implode(',', $list);
    }
}
