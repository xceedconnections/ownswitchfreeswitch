<?php
/** @var array $group @var string $step @var string $mode @var ?string $flash
 * @var ?string $token @var list $headers @var array $map @var list $sample
 * @var int $row_count @var ?array $preview @var list<string> $fields @var list<string> $required */
$gid = (int) $group['id'];
$base = '/dnc/' . $gid . '/import';
$isDelete = ($mode ?? 'add') === 'delete';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= $isDelete ? 'Delete via CSV / Excel' : 'Import DNC numbers' ?></h1>
        <p class="os-page-sub"><?= e((string) $group['name']) ?> · CSV or .xlsx</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/dnc/' . $gid)) ?>">Back to numbers</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-info"><?= e($flash) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<form method="post" action="<?= e(url($base . '/upload')) ?>" enctype="multipart/form-data" class="os-panel p-3 p-md-4 col-12">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <label class="form-label fw-semibold">File (CSV or Excel .xlsx)</label>
    <input type="file" name="file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    <p class="form-text">
        <?= $isDelete
            ? 'Numbers in the file will be <strong>removed</strong> from this group. Column: <code>number</code> (or first column).'
            : 'Numbers will be <strong>added</strong>. Columns: <code>number</code> (required), <code>note</code> (optional). Max 8 MB.' ?>
    </p>
    <button class="btn <?= $isDelete ? 'btn-danger' : 'btn-primary' ?> mt-2" type="submit" data-once>Upload &amp; map columns</button>
</form>
<?php endif; ?>

<?php if (in_array($step, ['map', 'preview'], true) && $token): ?>
<form method="post" action="<?= e(url($step === 'preview' && !empty($preview['ok']) ? $base . '/commit' : $base . '/preview')) ?>" class="os-panel p-3 p-md-4 mb-3 col-12">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <p class="small text-muted"><?= e((string) $row_count) ?> row(s)</p>
    <div class="row g-2">
        <?php foreach ($fields as $f): ?>
            <div class="col-md-6">
                <label class="form-label small mb-0"><?= e($f) ?><?= in_array($f, $required, true) ? ' <span class="text-danger">*</span>' : '' ?></label>
                <select name="map_<?= e($f) ?>" class="form-select form-select-sm">
                    <option value="">— select column —</option>
                    <?php foreach ($headers as $h): ?>
                        <option value="<?= e($h) ?>"<?= (($map[$f] ?? '') === $h) ? ' selected' : '' ?>><?= e($h) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($sample): ?>
        <div class="table-responsive mt-3"><table class="table table-sm table-bordered"><thead><tr><?php foreach ($headers as $h): ?><th class="small"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($sample as $row): ?><tr><?php foreach ($headers as $i => $_): ?><td class="small os-mono"><?= e((string) ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($step === 'preview' && is_array($preview)): ?>
        <?php if (!$preview['ok']): ?>
            <ul class="text-danger mt-2"><?php foreach ($preview['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            <button class="btn btn-primary btn-sm" type="submit" formaction="<?= e(url($base . '/preview')) ?>">Re-validate</button>
        <?php else: ?>
            <p class="text-success mt-2">Ready to <?= $isDelete ? 'delete' : 'import' ?> <?= e((string) count($preview['rows'])) ?> number(s).</p>
            <input type="hidden" name="map_number" value="<?= e($map['number'] ?? '') ?>">
            <input type="hidden" name="map_note" value="<?= e($map['note'] ?? '') ?>">
            <button class="btn <?= $isDelete ? 'btn-danger' : 'btn-success' ?> btn-sm me-2" type="submit" data-once><?= $isDelete ? 'Commit delete' : 'Commit import' ?></button>
        <?php endif; ?>
    <?php else: ?>
        <button class="btn btn-primary btn-sm mt-3" type="submit">Validate</button>
    <?php endif; ?>
</form>
<?php endif; ?>
