<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Repositories\AreaCodeRepository;
use Ownswitch\Support\SpreadsheetReader;
use PDO;
use RuntimeException;

final class AreaCodeCsvService
{
    public const HEADERS = ['code', 'name', 'notes'];
    public const REQUIRED = ['code', 'name'];

    public function __construct(private PDO $db)
    {
    }

    public function exportCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('temp');
        }
        fputcsv($out, self::HEADERS);
        $st = $this->db->query('SELECT code, name, notes FROM area_codes ORDER BY code');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            fputcsv($out, [$row['code'], $row['name'], $row['notes'] ?? '']);
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /**
     * @param array<string,string> $map
     * @return array{ok:bool,errors:list<string>,rows:list<array{code:string,name:string,notes:?string}>}
     */
    public function previewMapped(array $headers, array $dataRows, array $map): array
    {
        $mapped = SpreadsheetReader::mapRows($headers, $dataRows, $map);
        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($mapped as $i => $assoc) {
            $line = $i + 2;
            $code = preg_replace('/\D+/', '', (string) ($assoc['code'] ?? '')) ?? '';
            $name = trim((string) ($assoc['name'] ?? ''));
            $notes = trim((string) ($assoc['notes'] ?? ''));
            if ($code === '') {
                $errors[] = "Row {$line}: invalid area code (digits only)";
                continue;
            }
            if (strlen($code) > 32) {
                $errors[] = "Row {$line}: area code too long";
                continue;
            }
            if ($name === '') {
                $errors[] = "Row {$line}: name / description is required";
                continue;
            }
            if (mb_strlen($name) > 120) {
                $errors[] = "Row {$line}: name too long (max 120)";
                continue;
            }
            if (mb_strlen($notes) > 255) {
                $errors[] = "Row {$line}: notes too long (max 255)";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Duplicate code {$code} in file (rows {$seen[$code]} and {$line})";
                continue;
            }
            $seen[$code] = $line;
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'rows' => $rows];
    }

    /** @param list<array{code:string,name:string,notes:?string}> $rows */
    public function importRows(array $rows): int
    {
        $repo = new AreaCodeRepository($this->db);
        $n = 0;
        foreach ($rows as $row) {
            $repo->upsert($row['code'], $row['name'], $row['notes'] ?? null);
            $n++;
        }
        return $n;
    }
}
