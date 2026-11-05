<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Support\SpreadsheetReader;
use PDO;
use RuntimeException;

final class PrefixCsvService
{
    public const HEADERS = ['prefix', 'destination'];
    public const REQUIRED = ['prefix', 'destination'];

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
        $st = $this->db->query('SELECT prefix, destination FROM prefixes ORDER BY prefix');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            fputcsv($out, [$row['prefix'], $row['destination']]);
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /**
     * @param array<string,string> $map
     * @return array{ok:bool,errors:list<string>,rows:list<array{prefix:string,destination:string}>}
     */
    public function previewMapped(array $headers, array $dataRows, array $map): array
    {
        $mapped = SpreadsheetReader::mapRows($headers, $dataRows, $map);
        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($mapped as $i => $assoc) {
            $line = $i + 2;
            $prefix = preg_replace('/\D+/', '', (string) ($assoc['prefix'] ?? '')) ?? '';
            $dest = trim((string) ($assoc['destination'] ?? ''));
            if ($prefix === '') {
                $errors[] = "Row {$line}: invalid prefix";
                continue;
            }
            if ($dest === '') {
                $errors[] = "Row {$line}: destination is required";
                continue;
            }
            if (isset($seen[$prefix])) {
                $errors[] = "Duplicate prefix {$prefix} in file (rows {$seen[$prefix]} and {$line})";
                continue;
            }
            $seen[$prefix] = $line;
            $rows[] = ['prefix' => $prefix, 'destination' => $dest];
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'rows' => $rows];
    }

    /** @param list<array{prefix:string,destination:string}> $rows */
    public function importRows(array $rows): int
    {
        $repo = new PrefixRepository($this->db);
        $n = 0;
        foreach ($rows as $row) {
            $repo->upsert($row['prefix'], $row['destination']);
            $n++;
        }
        return $n;
    }
}
