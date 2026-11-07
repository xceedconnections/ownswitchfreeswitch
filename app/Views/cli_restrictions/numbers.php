<?php /** @var array $group @var list<array<string,mixed>> $numbers @var string $q @var bool $canWrite */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e((string) $group['name']) ?></h1>
        <p class="os-page-sub">Calls that present one of these caller IDs are refused with SIP 403 and recorded as CLI restricted.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cli-restrictions')) ?>">All lists</a>
</div>
<?php if (!empty($canWrite)): ?>
<form method="post" action="<?= e(url('/cli-restrictions/' . (int) $group['id'])) ?>" class="os-panel p-3 mb-3">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1" for="name">Name</label>
            <input class="form-control form-control-sm" id="name" name="name" required maxlength="120" value="<?= e((string) $group['name']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <option value="active"<?= ($group['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="disabled"<?= ($group['status'] ?? '') === 'disabled' ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1" for="notes">Notes</label>
            <input class="form-control form-control-sm" id="notes" name="notes" maxlength="255" value="<?= e((string) ($group['notes'] ?? '')) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-primary btn-sm" type="submit">Save list</button>
        </div>
    </div>
</form>
<form method="post" action="<?= e(url('/cli-restrictions/' . (int) $group['id'] . '/numbers')) ?>" class="os-panel p-3 mb-3">
    <?= csrf_field() ?>
    <label class="form-label fw-semibold" for="numbers">Add caller IDs</label>
    <textarea class="form-control os-mono" id="numbers" name="numbers" rows="4" placeholder="12035551212&#10;2035551213"></textarea>
    <div class="form-text">One caller ID per line, or separated by commas. Digits only are stored.</div>
    <button class="btn btn-primary btn-sm mt-2" type="submit">Add caller IDs</button>
</form>
<?php endif; ?>
<form method="get" class="os-toolbar row g-2 align-items-end mb-2" action="<?= e(url('/cli-restrictions/' . (int) $group['id'])) ?>">
    <div class="col-md-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <input class="form-control form-control-sm os-mono" id="q" name="q" value="<?= e($q) ?>" placeholder="203">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>
<div class="os-table-wrap">
<?php if ($numbers === []): ?>
    <div class="os-empty"><div class="os-empty-title">No caller IDs in this list</div></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0 os-table">
            <thead><tr><th>Caller ID</th><?php if (!empty($canWrite)): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($numbers as $n): ?>
                <tr>
                    <td class="os-mono"><?= e((string) $n['cli_digits']) ?></td>
                    <?php if (!empty($canWrite)): ?>
                    <td class="text-end">
                        <form method="post" action="<?= e(url('/cli-restrictions/' . (int) $group['id'] . '/numbers/' . (int) $n['id'] . '/delete')) ?>" data-confirm="Remove this caller ID?">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline-danger btn-sm" type="submit">Remove</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<?php if (!empty($canWrite)): ?>
<form method="post" action="<?= e(url('/cli-restrictions/' . (int) $group['id'] . '/delete')) ?>" class="mt-3" data-confirm="Delete this whole list? SIP users will no longer use it.">
    <?= csrf_field() ?>
    <button class="btn btn-outline-danger btn-sm" type="submit">Delete list</button>
</form>
<?php endif; ?>
