<?php
/** @var array $group @var array $result @var string $q @var array $query @var bool $canWrite */
$canWrite = !empty($canWrite);
$gid = (int) $group['id'];
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e((string) $group['name']) ?> <span class="os-count"><?= e((string) $result['total']) ?></span></h1>
        <p class="os-page-sub">DNC numbers · assign this group on a trunk to block these destinations</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/dnc')) ?>">All groups</a>
        <?php if ($canWrite): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/dnc/' . $gid . '/edit')) ?>">Edit group</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/dnc/' . $gid . '/import?mode=add')) ?>"><i class="bi bi-upload me-1"></i>Import CSV/Excel</a>
            <a class="btn btn-outline-danger btn-sm" href="<?= e(url('/dnc/' . $gid . '/import?mode=delete')) ?>">Delete via file</a>
            <form method="post" action="<?= e(url('/dnc/' . $gid . '/rebuild-cache')) ?>" class="d-inline"><?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm" data-once>Rebuild cache</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canWrite): ?>
<form method="post" action="<?= e(url('/dnc/' . $gid . '/numbers')) ?>" class="os-toolbar row g-2 align-items-end mb-3">
    <?= csrf_field() ?>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0" for="number">Add number</label>
        <input class="form-control form-control-sm" id="number" name="number" required placeholder="12125551212" autocomplete="off">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0" for="note">Note</label>
        <input class="form-control form-control-sm" id="note" name="note" maxlength="255" placeholder="optional">
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm" data-once>Add</button></div>
</form>
<?php endif; ?>

<form method="get" action="<?= e(url('/dnc/' . $gid)) ?>" class="os-toolbar row g-2 align-items-end mb-3">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0" for="q">Search</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Digits…">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Search</button></div>
</form>

<div class="os-table-wrap">
<?php if ((int) $result['total'] === 0): ?>
    <div class="os-empty">
        <div class="os-empty-title"><?= $q !== '' ? 'No matches' : 'No numbers yet' ?></div>
        <div><?= $q !== '' ? 'Try another search.' : 'Add manually or import a CSV / Excel file.' ?></div>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('/dnc/' . $gid . '/numbers/delete')) ?>" data-confirm="Delete selected numbers?">
        <?= csrf_field() ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 os-table">
                <thead>
                <tr>
                    <?php if ($canWrite): ?><th style="width:2rem"></th><?php endif; ?>
                    <th>Number</th>
                    <th>Note</th>
                    <th>Added</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <?php if ($canWrite): ?>
                            <td><input type="checkbox" name="ids[]" value="<?= e((string) $row['id']) ?>"></td>
                        <?php endif; ?>
                        <td class="os-mono"><?= e((string) $row['number_digits']) ?></td>
                        <td class="small text-muted"><?= e((string) ($row['note'] ?? '—')) ?></td>
                        <td class="os-mono small"><?= e(substr((string) ($row['created_at'] ?? ''), 0, 19)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canWrite): ?>
            <div class="px-3 py-2 border-top">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-once>Delete selected</button>
            </div>
        <?php endif; ?>
        <?php include __DIR__ . '/../partials/pagination.php'; ?>
    </form>
<?php endif; ?>
</div>
