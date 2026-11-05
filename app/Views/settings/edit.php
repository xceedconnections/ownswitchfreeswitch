<?php
/** @var array $schema @var array $values @var array<string,string> $errors @var ?string $logoUrl */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Settings</h1>
        <p class="os-page-sub">Branding, billing, capacity limits, failover and emergency kill switch</p>
    </div>
</div>
<form method="post" action="<?= e(url('/settings')) ?>" enctype="multipart/form-data" class="os-panel col-12">
    <div class="p-3 p-md-4">
        <?= csrf_field() ?>

        <div class="mb-4 pb-3 border-bottom">
            <label class="form-label" for="switch_logo">Switch logo</label>
            <?php if (!empty($logoUrl)): ?>
                <div class="mb-2 p-2 border rounded bg-light d-inline-block">
                    <img src="<?= e($logoUrl) ?>" alt="Current logo" style="max-height:48px;max-width:220px;object-fit:contain">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="switch_logo_clear" id="switch_logo_clear" value="1">
                    <label class="form-check-label" for="switch_logo_clear">Remove current logo</label>
                </div>
            <?php endif; ?>
            <input class="form-control<?= isset($errors['switch_logo']) ? ' is-invalid' : '' ?>" type="file" id="switch_logo" name="switch_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            <?php if (isset($errors['switch_logo'])): ?><div class="invalid-feedback d-block"><?= e($errors['switch_logo']) ?></div><?php endif; ?>
            <div class="form-text">PNG / JPG / WEBP / SVG · max 2 MB · shown in the top navigation for admin and customers</div>
        </div>

        <?php foreach ($schema as $key => [$label, $type, $help]):
            if ($type === 'logo') {
                continue;
            }
            $err = $errors[$key] ?? null;
            $val = $values[$key] ?? '';
            ?>
            <div class="mb-3">
                <?php if ($type === 'bool'): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"<?= in_array($val, ['1', 'true'], true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    </div>
                <?php elseif ($type === 'media'): ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <select class="form-select" id="<?= e($key) ?>" name="<?= e($key) ?>">
                        <option value="relay"<?= $val === 'relay' ? ' selected' : '' ?>>Relay — media via FreeSWITCH</option>
                        <option value="direct"<?= $val === 'direct' ? ' selected' : '' ?>>Direct — peer-to-peer RTP (bypass_media)</option>
                        <option value="record"<?= $val === 'record' ? ' selected' : '' ?>>Record — relay + record_session</option>
                    </select>
                <?php else: ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="form-control<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>" maxlength="<?= in_array($key, ['switch_name', 'failover_sip_codes'], true) ? '80' : '32' ?>">
                <?php endif; ?>
                <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="border-top px-3 px-md-4 py-3 bg-light rounded-bottom">
        <button class="btn btn-primary" type="submit" data-once><i class="bi bi-check2 me-1"></i>Save settings</button>
    </div>
</form>
