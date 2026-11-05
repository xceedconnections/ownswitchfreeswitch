<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\AreaCodeRepository;
use Ownswitch\Repositories\BaseRepository;

/**
 * Area codes / NPA names for CDR Description (separate from billing Prefixes).
 * Example: code 1213 → “USA LA”; dial 12138007447 → CDR Description = USA LA.
 */
final class AreaCodeController extends CrudController
{
    protected string $base = '/area-codes';
    protected string $singular = 'Area code';
    protected string $plural = 'Area Codes';
    protected string $emptyHint = 'No area codes yet. Add dial codes with city/region names (e.g. 1213 = USA LA). CDRs show this as Description.';
    protected string $defaultSort = 'code';

    protected function repo(): BaseRepository
    {
        return new AreaCodeRepository($this->db());
    }

    protected function fields(Request $r): array
    {
        return [
            [
                'name' => 'code',
                'label' => 'Area code',
                'type' => 'prefix',
                'required' => true,
                'list' => true,
                'help' => 'Digits only, including country code. Example: 1213 for USA Los Angeles, 1203 for Connecticut.',
            ],
            [
                'name' => 'name',
                'label' => 'Description / city',
                'required' => true,
                'max' => 120,
                'list' => true,
                'help' => 'Shown on CDRs when the dialled number matches this code (longest match wins). Example: USA LA',
            ],
            [
                'name' => 'notes',
                'label' => 'Notes',
                'max' => 255,
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['name'] = trim((string) ($data['name'] ?? ''));
        if ($data['name'] === '') {
            throw new \DomainException('Description / city is required.');
        }
        $data['code'] = preg_replace('/\D+/', '', (string) ($data['code'] ?? ($existing['code'] ?? ''))) ?? '';
        if ($data['code'] === '') {
            throw new \DomainException('Area code must be digits only.');
        }
        unset($data['country_code']);
        $notes = trim((string) ($data['notes'] ?? ''));
        $data['notes'] = $notes !== '' ? $notes : null;
        return $data;
    }

    protected function extra(Request $r): array
    {
        return [
            'bulkDelete' => true,
            'headerLinks' => [
                ['href' => '/area-codes/import', 'label' => 'Import', 'icon' => 'upload', 'class' => 'btn-outline-secondary'],
                ['href' => '/area-codes/export', 'label' => 'Export CSV', 'icon' => 'download', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    /** Delete selected rows or all rows matching current search. */
    public function bulkDestroy(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        /** @var AreaCodeRepository $repo */
        $repo = $this->repo();
        $scope = $this->scope($r);
        $mode = (string) $r->input('mode', 'selected');
        $qRaw = $r->input('q');
        if (!is_string($qRaw) || $qRaw === '') {
            $qRaw = $r->query('q');
        }
        $q = is_string($qRaw) ? trim($qRaw) : '';
        $n = 0;

        if ($mode === 'selected') {
            $raw = $r->input('ids', []);
            $ids = is_array($raw) ? $raw : (is_string($raw) ? (preg_split('/[\s,]+/', $raw) ?: []) : []);
            $idList = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
            $n = $repo->deleteByIds($scope, $idList);
        } elseif ($mode === 'filtered') {
            // Empty search = wipe all area codes (staff table; confirm text shows total count).
            $n = $repo->deleteFiltered($scope, [], $q !== '' ? $q : null, true);
        }

        $this->audit($r, 'area_code_bulk_delete', 'area_codes', null, null, [
            'mode' => $mode, 'count' => $n, 'q' => $q,
        ]);
        $qs = $q !== '' ? ('?q=' . rawurlencode($q)) : '';
        return $this->redirect(
            $r,
            $this->base . $qs,
            "Deleted {$n} " . strtolower($this->plural) . '.',
            $n > 0 ? 'success' : 'warning'
        );
    }
}
