<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Destination number normalisation and longest-prefix candidate generation. */
final class Destination
{
    /** Normalise to digits only (E.164 without '+'); 00 / 011 international prefixes stripped. */
    public static function normalize(string $raw): string
    {
        $s = trim($raw);
        $plus = str_starts_with($s, '+');
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        if (!$plus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '011') && strlen($digits) > 10) {
                $digits = substr($digits, 3);
            }
        }
        return $digits;
    }

    /**
     * All prefixes of $dst, longest first. Use with `prefix IN (...)` then pick longest.
     *
     * @return list<string>
     */
    public static function candidates(string $dst, int $max = 15): array
    {
        $dst = preg_replace('/\D+/', '', $dst) ?? '';
        $out = [];
        for ($len = min(strlen($dst), $max); $len >= 1; $len--) {
            $out[] = substr($dst, 0, $len);
        }
        return $out;
    }

    /** @param list<string> $candidates */
    public static function placeholders(array $candidates): string
    {
        return implode(',', array_fill(0, max(1, count($candidates)), '?'));
    }
}
