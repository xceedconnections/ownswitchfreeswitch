<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Tariffs\ImportUploadStore;
use Ownswitch\Services\Tariffs\PrefixCsvService;
use Ownswitch\Support\SpreadsheetReader;

final class PrefixImportController extends BaseController
{
    public function form(Request $r): Response
    {
        return $this->view('prefixes/import', [
            'title' => 'Prefix import / export',
            'step' => 'upload',
            'flash' => null,
            'token' => null,
            'headers' => [],
            'map' => [],
            'sample' => [],
            'row_count' => 0,
            'preview' => null,
            'fields' => PrefixCsvService::HEADERS,
            'required' => PrefixCsvService::REQUIRED,
        ]);
    }

    public function export(Request $r): Response
    {
        $csv = (new PrefixCsvService($this->db()))->exportCsv();
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="prefixes.csv"',
        ]);
    }

    public function upload(Request $r): Response
    {
        try {
            $stored = ImportUploadStore::storeUpload($_FILES['file'] ?? []);
        } catch (\Throwable $e) {
            return $this->view('prefixes/import', [
                'title' => 'Prefix import / export', 'step' => 'upload', 'flash' => $e->getMessage(),
                'token' => null, 'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
                'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
            ], 422);
        }
        $map = SpreadsheetReader::guessMap(PrefixCsvService::HEADERS, $stored['headers']);
        return $this->view('prefixes/import', [
            'title' => 'Prefix import / export', 'step' => 'map', 'flash' => 'Map columns, then validate.',
            'token' => $stored['token'], 'headers' => $stored['headers'], 'map' => $map,
            'sample' => $stored['sample'], 'row_count' => $stored['row_count'], 'preview' => null,
            'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
        ]);
    }

    public function preview(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        $map = [
            'prefix' => trim((string) $r->input('map_prefix', '')),
            'destination' => trim((string) $r->input('map_destination', '')),
        ];
        try {
            $data = ImportUploadStore::load($token);
        } catch (\Throwable $e) {
            return $this->view('prefixes/import', [
                'title' => 'Prefix import / export', 'step' => 'upload', 'flash' => $e->getMessage(),
                'token' => null, 'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
                'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
            ], 422);
        }
        if ($map['prefix'] === '' || $map['destination'] === '') {
            return $this->view('prefixes/import', [
                'title' => 'Prefix import / export', 'step' => 'map', 'flash' => 'Map both prefix and destination.',
                'token' => $token, 'headers' => $data['headers'], 'map' => $map,
                'sample' => array_slice($data['rows'], 0, 5), 'row_count' => count($data['rows']), 'preview' => null,
                'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
            ], 422);
        }
        $preview = (new PrefixCsvService($this->db()))->previewMapped($data['headers'], $data['rows'], $map);
        return $this->view('prefixes/import', [
            'title' => 'Prefix import / export',
            'step' => 'preview',
            'flash' => $preview['ok'] ? 'Validation OK — commit to save.' : 'Validation failed.',
            'token' => $token, 'headers' => $data['headers'], 'map' => $map,
            'sample' => array_slice($data['rows'], 0, 5), 'row_count' => count($data['rows']), 'preview' => $preview,
            'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
        ]);
    }

    public function commit(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        $map = [
            'prefix' => trim((string) $r->input('map_prefix', '')),
            'destination' => trim((string) $r->input('map_destination', '')),
        ];
        try {
            $data = ImportUploadStore::load($token);
            $preview = (new PrefixCsvService($this->db()))->previewMapped($data['headers'], $data['rows'], $map);
            if (!$preview['ok']) {
                throw new \RuntimeException(implode('; ', $preview['errors']));
            }
            $n = (new PrefixCsvService($this->db()))->importRows($preview['rows']);
            ImportUploadStore::forget($token);
            return $this->view('prefixes/import', [
                'title' => 'Prefix import / export', 'step' => 'upload', 'flash' => "Imported {$n} prefix(es).",
                'token' => null, 'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
                'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
            ]);
        } catch (\Throwable $e) {
            return $this->view('prefixes/import', [
                'title' => 'Prefix import / export', 'step' => 'upload', 'flash' => 'Import failed: ' . $e->getMessage(),
                'token' => null, 'headers' => [], 'map' => [], 'sample' => [], 'row_count' => 0, 'preview' => null,
                'fields' => PrefixCsvService::HEADERS, 'required' => PrefixCsvService::REQUIRED,
            ], 422);
        }
    }
}
