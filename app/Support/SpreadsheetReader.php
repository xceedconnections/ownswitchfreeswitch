<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Read CSV or simple XLSX (first sheet) into header + rows. No Composer deps.
 */
final class SpreadsheetReader
{
    /**
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['headers' => [], 'rows' => [], 'error' => 'Upload failed.'];
        }
        if (($file['size'] ?? 0) > 8_000_000) {
            return ['headers' => [], 'rows' => [], 'error' => 'File too large (max 8 MB).'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = strtolower((string) ($file['name'] ?? ''));
        $raw = (string) file_get_contents($tmp);
        if ($raw === '') {
            return ['headers' => [], 'rows' => [], 'error' => 'Empty file.'];
        }
        if (str_ends_with($name, '.xlsx') || str_starts_with($raw, 'PK')) {
            return self::fromXlsx($tmp);
        }
        return self::fromCsv($raw);
    }

    /**
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromCsv(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return ['headers' => [], 'rows' => [], 'error' => 'Unable to parse CSV.'];
        }
        fwrite($fh, $contents);
        rewind($fh);
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            return ['headers' => [], 'rows' => [], 'error' => 'Empty CSV.'];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $header);
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) $c), $data);
        }
        fclose($fh);
        return ['headers' => $headers, 'rows' => $rows, 'error' => null];
    }

    /**
     * Minimal XLSX reader (sharedStrings + first sheet rows).
     *
     * @return array{headers:list<string>,rows:list<list<string>>,error:?string}
     */
    public static function fromXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['headers' => [], 'rows' => [], 'error' => 'XLSX needs PHP zip extension — save as CSV instead.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['headers' => [], 'rows' => [], 'error' => 'Cannot open XLSX file.'];
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && $ss !== '') {
            if (preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $ss, $m)) {
                foreach ($m[1] as $si) {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                        $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } else {
                        $shared[] = '';
                    }
                }
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheet) || $sheet === '') {
            return ['headers' => [], 'rows' => [], 'error' => 'XLSX has no sheet1.'];
        }
        $grid = [];
        if (preg_match_all('/<c\\b([^>]*)>(?:<v>(.*?)<\\/v>)?/s', $sheet, $cells, PREG_SET_ORDER)) {
            // fallback simpler row parse
        }
        $rowsXml = [];
        if (preg_match_all('/<row\\b[^>]*>(.*?)<\\/row>/s', $sheet, $rm)) {
            foreach ($rm[1] as $rowXml) {
                $row = [];
                if (preg_match_all('/<c\\b([^>]*)>(?:.*?<v>(.*?)<\\/v>)?/s', $rowXml, $cm, PREG_SET_ORDER)) {
                    $byCol = [];
                    $max = -1;
                    foreach ($cm as $c) {
                        $attrs = $c[1];
                        $v = $c[2] ?? '';
                        $col = 0;
                        if (preg_match('/r="([A-Z]+)\\d+"/i', $attrs, $rmatch)) {
                            $col = self::colIndex($rmatch[1]);
                        }
                        $max = max($max, $col);
                        $type = '';
                        if (preg_match('/t="([^"]+)"/', $attrs, $tm)) {
                            $type = $tm[1];
                        }
                        if ($type === 's' && isset($shared[(int) $v])) {
                            $byCol[$col] = $shared[(int) $v];
                        } else {
                            $byCol[$col] = html_entity_decode((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                    for ($i = 0; $i <= $max; $i++) {
                        $row[] = $byCol[$i] ?? '';
                    }
                }
                if ($row !== [] && implode('', $row) !== '') {
                    $rowsXml[] = $row;
                }
            }
        }
        if ($rowsXml === []) {
            return ['headers' => [], 'rows' => [], 'error' => 'No data rows in XLSX.'];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $rowsXml[0]);
        $data = array_slice($rowsXml, 1);
        return ['headers' => $headers, 'rows' => $data, 'error' => null];
    }

    private static function colIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }

    /**
     * Apply mapping: field => header name (or empty to skip).
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param array<string,string> $map field => source header
     * @return list<array<string,string>>
     */
    public static function mapRows(array $headers, array $rows, array $map): array
    {
        $idx = [];
        foreach ($headers as $i => $h) {
            $idx[strtolower(trim($h))] = $i;
        }
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($map as $field => $src) {
                $src = trim($src);
                if ($src === '') {
                    $assoc[$field] = '';
                    continue;
                }
                $i = $idx[strtolower($src)] ?? null;
                $assoc[$field] = $i === null ? '' : trim((string) ($row[$i] ?? ''));
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** Guess map from headers to known fields. @param list<string> $fields @param list<string> $headers */
    public static function guessMap(array $fields, array $headers): array
    {
        $aliases = [
            'prefix' => ['prefix', 'dialcode', 'dial_code', 'code', 'destinationcode', 'dst'],
            'destination' => ['destination', 'dest', 'name', 'description', 'country', 'city'],
            'rate' => ['rate', 'price', 'sell', 'sell_price', 'cost', 'buy', 'buy_price'],
            'initial_increment' => ['initial_increment', 'initial', 'initblock', 'init_block', 'min_time'],
            'subsequent_increment' => ['subsequent_increment', 'subsequent', 'billingblock', 'billing_block', 'increment'],
            'connect_fee' => ['connect_fee', 'connection', 'connect', 'setup'],
            'status' => ['status', 'active', 'enabled'],
            'effective_from' => ['effective_from', 'from', 'start', 'valid_from'],
            'effective_to' => ['effective_to', 'to', 'end', 'valid_to'],
            'routing_plan_id' => ['routing_plan_id', 'trunk_group', 'trunkgroup', 'trunk_group_id'],
        ];
        $lower = [];
        foreach ($headers as $h) {
            $lower[strtolower(preg_replace('/[^a-z0-9]+/i', '_', $h) ?? $h)] = $h;
            $lower[strtolower(trim($h))] = $h;
        }
        $map = [];
        foreach ($fields as $f) {
            $map[$f] = '';
            foreach ($aliases[$f] ?? [$f] as $a) {
                $key = strtolower($a);
                if (isset($lower[$key])) {
                    $map[$f] = $lower[$key];
                    break;
                }
            }
        }
        return $map;
    }
}
