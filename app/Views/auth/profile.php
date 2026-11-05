<?php
/** @var array $user @var string $timezone @var array<string,string> $options @var list<string> $errors */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">My profile</h1>
        <p class="os-page-sub">Timezone controls how CDR times and report date ranges appear for your account</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="os-panel p-3">
            <h2 class="h6 mb-3">Account</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-4 text-muted">Name</dt>
                <dd class="col-sm-8"><?= e((string) ($user['name'] ?? '')) ?></dd>
                <dt class="col-sm-4 text-muted">Email</dt>
                <dd class="col-sm-8"><?= e((string) ($user['email'] ?? '')) ?></dd>
                <dt class="col-sm-4 text-muted">Role</dt>
                <dd class="col-sm-8"><?= e((string) ($user['role'] ?? '')) ?></dd>
            </dl>
            <p class="mb-0 mt-3"><a href="<?= e(url('/account/password')) ?>">Change password</a></p>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="os-panel p-3">
            <h2 class="h6 mb-3">Timezone</h2>
            <form method="post" action="<?= e(url('/account/profile')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="timezone">Display timezone</label>
                    <select class="form-select" id="timezone" name="timezone" required>
                        <?php foreach ($options as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $timezone === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset($options[$timezone])): ?>
                            <option value="<?= e($timezone) ?>" selected><?= e($timezone) ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Example: a call at 2:00 AM New York time shows as 11:00 AM in Pakistan (PKT) when you select Asia/Karachi.
                        CDR search, failed CDR, and reporting date filters use this timezone.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" data-once>Save timezone</button>
            </form>
        </div>
    </div>
</div>
