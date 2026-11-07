<?php /** @var ?array $group @var array $errors @var array $data */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub">A named list you can assign to one or more SIP users</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cli-restrictions')) ?>">Back</a>
</div>
<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e((string) $errors['_form']) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/cli-restrictions')) ?>" class="os-panel col-12 col-lg-8 p-3 p-md-4">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="name">Name</label>
        <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e((string) ($data['name'] ?? '')) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="active"<?= (($data['status'] ?? 'active') === 'active') ? ' selected' : '' ?>>Active</option>
            <option value="disabled"<?= (($data['status'] ?? '') === 'disabled') ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="notes">Notes</label>
        <input class="form-control" id="notes" name="notes" maxlength="255" value="<?= e((string) ($data['notes'] ?? '')) ?>">
    </div>
    <button class="btn btn-primary" type="submit" data-once>Save</button>
</form>
