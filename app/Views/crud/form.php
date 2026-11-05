<?php
/**
 * @var string $title @var string $base @var string $singular @var list<array<string,mixed>> $fields
 * @var array $values @var array<string,string> $errors @var ?array $existing @var bool $canWrite
 */
$action = $existing ? $base . '/' . (int) $existing['id'] : $base;
$isEdit = $existing !== null;
$canWrite = $canWrite ?? true;
$viewOnly = !$canWrite;
?>
<?php
$formSub = $viewOnly
    ? 'Read-only — contact your provider to change these settings'
    : ($isEdit ? 'Update existing record' : 'Create a new ' . strtolower($singular));
if (($base ?? '') === '/trunk-groups' && !$viewOnly) {
    $formSub = 'Choose which trunks carry your calls and in what order. Each call tries your first trunk; if it can’t connect, it moves to the next one on your list.';
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= e($formSub) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base)) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<?php
$sections = [];
foreach ($fields as $f) {
    $sec = (string) ($f['section'] ?? 'General');
    $sections[$sec][] = $f;
}
$sectionIds = array_keys($sections);
$useTabs = count($sectionIds) > 1;
?>
<?php
$sipAuthForm = (($base ?? '') === '/sip-users');
$formExtra = $sipAuthForm ? ' data-os-sip-auth' : '';
?>
<?php if ($viewOnly): ?>
<div class="os-panel col-12"<?= $formExtra ?>>
<?php else: ?>
<form method="post" action="<?= e(url($action)) ?>" class="os-panel col-12" novalidate<?= $formExtra ?>>
<?php endif; ?>
    <div class="p-3 p-md-4">
        <?php if (!$viewOnly): ?><?= csrf_field() ?><?php endif; ?>
        <?php if ($useTabs): ?>
            <ul class="nav nav-tabs os-form-tabs mb-3" role="tablist">
                <?php foreach ($sectionIds as $i => $sec):
                    $tid = 'tab_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sec));
                    ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="<?= e($tid) ?>-btn" data-bs-toggle="tab" data-bs-target="#<?= e($tid) ?>" type="button" role="tab"><?= e($sec) ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-content">
        <?php endif; ?>

        <?php foreach ($sections as $sec => $secFields):
            $tid = 'tab_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($sec));
            $si = array_search($sec, $sectionIds, true);
            ?>
            <?php if ($useTabs): ?><div class="tab-pane fade<?= $si === 0 ? ' show active' : '' ?>" id="<?= e($tid) ?>" role="tabpanel"><?php endif; ?>
            <div class="row g-3<?= $useTabs ? ' mb-2' : '' ?>">
            <?php foreach ($secFields as $f):
                if (!empty($f['list_only'])) {
                    continue;
                }
                $name = (string) $f['name'];
                $type = (string) ($f['type'] ?? 'text');
                if ($viewOnly && $type === 'password') {
                    continue; // never show password fields in read-only view
                }
                $readonly = $viewOnly || ($isEdit && !empty($f['readonly_on_edit']));
                $val = array_key_exists($name, $values) ? $values[$name] : ($f['default'] ?? '');
                if ($type === 'password') {
                    $val = '';
                }
                $id = 'f_' . $name;
                $err = $errors[$name] ?? null;
                $cls = 'form-control' . ($err ? ' is-invalid' : '');
                $wide = in_array($type, ['textarea', 'password', 'multiselect', 'trunk_waterfall'], true) || !empty($f['wide']);
                $addLabel = (string) ($f['add_label'] ?? ('+ Add ' . strtolower((string) ($f['label'] ?? 'item')) . '…'));
                ?>
                <div class="<?= $wide ? 'col-12' : 'col-12 col-md-6' ?>">
                    <?php if ($type === 'trunk_waterfall'):
                        $wfMeta = $f['trunk_meta'] ?? [];
                        $wfOpts = $f['options'] ?? [];
                        $wfStatsWindow = (int) ($f['stats_window'] ?? 60);
                        $wfOn = [];
                        if (is_array($val)) {
                            $wfOn = array_values(array_filter(array_map('strval', $val)));
                        } else {
                            $wfOn = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
                        }
                        $wfOn = array_values(array_filter($wfOn, static fn ($c) => isset($wfOpts[$c]) || isset($wfMeta[$c])));
                        $wfOffRaw = (string) ($values['trunk_off_ids'] ?? '');
                        $wfOff = array_values(array_filter(array_map('trim', explode(',', $wfOffRaw)), static fn ($x) => $x !== '' && !in_array($x, $wfOn, true) && (isset($wfOpts[$x]) || isset($wfMeta[$x]))));
                        $wfUsed = array_merge($wfOn, $wfOff);
                        require __DIR__ . '/../routing/_waterfall.php';
                    elseif ($type === 'checkbox'): ?>
                        <div class="form-check mt-md-4">
                            <input class="form-check-input" type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= !empty($val) ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
                            <label class="form-check-label" for="<?= e($id) ?>"><?= e($f['label']) ?></label>
                        </div>
                    <?php else: ?>
                        <label class="form-label fw-semibold" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                        <?php if ($type === 'select'): ?>
                            <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $readonly ? ' disabled' : '' ?>>
                                <?php if (empty($f['required']) && !isset($f['options'][''])): ?><option value="">—</option><?php endif; ?>
                                <?php foreach ($f['options'] as $k => $label): ?>
                                    <option value="<?= e($k) ?>"<?= ((string) $val === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($type === 'multiselect'):
                            $opts = $f['options'] ?? [];
                            $selected = [];
                            if (is_array($val)) {
                                $selected = array_values(array_filter(array_map('strval', $val)));
                            } else {
                                $selected = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
                            }
                            $selected = array_values(array_filter($selected, static fn ($c) => isset($opts[$c])));
                            ?>
                            <div class="os-ms<?= $err ? ' is-invalid' : '' ?>" data-os-multiselect data-name="<?= e($name) ?>"<?= $readonly ? ' data-readonly="1"' : '' ?>>
                                <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e(implode(',', $selected)) ?>">
                                <div class="os-ms-chips" data-chips>
                                    <?php foreach ($selected as $code): ?>
                                        <span class="os-ms-chip" data-value="<?= e($code) ?>">
                                            <span class="os-ms-chip-label"><?= e($opts[$code] ?? $code) ?></span>
                                            <?php if (!$readonly): ?>
                                                <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove <?= e($code) ?>">&times;</button>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!$readonly): ?>
                                    <select class="form-select form-select-sm os-ms-add" data-add aria-label="<?= e($addLabel) ?>">
                                        <option value=""><?= e($addLabel) ?></option>
                                        <?php foreach ($opts as $k => $label): ?>
                                            <option value="<?= e($k) ?>"<?= in_array((string) $k, $selected, true) ? ' disabled' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <template data-chip-tpl>
                                    <span class="os-ms-chip" data-value="">
                                        <span class="os-ms-chip-label"></span>
                                        <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove">&times;</button>
                                    </span>
                                </template>
                            </div>
                        <?php elseif ($type === 'textarea'): ?>
                            <textarea class="<?= e($cls) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= e((string) ($f['rows'] ?? 3)) ?>"<?= isset($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?> placeholder="<?= e((string) ($f['placeholder'] ?? '')) ?>"><?= e($val) ?></textarea>
                        <?php else:
                            $htmlType = match ($type) { 'email' => 'email', 'password' => 'password', default => 'text' };
                            $inputmode = in_array($type, ['int', 'prefix'], true) ? 'numeric' : ($type === 'number' ? 'decimal' : 'text');
                            ?>
                            <input class="<?= e($cls) ?>" type="<?= e($htmlType) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"
                                   inputmode="<?= e($inputmode) ?>" placeholder="<?= e((string) ($f['placeholder'] ?? '')) ?>"<?= isset($f['max']) && !in_array($type, ['int', 'number'], true) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?>
                                   <?= $readonly ? ' readonly' : '' ?><?= $type === 'password' ? ' autocomplete="new-password"' : '' ?>>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                    <?php if (!empty($f['help']) && $type !== 'trunk_waterfall'): ?><div class="form-text"><?= e($f['help']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if ($useTabs): ?></div><?php endif; ?>
        <?php endforeach; ?>

        <?php if ($useTabs): ?></div><?php endif; ?>
    </div>
    <div class="border-top px-3 px-md-4 py-3 d-flex flex-wrap gap-2 bg-light rounded-bottom">
        <?php if (!$viewOnly): ?>
            <button type="submit" class="btn btn-primary" data-once><i class="bi bi-check2 me-1"></i>Save</button>
            <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Cancel</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>"><i class="bi bi-arrow-left me-1"></i>Back to list</a>
        <?php endif; ?>
    </div>
<?php if ($viewOnly): ?></div><?php else: ?></form><?php endif; ?>
