<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use Ownswitch\Services\Settings\SettingsService;
use PDO;

/** Canonical SIP codec catalogue + FreeSWITCH absolute_codec_string helpers. */
final class SipCodecs
{
    /** value => short FreeSWITCH / SDP style label for chips */
    public const OPTIONS = [
        'PCMU' => 'G.711 μ-law (ulaw / G711)',
        'PCMA' => 'G.711 A-law (alaw)',
        'G729' => 'G.729',
        'G729A' => 'G.729A',
        'GSM' => 'GSM',
        'G722' => 'G.722',
        'G723' => 'G.723',
        'G726' => 'G.726',
        'OPUS' => 'Opus',
        'iLBC' => 'iLBC',
        'SPEEX' => 'Speex',
        'AMR' => 'AMR',
        'AMR-WB' => 'AMR-WB',
        'H261' => 'H.261 (video)',
        'H263' => 'H.263 (video)',
        'H263P' => 'H.263+ (video)',
        'H264' => 'H.264 (video)',
        'VP8' => 'VP8 (video)',
        'telephone-event' => 'telephone-event (DTMF)',
    ];

    /** Default when Settings → Default codecs is empty. */
    public const DEFAULT_LIST = ['PCMU', 'PCMA', 'G729', 'GSM', 'telephone-event'];

    /** FreeSWITCH absolute_codec_string tokens (canonical portal → FS). */
    private const FS_NAMES = [
        'PCMU' => 'PCMU',
        'PCMA' => 'PCMA',
        'G729' => 'G729',
        'G729A' => 'G729',
        'GSM' => 'GSM',
        'G722' => 'G722',
        'G723' => 'G723',
        'G726' => 'G726',
        'OPUS' => 'opus',
        'iLBC' => 'iLBC',
        'SPEEX' => 'speex',
        'AMR' => 'AMR',
        'AMR-WB' => 'AMR-WB',
        'H261' => 'H261',
        'H263' => 'H263',
        'H263P' => 'H263-1998',
        'H264' => 'H264',
        'VP8' => 'VP8',
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

    /** @return array<string,string> */
    public static function formOptions(): array
    {
        return self::OPTIONS;
    }

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

    /** Comma list for DB / forms from Settings (or built-in default). */
    public static function defaultStorage(?PDO $pdo = null): string
    {
        if ($pdo !== null) {
            try {
                $from = (new SettingsService($pdo))->get('default_codecs', '');
                $stored = self::toStorage($from);
                if ($stored !== null) {
                    return $stored;
                }
            } catch (\Throwable) {
            }
        }
        return implode(',', self::DEFAULT_LIST);
    }

    /**
     * Resolve row codecs: empty/null → system default. Returns storage string (never empty).
     */
    public static function resolve(?string $stored, ?PDO $pdo = null): string
    {
        $list = self::normalizeList($stored);
        if ($list !== []) {
            return implode(',', $list);
        }
        return self::defaultStorage($pdo);
    }

    /** FreeSWITCH absolute_codec_string (empty if nothing usable). */
    public static function toAbsoluteCodecString(?string $stored): string
    {
        $fs = [];
        foreach (self::normalizeList($stored) as $code) {
            $name = self::FS_NAMES[$code] ?? null;
            if ($name !== null && !in_array($name, $fs, true)) {
                $fs[] = $name;
            }
        }
        return implode(',', $fs);
    }
}
