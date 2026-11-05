<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Tariffs\ImportUploadStore;
use Ownswitch\Services\Tariffs\TariffCsvService;
use Ownswitch\Support\SpreadsheetReader;

final class TariffImportController extends BaseController
{
    public function form(Request $r): Response
    {
        return $this->view('tariffs/import', $this->baseVars($r) + [
            'step' => 'upload',
            'flash' => null,
            'token' => null,
            'headers' => [],
            'map' => [],
            'sample' => [],
            'row_count' => 0,
            'preview' => null,
            'owners' => $this->owners((string) $r->query('kind', 'customer')),
        ]);
    }

    public function export(Request $r): Response
    {
        $kind = $r->query('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->query('owner_id', '0');
        if ($ownerId <= 0) {
            return $this->view('tariffs/import', $this->baseVars($r, $kind, 0) + [
                'step' => 'upload', 'flash' => 'Select a plan or provider to export.', 'token' => null,
                'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
                'owners' => $this->owners($kind),
            ], 422);
        }
        $csv = (new TariffCsvService($this->db()))->exportCsv($kind, $ownerId);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $kind . '-tariffs-' . $ownerId . '.csv"',
        ]);
    }

    public function upload(Request $r): Response
    {
        $kind = $r->input('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->input('owner_id', '0');
        $vars = $this->baseVars($r, $kind, $ownerId) + ['owners' => $this->owners($kind), 'preview' => null];
        if ($ownerId <= 0) {
            return $this->view('tariffs/import', $vars + [
                'step' => 'upload', 'flash' => 'Select a rate plan or provider.', 'token' => null,
                'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0,
            ], 422);
        }
        try {
            $stored = ImportUploadStore::storeUpload($_FILES['file'] ?? []);
        } catch (\Throwable $e) {
            return $this->view('tariffs/import', $vars + [
                'step' => 'upload', 'flash' => $e->getMessage(), 'token' => null,
                'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0,
            ], 422);
        }
        $map = SpreadsheetReader::guessMap(TariffCsvService::HEADERS, $stored['headers']);
        return $this->view('tariffs/import', $vars + [
            'step' => 'map',
            'flash' => 'Map your columns, then validate.',
            'token' => $stored['token'],
            'headers' => $stored['headers'],
            'map' => $map,
            'sample' => $stored['sample'],
            'row_count' => $stored['row_count'],
        ]);
    }

    public function preview(Request $r): Response
    {
        $kind = $r->input('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->input('owner_id', '0');
        $token = (string) $r->input('token', '');
        $map = $this->readMap($r);
        $vars = $this->baseVars($r, $kind, $ownerId) + ['owners' => $this->owners($kind)];
        try {
            $data = ImportUploadStore::load($token);
        } catch (\Throwable $e) {
            return $this->view('tariffs/import', $vars + [
                'step' => 'upload', 'flash' => $e->getMessage(), 'token' => null,
                'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
            ], 422);
        }
        foreach (TariffCsvService::REQUIRED as $req) {
            if (($map[$req] ?? '') === '') {
                return $this->view('tariffs/import', $vars + [
                    'step' => 'map', 'flash' => "Map the required field: {$req}", 'token' => $token,
                    'headers' => $data['headers'], 'map' => $map, 'sample' => array_slice($data['rows'], 0, 5),
                    'row_count' => count($data['rows']), 'preview' => null,
                ], 422);
            }
        }
        $preview = (new TariffCsvService($this->db()))->previewMapped($kind, $ownerId, $data['headers'], $data['rows'], $map);
        return $this->view('tariffs/import', $vars + [
            'step' => 'preview',
            'flash' => $preview['ok'] ? 'Validation OK — review then commit.' : 'Validation failed.',
            'token' => $token,
            'headers' => $data['headers'],
            'map' => $map,
            'sample' => array_slice($data['rows'], 0, 5),
            'row_count' => count($data['rows']),
            'preview' => $preview,
        ]);
    }

    public function commit(Request $r): Response
    {
        $kind = $r->input('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->input('owner_id', '0');
        $token = (string) $r->input('token', '');
        $map = $this->readMap($r);
        $vars = $this->baseVars($r, $kind, $ownerId) + ['owners' => $this->owners($kind)];
        try {
            $data = ImportUploadStore::load($token);
            $preview = (new TariffCsvService($this->db()))->previewMapped($kind, $ownerId, $data['headers'], $data['rows'], $map);
            if (!$preview['ok']) {
                throw new \RuntimeException(implode('; ', $preview['errors']));
            }
            $n = (new TariffCsvService($this->db()))->importRows($kind, $ownerId, $preview['rows'], (int) ($this->user($r)['id'] ?? 0));
            ImportUploadStore::forget($token);
            return $this->view('tariffs/import', $vars + [
                'step' => 'upload', 'flash' => "Imported {$n} tariff row(s).", 'token' => null,
                'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->view('tariffs/import', $vars + [
                'step' => 'map', 'flash' => 'Import failed: ' . $e->getMessage(), 'token' => $token,
                'headers' => [], 'map' => $map, 'sample' => [], 'row_count' => 0, 'preview' => null,
            ], 422);
        }
    }

    /** @return array<string,mixed> */
    private function baseVars(Request $r, ?string $kind = null, ?int $ownerId = null): array
    {
        return [
            'title' => 'Tariff import / export',
            'kind' => $kind ?? ((string) $r->query('kind', 'customer') === 'carrier' ? 'carrier' : 'customer'),
            'owner_id' => $ownerId ?? (int) $r->query('owner_id', '0'),
            'fields' => TariffCsvService::HEADERS,
            'required' => TariffCsvService::REQUIRED,
        ];
    }

    /** @return array<int|string,string> */
    private function owners(string $kind): array
    {
        $o = [];
        if ($kind === 'carrier') {
            foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
                $o[(int) $c['id']] = $c['name'];
            }
        } else {
            foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $c) {
                $o[(int) $c['id']] = $c['name'];
            }
        }
        return $o;
    }

    /** @return array<string,string> */
    private function readMap(Request $r): array
    {
        $map = [];
        foreach (TariffCsvService::HEADERS as $f) {
            $map[$f] = trim((string) $r->input('map_' . $f, ''));
        }
        return $map;
    }
}
