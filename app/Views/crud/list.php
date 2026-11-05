<?php
/**
 * @var string $title @var string $base @var string $singular @var string $plural
 * @var list<array<string,mixed>> $fields @var array $result @var string $q @var array $filters @var bool $canWrite @var string $emptyHint @var array $query
 */
$cols = array_values(array_filter($fields, static fn ($f) => !empty($f['list'])));
$cell = static function (array $f, mixed $v): string {
    if ($v === null || $v === '') {
        return '<span class="text-muted">—</span>';
    }
    if (($f['type'] ?? '') === 'select' && isset($f['options'][$v])) {
        $label = (string) ($f['list_options'][$v] ?? $f['options'][$v]);
        if ($f['name'] === 'status') {
            $cls = ['active' => 'success', 'suspended' => 'warning', 'disabled' => 'secondary', 'closed' => 'dark', 'unused' => 'success', 'used' => 'primary'][$v] ?? 'secondary';
            return '<span class="badge text-bg-' . e($cls) . '">' . e($label) . '</span>';
        }
        return e($label);
    }
    if (($f['type'] ?? '') === 'multiselect' && is_string($v)) {
        return e($v);
    }
    if (($f['name'] ?? '') === 'trunks') {
        return '<span class="os-trunk-chain">' . e((string) $v) . '</span>';
    }
    if (($f['name'] ?? '') === 'media_policy') {
        $key = ($v === null || $v === '') ? '' : (string) $v;
        $labels = $f['list_options'] ?? [
            '' => 'Default',
            'direct' => 'Direct',
            'relay' => 'Relay',
            'record' => 'Record',
        ];
        return e((string) ($labels[$key] ?? ($f['options'][$key] ?? $key)));
    }
    if (($f['name'] ?? '') === 'balance') {
        return '<span class="os-mono">' . e(function_exists('fmt_money') ? fmt_money((string) $v, 2) : (string) $v) . '</span>';
    }
    if (($f['type'] ?? '') === 'number') {
        return '<span class="os-mono">' . e($v) . '</span>';
    }
    if (in_array(($f['name'] ?? ''), ['max_concurrent_calls', 'max_channels', 'max_cps'], true)) {
        if ((string) $v === '-1') {
            return '<span class="badge text-bg-secondary">Unlimited</span>';
        }
        return '<span class="os-mono">' . e((string) $v) . '</span>';
    }
    if (($f['name'] ?? '') === 'presence') {
        $online = false;
        $ms = null;
        if (is_array($v)) {
            $online = !empty($v['online']);
            $ms = isset($v['ms']) && is_numeric($v['ms']) ? (int) $v['ms'] : null;
        } elseif (is_string($v)) {
            $online = in_array($v, ['registered', 'online', 'Active', 'reachable'], true);
        }
        $dot = $online
            ? '<span class="os-presence-dot os-presence-dot--on" title="Online" aria-label="Online"></span>'
            : '<span class="os-presence-dot os-presence-dot--off" title="Offline" aria-label="Offline"></span>';
        if ($online && $ms !== null) {
            $cls = $ms < 80 ? 'success' : ($ms < 200 ? 'warning' : 'danger');
            return '<span class="os-presence">' . $dot . ' <span class="os-mono text-' . $cls . '">' . e((string) $ms) . ' ms</span></span>';
        }
        if ($online) {
            return '<span class="os-presence">' . $dot . ' <span class="small text-muted">ok</span></span>';
        }
        return '<span class="os-presence">' . $dot . ' <span class="small text-muted">offline</span></span>';
    }
    if (($f['name'] ?? '') === 'latency_ms') {
        if ($v === null || $v === '' || $v === '—') {
            return '<span class="text-muted">—</span>';
        }
        if ($v === 'n/a' || $v === 'offline') {
            return '<span class="text-muted">' . e((string) $v) . '</span>';
        }
        $n = is_numeric($v) ? (int) $v : null;
        if ($n !== null) {
            $cls = $n < 80 ? 'success' : ($n < 200 ? 'warning' : 'danger');
            return '<span class="os-mono text-' . $cls . '">' . e((string) $n) . ' ms</span>';
        }
        return '<span class="os-mono">' . e((string) $v) . '</span>';
    }
    if (($f['name'] ?? '') === 'reg_status') {
        $online = ($v === 'registered' || $v === 'online' || $v === 'Active' || $v === 'reachable');
        $dot = $online
            ? '<span class="os-presence-dot os-presence-dot--on" title="Online"></span>'
            : '<span class="os-presence-dot os-presence-dot--off" title="Offline"></span>';
        return '<span class="os-presence">' . $dot . '</span>';
    }
    return e((string) $v);
};
$statusField = null;
foreach ($fields as $f) {
    if ($f['name'] === 'status' && ($f['type'] ?? '') === 'select') {
        $statusField = $f;
    }
}
$hideIdColumn = !empty($hideIdColumn);
$colCount = ($hideIdColumn ? 0 : 1) + count($cols) + 1;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($plural) ?> <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub"><?= e((int) $result['total'] === 0 && $emptyHint !== '' ? $emptyHint : ('Manage ' . strtolower($plural))) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach (($headerLinks ?? []) as $link): ?>
            <a class="btn <?= e((string) ($link['class'] ?? 'btn-outline-secondary')) ?> btn-sm" href="<?= e(url((string) $link['href'])) ?>">
                <?php if (!empty($link['icon'])): ?><i class="bi bi-<?= e((string) $link['icon']) ?> me-1"></i><?php endif; ?>
                <?= e((string) $link['label']) ?>
            </a>
        <?php endforeach; ?>
        <?php if ($canWrite): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url($base . '/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New <?= e(strtolower($singular)) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php
$bulkDelete = !empty($bulkDelete);
$bulkRateUpdate = !empty($bulkRateUpdate);
$ownerFilter = $ownerFilter ?? null;
?>
<form method="get" class="os-toolbar row g-2 align-items-end" action="<?= e(url($base)) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Search…" maxlength="100" aria-label="Search">
        </div>
    </div>
    <?php if (is_array($ownerFilter) && !empty($ownerFilter['options'])): ?>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="<?= e($ownerFilter['name']) ?>"><?= e($ownerFilter['label']) ?></label>
            <select name="<?= e($ownerFilter['name']) ?>" id="<?= e($ownerFilter['name']) ?>" class="form-select form-select-sm" data-autosubmit aria-label="<?= e($ownerFilter['label']) ?>">
                <?php foreach ($ownerFilter['options'] as $k => $label): ?>
                    <option value="<?= e((string) $k) ?>"<?= (($filters[$ownerFilter['name']] ?? '') === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($statusField): ?>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select name="status" id="status" class="form-select form-select-sm" data-autosubmit aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($statusField['options'] as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= (($filters['status'] ?? '') === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>

<div class="os-table-wrap">
    <?php if ($result['total'] === 0): ?>
        <div class="os-empty">
            <div class="os-empty-title">No <?= e(strtolower($plural)) ?> found</div>
            <div><?= e($q !== '' || $filters ? 'Nothing matches the current filters.' : $emptyHint) ?></div>
            <?php if ($canWrite && $q === '' && !$filters): ?>
                <a class="btn btn-primary btn-sm mt-3" href="<?= e(url($base . '/create')) ?>">Create the first <?= e(strtolower($singular)) ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if (($bulkDelete || $bulkRateUpdate) && $canWrite): ?>
            <form method="post" action="<?= e(url($base . '/bulk-delete')) ?>" id="os-bulk-form">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" id="os-bulk-mode" value="selected">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <?php foreach ($filters as $fk => $fv): ?>
                    <input type="hidden" name="<?= e((string) $fk) ?>" value="<?= e((string) $fv) ?>">
                <?php endforeach; ?>
                <div class="d-flex flex-wrap gap-2 align-items-center px-2 py-2 border-bottom bg-light">
                    <?php if ($bulkDelete): ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete selected <?= e(strtolower($plural)) ?>? This cannot be undone." onclick="document.getElementById('os-bulk-mode').value='selected'; this.form.action='<?= e(url($base . '/bulk-delete')) ?>'">
                        <i class="bi bi-trash me-1"></i>Delete selected
                    </button>
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete ALL <?= e((string) $result['total']) ?> <?= e(strtolower($plural)) ?> matching this search/filter (not only this page)? This cannot be undone." onclick="document.getElementById('os-bulk-mode').value='filtered'; this.form.action='<?= e(url($base . '/bulk-delete')) ?>'">
                        <i class="bi bi-funnel me-1"></i>Delete matching filter (<?= e((string) $result['total']) ?>)
                    </button>
                    <?php endif; ?>
                    <?php if ($bulkRateUpdate): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#os-batch-rate-modal">
                        <i class="bi bi-percent me-1"></i>Batch rate update
                    </button>
                    <?php endif; ?>
                    <span class="small text-muted">Select rows below<?= $bulkDelete ? ', or delete everything that matches the current filter' : '' ?>.</span>
                </div>
        <?php endif; ?>
        <div class="os-table-scroll">
            <table class="os-table">
                <colgroup>
                    <?php if (($bulkDelete || $bulkRateUpdate) && $canWrite): ?><col style="width:2.25rem"><?php endif; ?>
                    <?php if (!$hideIdColumn): ?><col class="os-col-id"><?php endif; ?>
                    <?php foreach ($cols as $_): ?><col><?php endforeach; ?>
                    <col class="os-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <?php if (($bulkDelete || $bulkRateUpdate) && $canWrite): ?>
                            <th scope="col"><input type="checkbox" class="form-check-input" id="os-bulk-all" aria-label="Select all"></th>
                        <?php endif; ?>
                        <?php if (!$hideIdColumn): ?><th scope="col" class="os-col-id">ID</th><?php endif; ?>
                        <?php foreach ($cols as $c): ?><th scope="col"><?= e($c['label']) ?></th><?php endforeach; ?>
                        <th scope="col" class="os-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <?php if (($bulkDelete || $bulkRateUpdate) && $canWrite): ?>
                            <td><input type="checkbox" class="form-check-input os-bulk-id" name="ids[]" value="<?= e((string) $row['id']) ?>" form="os-bulk-form" aria-label="Select <?= e((string) $row['id']) ?>"></td>
                        <?php endif; ?>
                        <?php if (!$hideIdColumn): ?><td class="os-mono os-col-id"><?= e($row['id']) ?></td><?php endif; ?>
                        <?php foreach ($cols as $c): ?><td><?= $cell($c, $row[$c['name']] ?? null) ?></td><?php endforeach; ?>
                        <td class="os-col-actions">
                            <div class="os-actions-inner">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base . '/' . (int) $row['id'] . '/edit')) ?>">
                                    <i class="bi bi-<?= $canWrite ? 'pencil' : 'eye' ?>"></i> <?= $canWrite ? 'Edit' : 'View' ?>
                                </a>
                                <?php if ($canWrite): ?>
                                    <form method="post" action="<?= e(url($base . '/' . (int) $row['id'] . '/delete')) ?>" data-confirm="Delete this <?= e(strtolower($singular)) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (($bulkDelete || $bulkRateUpdate) && $canWrite): ?></form>
            <script>
            (function () {
                var all = document.getElementById('os-bulk-all');
                if (!all) return;
                all.addEventListener('change', function () {
                    document.querySelectorAll('.os-bulk-id').forEach(function (cb) { cb.checked = all.checked; });
                });
            })();
            </script>
            <?php if ($bulkRateUpdate): ?>
            <div class="modal fade" id="os-batch-rate-modal" tabindex="-1" aria-labelledby="os-batch-rate-title" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="post" action="<?= e(url($base . '/bulk-rate-update')) ?>" class="modal-content" id="os-batch-rate-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <?php foreach ($filters as $fk => $fv): ?>
                            <input type="hidden" name="<?= e((string) $fk) ?>" value="<?= e((string) $fv) ?>">
                        <?php endforeach; ?>
                        <div id="os-batch-rate-ids"></div>
                        <div class="modal-header">
                            <h5 class="modal-title" id="os-batch-rate-title">Batch rate update</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted">Applies to <strong>selected rows</strong> on this page. Optional prefix filter further narrows the selection.</p>
                            <div class="mb-3">
                                <label class="form-label" for="rate_mode">Mode</label>
                                <select name="rate_mode" id="rate_mode" class="form-select form-select-sm" required>
                                    <option value="percent">Percent change (e.g. 10 = +10%, -5 = −5%)</option>
                                    <option value="absolute">Flat new rate (absolute)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="rate_value">Value</label>
                                <input type="text" class="form-control form-control-sm os-mono" name="rate_value" id="rate_value" required placeholder="10 or 0.0125" pattern="-?\d+(\.\d{1,8})?">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="prefix_filter">Prefix filter (optional)</label>
                                <input type="text" class="form-control form-control-sm os-mono" name="prefix_filter" id="prefix_filter" placeholder="e.g. 1213" maxlength="32">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Update rates</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            (function () {
                var modal = document.getElementById('os-batch-rate-modal');
                if (!modal) return;
                modal.addEventListener('show.bs.modal', function () {
                    var box = document.getElementById('os-batch-rate-ids');
                    if (!box) return;
                    box.innerHTML = '';
                    document.querySelectorAll('.os-bulk-id:checked').forEach(function (cb) {
                        var i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = 'ids[]';
                        i.value = cb.value;
                        box.appendChild(i);
                    });
                });
            })();
            </script>
            <?php endif; ?>
        <?php endif; ?>
        <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
<?php unset($colCount); ?>
