<?php
/** @var string $kind @var int $owner_id @var string $step @var ?string $flash @var ?string $token
 * @var list<string> $headers @var array $map @var list $sample @var int $row_count @var ?array $preview
 * @var array $owners @var list<string> $fields @var list<string> $required */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Tariff import / export</h1>
        <p class="os-page-sub">CSV or Excel · map columns · validate · commit</p>
    </div>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-info"><?= e($flash) ?></div><?php endif; ?>

<form method="get" action="<?= e(url('/tariffs/export')) ?>" class="os-panel p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-0">Kind</label>
            <select name="kind" class="form-select form-select-sm" data-autosubmit>
                <option value="customer"<?= $kind === 'customer' ? ' selected' : '' ?>>Customer tariffs (plan)</option>
                <option value="carrier"<?= $kind === 'carrier' ? ' selected' : '' ?>>Provider rates</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-0"><?= $kind === 'carrier' ? 'Provider' : 'Rate plan' ?></label>
            <select name="owner_id" class="form-select form-select-sm" required>
                <option value="">— select —</option>
                <?php foreach ($owners as $id => $name): ?>
                    <option value="<?= e((string) $id) ?>"<?= (int) $owner_id === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-download me-1"></i>Download CSV</button></div>
    </div>
</form>

<?php if ($step === 'upload'): ?>
<form method="post" action="<?= e(url('/tariffs/import/upload')) ?>" enctype="multipart/form-data" class="os-panel p-3 p-md-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Kind</label>
            <select name="kind" id="kind" class="form-select" onchange="this.form.action=this.form.action; location='?kind='+this.value">
                <option value="customer"<?= $kind === 'customer' ? ' selected' : '' ?>>Customer</option>
                <option value="carrier"<?= $kind === 'carrier' ? ' selected' : '' ?>>Provider rates</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold"><?= $kind === 'carrier' ? 'Provider' : 'Rate plan' ?></label>
            <select name="owner_id" class="form-select" required>
                <option value="">— select —</option>
                <?php foreach ($owners as $id => $name): ?>
                    <option value="<?= e((string) $id) ?>"<?= (int) $owner_id === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label fw-semibold">File (CSV or Excel .xlsx)</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </div>
    </div>
    <button class="btn btn-primary mt-3" type="submit" data-once>Upload &amp; map columns</button>
</form>
<?php endif; ?>

<?php if (in_array($step, ['map', 'preview'], true) && $token): ?>
<form method="post" action="<?= e(url($step === 'preview' && !empty($preview['ok']) ? '/tariffs/import/commit' : '/tariffs/import/preview')) ?>" class="os-panel p-3 p-md-4 mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <input type="hidden" name="owner_id" value="<?= (int) $owner_id ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <p class="small text-muted mb-3"><?= e((string) $row_count) ?> data row(s) · map each Ownswitch field to a column from your file</p>
    <div class="row g-2">
        <?php foreach ($fields as $f): ?>
            <div class="col-md-6 col-lg-4">
                <label class="form-label small mb-0"><?= e($f) ?><?= in_array($f, $required, true) ? ' <span class="text-danger">*</span>' : '' ?></label>
                <select name="map_<?= e($f) ?>" class="form-select form-select-sm">
                    <option value="">— skip —</option>
                    <?php foreach ($headers as $h): ?>
                        <option value="<?= e($h) ?>"<?= (($map[$f] ?? '') === $h) ? ' selected' : '' ?>><?= e($h) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($sample): ?>
        <p class="form-text mt-3 mb-1">Sample rows</p>
        <div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0"><thead><tr><?php foreach ($headers as $h): ?><th class="small"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($sample as $row): ?><tr><?php foreach ($headers as $i => $_): ?><td class="small os-mono"><?= e((string) ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($step === 'preview' && is_array($preview)): ?>
        <?php if (!$preview['ok']): ?>
            <ul class="text-danger"><?php foreach ($preview['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            <button class="btn btn-primary btn-sm" type="submit" formaction="<?= e(url('/tariffs/import/preview')) ?>">Re-validate</button>
        <?php else: ?>
            <p class="text-success">Ready to import <?= e((string) count($preview['rows'])) ?> row(s).</p>
            <?php foreach ($map as $f => $h): ?><input type="hidden" name="map_<?= e($f) ?>" value="<?= e($h) ?>"><?php endforeach; ?>
            <button class="btn btn-success btn-sm me-2" type="submit" data-once>Commit import</button>
            <button class="btn btn-outline-secondary btn-sm" type="submit" formaction="<?= e(url('/tariffs/import/preview')) ?>">Re-validate</button>
        <?php endif; ?>
        <?php if (!empty($preview['rows'])): ?>
            <div class="table-responsive mt-3"><table class="table table-sm table-striped"><thead><tr><?php foreach (['prefix','destination','rate','initial_increment','subsequent_increment','connect_fee','status'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach (array_slice($preview['rows'], 0, 40) as $row): ?>
                    <tr><?php foreach (['prefix','destination','rate','initial_increment','subsequent_increment','connect_fee','status'] as $h): ?><td class="os-mono"><?= e((string) ($row[$h] ?? '')) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    <?php else: ?>
        <button class="btn btn-primary mt-3" type="submit" data-once>Validate mapping</button>
    <?php endif; ?>
</form>
<a class="small" href="<?= e(url('/tariffs/import?kind=' . urlencode($kind))) ?>">← Start over</a>
<?php endif; ?>
