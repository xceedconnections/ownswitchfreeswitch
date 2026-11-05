<?php
/** @var ?array $group @var array $data @var array $errors @var bool $canWrite */
$isEdit = $group !== null;
$canWrite = $canWrite ?? true;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($isEdit ? 'Edit DNC group' : 'New DNC group') ?></h1>
        <p class="os-page-sub">Groups are assigned on Trunks under Routes</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($isEdit ? '/dnc/' . (int) $group['id'] : '/dnc')) ?>">Back</a>
</div>

<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e((string) $errors['_form']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? '/dnc/' . (int) $group['id'] : '/dnc')) ?>" class="os-panel p-3 p-md-4 col-12 col-lg-8">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e((string) ($data['name'] ?? '')) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="active"<?= (($data['status'] ?? '') === 'active') ? ' selected' : '' ?>>Active</option>
            <option value="disabled"<?= (($data['status'] ?? '') === 'disabled') ? ' selected' : '' ?>>Disabled</option>
        </select>
        <div class="form-text">Disabled groups are ignored on the switch (cache cleared).</div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="notes">Notes</label>
        <input class="form-control" id="notes" name="notes" maxlength="255" value="<?= e((string) ($data['notes'] ?? '')) ?>">
    </div>
    <button type="submit" class="btn btn-primary" data-once><?= $isEdit ? 'Save' : 'Create' ?></button>
</form>

<?php if ($isEdit && $canWrite): ?>
<form method="post" action="<?= e(url('/dnc/' . (int) $group['id'] . '/delete')) ?>" class="mt-3" data-confirm="Delete this DNC group and all its numbers?">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-danger btn-sm" data-once>Delete group</button>
</form>
<?php endif; ?>
