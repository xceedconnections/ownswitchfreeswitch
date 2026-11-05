<?php
/** @var array $group @var list<array<string,mixed>> $numbers @var bool $canWrite @var string $base */
$gid = (int) $group['id'];
$canWrite = !empty($canWrite);
?>
<div class="os-panel col-12 mt-3">
    <div class="p-3 p-md-4">
        <h2 class="h6 fw-semibold mb-1">Prefix → CLI numbers <span class="os-count"><?= e((string) count($numbers)) ?></span></h2>
        <p class="small text-muted mb-3">The longest prefix that matches the dialled number selects the outbound CallerID. Use <code>*</code> as a default for any destination. Several rows with the same prefix are allowed.</p>

        <?php if ($canWrite): ?>
        <form method="post" action="<?= e(url($base . '/' . $gid . '/numbers')) ?>" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="cgn_prefix">Prefix</label>
                <input class="form-control form-control-sm os-mono" id="cgn_prefix" name="prefix" maxlength="32" placeholder="1 or *" value="*" autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="cgn_cli">CLI number</label>
                <input class="form-control form-control-sm os-mono" id="cgn_cli" name="cli_number" maxlength="32" required placeholder="12135550123" autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="cgn_name">Name</label>
                <input class="form-control form-control-sm" id="cgn_name" name="name" maxlength="64" placeholder="optional">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="cgn_status">Status</label>
                <select class="form-select form-select-sm" id="cgn_status" name="status">
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm" data-once>Add</button></div>
        </form>
        <?php endif; ?>

        <?php if (!$numbers): ?>
            <div class="text-muted small">No numbers yet.</div>
        <?php else: ?>
        <div class="os-table-scroll">
            <table class="os-table">
                <thead>
                <tr>
                    <th scope="col">Prefix</th>
                    <th scope="col">CLI number</th>
                    <th scope="col">Name</th>
                    <th scope="col">Status</th>
                    <?php if ($canWrite): ?><th scope="col" class="os-col-actions">Actions</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($numbers as $n): ?>
                    <tr>
                        <td class="os-mono"><?= e((string) $n['prefix']) ?></td>
                        <td class="os-mono"><?= e((string) $n['cli_number']) ?></td>
                        <td><?= e((string) ($n['name'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge text-bg-<?= $n['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst((string) $n['status'])) ?></span></td>
                        <?php if ($canWrite): ?>
                        <td class="os-col-actions">
                            <form method="post" action="<?= e(url($base . '/' . $gid . '/numbers/' . (int) $n['id'] . '/delete')) ?>" data-confirm="Remove this number?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Delete"><i class="bi bi-trash"></i></button>
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
</div>
