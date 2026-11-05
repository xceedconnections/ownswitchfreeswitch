<?php /** @var list<string> $errors */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Change password</h1>
        <p class="os-page-sub">Update the password for your portal account</p>
    </div>
</div>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>
<form method="post" action="<?= e(url('/account/password')) ?>" class="os-panel col-12 p-3 p-md-4">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="current">Current password</label>
        <input type="password" class="form-control" id="current" name="current" required autocomplete="current-password">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="new">New password</label>
        <input type="password" class="form-control" id="new" name="new" required autocomplete="new-password" minlength="12">
        <div class="form-text">At least 12 characters with upper-case, lower-case and a digit.</div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="confirm">Confirm new password</label>
        <input type="password" class="form-control" id="confirm" name="confirm" required autocomplete="new-password" minlength="12">
    </div>
    <button class="btn btn-primary" type="submit" data-once><i class="bi bi-key me-1"></i>Update password</button>
</form>
