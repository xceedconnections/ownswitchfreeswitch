<?php

declare(strict_types=1);

namespace Ownswitch\Support;

final class Csv
{
    /** Neutralise spreadsheet formula injection (CDR fields are untrusted). */
    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return "'" . $s;
        }
        return $s;
    }

    /** @param list<mixed> $row */
    public static function line(array $row): string
    {
        $out = [];
        foreach ($row as $c) {
            $s = self::cell($c);
            $out[] = '"' . str_replace('"', '""', $s) . '"';
        }
        return implode(',', $out) . "\r\n";
    }
}
