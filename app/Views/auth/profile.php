<?php
/** @var array $user @var string $timezone @var string $effective @var array<string,string> $options @var list<string> $errors */
$effective = $effective ?? $timezone;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">My profile</h1>
        <p class="os-page-sub">Times, dashboard today / week / month, and report dates use <?= e(\Ownswitch\Support\Timezone::label($effective)) ?></p>
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
                    <label class="form-label" for="timezone">Timezone</label>
                    <select class="form-select" id="timezone" name="timezone" required>
                        <option value="system"<?= $timezone === 'system' ? ' selected' : '' ?>>Same as system settings (<?= e(\Ownswitch\Support\Timezone::label(\Ownswitch\Support\Timezone::systemDefault())) ?>)</option>
                        <?php foreach (\Ownswitch\Support\Timezone::GROUPS as $region => $zones): ?>
                            <optgroup label="<?= e($region) ?>">
                                <?php foreach ($zones as $zoneId => $zoneLabel): ?>
                                    <option value="<?= e($zoneId) ?>"<?= $timezone === $zoneId ? ' selected' : '' ?>><?= e($zoneLabel) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                        <?php if ($timezone !== 'system' && !isset($options[$timezone])): ?>
                            <option value="<?= e($timezone) ?>" selected><?= e($timezone) ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Pick New York, London, Karachi, or another city. Dashboard totals, stats, CDR times, and report dates follow this clock. Same as system settings uses the timezone chosen under Settings.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" data-once>Save profile</button>
            </form>
        </div>
    </div>
</div>
