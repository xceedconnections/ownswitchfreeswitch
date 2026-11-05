<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Support\SpreadsheetReader;
use PDO;
use RuntimeException;

/**
 * CSV/XLSX import/export for customer and carrier tariff tables, with column mapping.
 */
final class TariffCsvService
{
    public const HEADERS = [
        'prefix', 'destination', 'rate', 'initial_increment', 'subsequent_increment',
        'connect_fee', 'status', 'effective_from', 'effective_to',
    ];

    public const REQUIRED = ['prefix', 'rate'];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(string $kind, int $ownerId): array
    {
        if ($kind === 'carrier') {
            $st = $this->db->prepare('SELECT prefix, destination, rate, initial_increment, subsequent_increment, connect_fee, status, effective_from, effective_to FROM carrier_tariffs WHERE carrier_id = ? ORDER BY prefix');
            $st->execute([$ownerId]);
        } else {
            $st = $this->db->prepare('SELECT prefix, destination, rate, initial_increment, subsequent_increment, connect_fee, status, effective_from, effective_to FROM rate_plan_tariffs WHERE rate_plan_id = ? ORDER BY prefix');
            $st->execute([$ownerId]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function exportCsv(string $kind, int $ownerId): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('Unable to open temp stream');
        }
        fputcsv($out, self::HEADERS);
        foreach ($this->exportRows($kind, $ownerId) as $row) {
            $line = [];
            foreach (self::HEADERS as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /**
     * @param array<string,string> $map field => source header
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,rows:list<array<string,mixed>>,duplicates:list<string>}
     */
    public function previewMapped(string $kind, int $ownerId, array $headers, array $dataRows, array $map): array
    {
        $mapped = SpreadsheetReader::mapRows($headers, $dataRows, $map);
        return $this->previewRows($mapped);
    }

    /**
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,rows:list<array<string,mixed>>,duplicates:list<string>}
     */
    public function preview(string $kind, int $ownerId, string $csvContents): array
    {
        $parsed = SpreadsheetReader::fromCsv($csvContents);
        if ($parsed['error']) {
            return ['ok' => false, 'errors' => [$parsed['error']], 'warnings' => [], 'rows' => [], 'duplicates' => []];
        }
        $map = SpreadsheetReader::guessMap(self::HEADERS, $parsed['headers']);
        return $this->previewMapped($kind, $ownerId, $parsed['headers'], $parsed['rows'], $map);
    }

    /**
     * @param list<array<string,string>> $mapped
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,rows:list<array<string,mixed>>,duplicates:list<string>}
     */
    public function previewRows(array $mapped): array
    {
        $errors = [];
        $warnings = [];
        $rows = [];
        $seen = [];
        $duplicates = [];
        foreach ($mapped as $i => $assoc) {
            $lineNo = $i + 2;
            $prefix = preg_replace('/\D+/', '', (string) ($assoc['prefix'] ?? '')) ?? '';
            if ($prefix === '') {
                $errors[] = "Row {$lineNo}: invalid prefix";
                continue;
            }
            $rate = trim((string) ($assoc['rate'] ?? ''));
            if ($rate === '') {
                $errors[] = "Row {$lineNo}: rate is required";
                continue;
            }
            $status = strtolower(trim((string) ($assoc['status'] ?? 'active')));
            if ($status === '') {
                $status = 'active';
            }
            if (!in_array($status, ['active', 'disabled'], true)) {
                $errors[] = "Row {$lineNo}: status must be active or disabled";
                continue;
            }
            if (isset($seen[$prefix])) {
                $duplicates[] = "Duplicate prefix {$prefix} (rows {$seen[$prefix]} and {$lineNo})";
            } else {
                $seen[$prefix] = $lineNo;
            }
            try {
                RatingService::assertDecimal($rate, 'rate');
                if (bccomp($rate, '0', 8) < 0) {
                    $errors[] = "Row {$lineNo}: negative rate rejected";
                }
            } catch (\Throwable $e) {
                $errors[] = "Row {$lineNo}: " . $e->getMessage();
            }
            foreach (['initial_increment', 'subsequent_increment'] as $inc) {
                $v = $assoc[$inc] ?? '';
                if ($v !== '' && $v !== null && (int) $v <= 0) {
                    $errors[] = "Row {$lineNo}: {$inc} must be a positive integer";
                }
            }
            $rows[] = [
                'prefix' => $prefix,
                'destination' => (string) ($assoc['destination'] ?? ''),
                'rate' => $rate,
                'initial_increment' => (string) ($assoc['initial_increment'] ?? ''),
                'subsequent_increment' => (string) ($assoc['subsequent_increment'] ?? ''),
                'connect_fee' => (string) ($assoc['connect_fee'] ?? ''),
                'status' => $status,
                'effective_from' => (string) ($assoc['effective_from'] ?? ''),
                'effective_to' => (string) ($assoc['effective_to'] ?? ''),
            ];
        }
        if ($duplicates !== []) {
            $errors = array_merge($errors, $duplicates);
        }
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'rows' => $rows,
            'duplicates' => $duplicates,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    public function importRows(string $kind, int $ownerId, array $rows, int $actorUserId): int
    {
        $preview = $this->previewRows(array_map(static fn ($r) => array_map('strval', $r), $rows));
        if (!$preview['ok']) {
            throw new RuntimeException('Validation failed: ' . implode('; ', $preview['errors']));
        }
        $this->db->beginTransaction();
        try {
            $count = 0;
            foreach ($preview['rows'] as $row) {
                $this->upsert($kind, $ownerId, $row);
                $count++;
            }
            if ($kind === 'customer') {
                (new RatePlanRepository($this->db))->createVersion($ownerId, $actorUserId ?: null);
            }
            $this->logBatch($kind, $ownerId, $actorUserId, $count);
            $this->db->commit();
            return $count;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function import(string $kind, int $ownerId, string $csvContents, int $actorUserId): int
    {
        $preview = $this->preview($kind, $ownerId, $csvContents);
        if (!$preview['ok']) {
            throw new RuntimeException('CSV validation failed: ' . implode('; ', $preview['errors']));
        }
        return $this->importRows($kind, $ownerId, $preview['rows'], $actorUserId);
    }

    /** @param array<string,mixed> $row */
    private function upsert(string $kind, int $ownerId, array $row): void
    {
        (new PrefixRepository($this->db))->upsert((string) $row['prefix'], (string) ($row['destination'] ?? ''));
        $cols = [
            'prefix' => $row['prefix'],
            'destination' => $row['destination'] ?: null,
            'rate' => $row['rate'],
            'initial_increment' => $row['initial_increment'] !== '' && $row['initial_increment'] !== null ? (int) $row['initial_increment'] : null,
            'subsequent_increment' => $row['subsequent_increment'] !== '' && $row['subsequent_increment'] !== null ? (int) $row['subsequent_increment'] : null,
            'connect_fee' => $row['connect_fee'] !== '' && $row['connect_fee'] !== null ? $row['connect_fee'] : null,
            'status' => $row['status'] ?: 'active',
            'effective_from' => $row['effective_from'] ?: null,
            'effective_to' => $row['effective_to'] ?: null,
        ];
        if ($kind === 'carrier') {
            $find = $this->db->prepare('SELECT id FROM carrier_tariffs WHERE carrier_id = ? AND prefix = ? LIMIT 1');
            $find->execute([$ownerId, $cols['prefix']]);
            $id = $find->fetchColumn();
            $repo = TariffRepository::carrier($this->db);
            $cols['carrier_id'] = $ownerId;
            if ($id) {
                $repo->updateInternal((int) $id, $cols);
            } else {
                $repo->insert($cols);
            }
        } else {
            $find = $this->db->prepare('SELECT id FROM rate_plan_tariffs WHERE rate_plan_id = ? AND prefix = ? LIMIT 1');
            $find->execute([$ownerId, $cols['prefix']]);
            $id = $find->fetchColumn();
            $repo = TariffRepository::customer($this->db);
            $cols['rate_plan_id'] = $ownerId;
            if ($id) {
                $repo->updateInternal((int) $id, $cols);
            } else {
                $repo->insert($cols);
            }
        }
    }

    private function logBatch(string $kind, int $ownerId, int $actorUserId, int $count): void
    {
        try {
            $st = $this->db->prepare('INSERT INTO tariff_import_batches (kind, owner_id, user_id, row_count, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $st->execute([$kind, $ownerId, $actorUserId ?: null, $count]);
        } catch (\Throwable) {
        }
    }
}
