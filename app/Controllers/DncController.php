<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Dnc\DncService;
use Ownswitch\Services\Tariffs\ImportUploadStore;
use Ownswitch\Support\Destination;
use Ownswitch\Support\SpreadsheetReader;

/** Routes → DNC: groups, numbers, CSV/XLSX add/delete. */
final class DncController extends BaseController
{
    private function svc(): DncService
    {
        return new DncService($this->db());
    }

    public function index(Request $r, array $p = []): Response
    {
        try {
            $svc = $this->svc();
            $svc->ensureSchema();
            return $this->view('dnc/groups', [
                'title' => 'DNC',
                'groups' => $svc->listGroups(),
                'canWrite' => $this->canWrite($r),
            ]);
        } catch (\PDOException $e) {
            error_log('[ownswitch] DNC index: ' . $e->getMessage());
            return $this->view('error', [
                'title' => 'DNC',
                'message' => 'DNC tables are missing or the database rejected the query. On the server run: '
                    . 'sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/migrate.php',
                'code' => 500,
            ], 500);
        }
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        return $this->view('dnc/group_form', [
            'title' => 'New DNC group',
            'group' => null,
            'errors' => [],
            'data' => ['name' => '', 'status' => 'active', 'notes' => ''],
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        try {
            $id = $this->svc()->createGroup(
                (string) $r->input('name', ''),
                (string) $r->input('status', 'active'),
                (string) $r->input('notes', '')
            );
            $this->audit($r, 'create', 'dnc_groups', $id, null, ['name' => $r->input('name')]);
            return $this->redirect($r, '/dnc/' . $id, 'DNC group created.');
        } catch (\DomainException $e) {
            return $this->view('dnc/group_form', [
                'title' => 'New DNC group',
                'group' => null,
                'errors' => ['_form' => $e->getMessage()],
                'data' => $r->post,
            ], 422);
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062) ? 'A group with that name already exists.' : 'Could not save group.';
            return $this->view('dnc/group_form', [
                'title' => 'New DNC group',
                'group' => null,
                'errors' => ['_form' => $msg],
                'data' => $r->post,
            ], 422);
        }
    }

    public function edit(Request $r, array $p): Response
    {
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        return $this->view('dnc/group_form', [
            'title' => 'Edit DNC group',
            'group' => $g,
            'errors' => [],
            'data' => $g,
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function update(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        try {
            $this->svc()->updateGroup(
                (int) $g['id'],
                (string) $r->input('name', ''),
                (string) $r->input('status', 'active'),
                (string) $r->input('notes', '')
            );
            $this->audit($r, 'update', 'dnc_groups', (int) $g['id'], $g, $r->post);
            return $this->redirect($r, '/dnc/' . (int) $g['id'], 'Group saved.');
        } catch (\DomainException $e) {
            return $this->view('dnc/group_form', [
                'title' => 'Edit DNC group',
                'group' => $g,
                'errors' => ['_form' => $e->getMessage()],
                'data' => $r->post,
                'canWrite' => true,
            ], 422);
        }
    }

    public function destroy(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $this->svc()->deleteGroup((int) $g['id']);
        $this->audit($r, 'delete', 'dnc_groups', (int) $g['id'], $g, null);
        return $this->redirect($r, '/dnc', 'DNC group deleted.');
    }

    public function numbers(Request $r, array $p): Response
    {
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $q = is_string($r->query('q')) ? trim((string) $r->query('q')) : '';
        $result = $this->svc()->listNumbers((int) $g['id'], $q, (int) $r->query('page', 1), 10);
        return $this->view('dnc/numbers', [
            'title' => 'DNC · ' . $g['name'],
            'group' => $g,
            'result' => $result,
            'q' => $q,
            'query' => array_filter(['q' => $q], static fn ($v) => $v !== ''),
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function addNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) $p['id'];
        try {
            $digits = $this->svc()->addNumber($id, (string) $r->input('number', ''), (string) $r->input('note', ''));
            $this->audit($r, 'dnc_add', 'dnc_numbers', $id, null, ['number' => $digits]);
            return $this->redirect($r, '/dnc/' . $id, "Added {$digits}.");
        } catch (\DomainException $e) {
            return $this->redirect($r, '/dnc/' . $id, $e->getMessage(), 'danger');
        }
    }

    public function deleteNumbers(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) $p['id'];
        $ids = $r->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $n = $this->svc()->deleteNumbersById($id, $ids);
        $this->audit($r, 'dnc_delete', 'dnc_numbers', $id, null, ['count' => $n]);
        return $this->redirect($r, '/dnc/' . $id, $n > 0 ? "Removed {$n} number(s)." : 'Nothing removed.');
    }

    public function importForm(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $mode = (string) $r->query('mode', 'add');
        if (!in_array($mode, ['add', 'delete'], true)) {
            $mode = 'add';
        }
        return $this->view('dnc/import', $this->importVars($g, 'upload', $mode));
    }

    public function importUpload(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $mode = (string) $r->input('mode', 'add');
        if (!in_array($mode, ['add', 'delete'], true)) {
            $mode = 'add';
        }
        try {
            $stored = ImportUploadStore::storeUpload($_FILES['file'] ?? []);
        } catch (\Throwable $e) {
            return $this->view('dnc/import', $this->importVars($g, 'upload', $mode, $e->getMessage()), 422);
        }
        $map = SpreadsheetReader::guessMap(['number', 'note'], $stored['headers']);
        if (($map['number'] ?? '') === '' && ($stored['headers'][0] ?? '') !== '') {
            $map['number'] = (string) $stored['headers'][0];
        }
        return $this->view('dnc/import', $this->importVars($g, 'map', $mode, 'Map columns, then validate.', [
            'token' => $stored['token'],
            'headers' => $stored['headers'],
            'map' => $map,
            'sample' => $stored['sample'],
            'row_count' => $stored['row_count'],
        ]));
    }

    public function importPreview(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $mode = (string) $r->input('mode', 'add');
        if (!in_array($mode, ['add', 'delete'], true)) {
            $mode = 'add';
        }
        $token = (string) $r->input('token', '');
        $map = [
            'number' => (string) $r->input('map_number', ''),
            'note' => (string) $r->input('map_note', ''),
        ];
        try {
            $data = ImportUploadStore::load($token);
        } catch (\Throwable $e) {
            return $this->view('dnc/import', $this->importVars($g, 'upload', $mode, $e->getMessage()), 422);
        }
        if ($map['number'] === '') {
            return $this->view('dnc/import', $this->importVars($g, 'map', $mode, 'Map the number column.', [
                'token' => $token,
                'headers' => $data['headers'],
                'map' => $map,
                'sample' => array_slice($data['rows'], 0, 5),
                'row_count' => count($data['rows']),
            ]), 422);
        }
        $preview = $this->previewMapped($data['headers'], $data['rows'], $map);
        return $this->view('dnc/import', $this->importVars(
            $g,
            'preview',
            $mode,
            $preview['ok'] ? 'Validation OK — commit to apply.' : 'Validation failed.',
            [
                'token' => $token,
                'headers' => $data['headers'],
                'map' => $map,
                'sample' => array_slice($data['rows'], 0, 5),
                'row_count' => count($data['rows']),
                'preview' => $preview,
            ]
        ));
    }

    public function importCommit(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->svc()->findGroup((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $mode = (string) $r->input('mode', 'add');
        if (!in_array($mode, ['add', 'delete'], true)) {
            $mode = 'add';
        }
        $token = (string) $r->input('token', '');
        $map = [
            'number' => (string) $r->input('map_number', ''),
            'note' => (string) $r->input('map_note', ''),
        ];
        try {
            $data = ImportUploadStore::load($token);
            $preview = $this->previewMapped($data['headers'], $data['rows'], $map);
            if (!$preview['ok']) {
                throw new \RuntimeException(implode('; ', $preview['errors']));
            }
            $res = $this->svc()->importRows((int) $g['id'], $preview['rows'], $mode);
            ImportUploadStore::forget($token);
            $this->audit($r, 'dnc_import_' . $mode, 'dnc_groups', (int) $g['id'], null, $res);
            $verb = $mode === 'delete' ? 'Removed' : 'Added';
            return $this->redirect(
                $r,
                '/dnc/' . (int) $g['id'],
                "{$verb} {$res['ok']} number(s)" . ($res['skipped'] ? ", skipped {$res['skipped']}" : '') . '.'
            );
        } catch (\Throwable $e) {
            return $this->view('dnc/import', $this->importVars($g, 'upload', $mode, 'Import failed: ' . $e->getMessage()), 422);
        }
    }

    public function rebuildCache(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) ($p['id'] ?? 0);
        $this->svc()->rebuildGroupCache($id > 0 ? $id : null);
        $this->audit($r, 'dnc_rebuild_cache', 'dnc_groups', $id > 0 ? $id : null, null, null);
        $path = $id > 0 ? '/dnc/' . $id : '/dnc';
        return $this->redirect($r, $path, 'DNC cache rebuilt (Redis + dialplan lookup).');
    }

    private function canWrite(Request $r): bool
    {
        return in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin'], true);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function importVars(array $group, string $step, string $mode, ?string $flash = null, array $extra = []): array
    {
        return [
            'title' => ($mode === 'delete' ? 'Delete DNC numbers' : 'Import DNC numbers') . ' · ' . $group['name'],
            'group' => $group,
            'step' => $step,
            'mode' => $mode,
            'flash' => $flash,
            'fields' => ['number', 'note'],
            'required' => ['number'],
            'token' => null,
            'headers' => [],
            'map' => ['number' => '', 'note' => ''],
            'sample' => [],
            'row_count' => 0,
            'preview' => null,
        ] + $extra;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param array{number:string,note:string} $map
     * @return array{ok:bool,errors:list<string>,rows:list<array{number:string,note:?string}>}
     */
    private function previewMapped(array $headers, array $rows, array $map): array
    {
        $idx = static function (string $col) use ($headers): int {
            $i = array_search($col, $headers, true);
            return $i === false ? -1 : (int) $i;
        };
        $ni = $idx($map['number']);
        $ti = $map['note'] !== '' ? $idx($map['note']) : -1;
        if ($ni < 0) {
            return ['ok' => false, 'errors' => ['Number column not found.'], 'rows' => []];
        }
        $out = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $raw = (string) ($row[$ni] ?? '');
            $digits = Destination::normalize($raw);
            if ($digits === '') {
                continue;
            }
            if (strlen($digits) < 3 || strlen($digits) > 32) {
                if (count($errors) < 15) {
                    $errors[] = 'Row ' . ($i + 2) . ': invalid number "' . mb_substr($raw, 0, 40) . '"';
                }
                continue;
            }
            if (isset($seen[$digits])) {
                continue;
            }
            $seen[$digits] = true;
            $note = $ti >= 0 ? trim((string) ($row[$ti] ?? '')) : '';
            $out[] = ['number' => $digits, 'note' => $note !== '' ? $note : null];
        }
        if ($out === [] && $errors === []) {
            $errors[] = 'No valid numbers found in the file.';
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'rows' => $out];
    }
}
