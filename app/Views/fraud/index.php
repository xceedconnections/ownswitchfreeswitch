<?php /** @var array $result @var list<array> $rules @var bool $canManage @var array $filters */ $query = $_GET ?? []; ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Fraud Alerts <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Signalling/CDR-based detection (velocity, short-call ratio, destination spread)</p>
    </div>
    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/fraud/scan')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit" data-once><i class="bi bi-shield-exclamation me-1"></i>Scan recent CDRs</button></form>
    <?php endif; ?>
</div>
<div class="os-table-wrap mb-4">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No fraud events</div><div>Nothing has been flagged. Run <code>php bin/worker_cdr.php</code> to ingest CDRs, then scan.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>Time (UTC)</th><th>Rule</th><th>Severity</th><th>Customer</th><th>Summary</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($result['rows'] as $ev): ?>
            <tr><td class="os-mono"><?= e(substr((string) $ev['created_at'], 0, 19)) ?></td><td class="os-mono"><?= e($ev['rule_code']) ?></td>
                <td><span class="badge text-bg-<?= $ev['severity'] === 'high' ? 'danger' : 'warning' ?>"><?= e($ev['severity']) ?></span></td>
                <td class="os-mono"><?= e($ev['customer_id']) ?></td><td><?= e($ev['summary']) ?></td><td><?= e($ev['status']) ?></td>
                <td class="os-actions"><?php if ($ev['status'] === 'open'): ?>
                    <form method="post" action="<?= e(url('/fraud/' . (int) $ev['id'] . '/ack')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Acknowledge</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
<?php if ($rules): ?>
    <p class="os-section-label">Fraud rules</p>
    <div class="os-table-wrap"><div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th>Code</th><th>Type</th><th class="os-num">Threshold</th><th class="os-num">Window (s)</th><th>Action</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($rules as $rule): ?>
            <?php if ($canManage): ?>
            <tr>
                <td class="os-mono"><?= e($rule['code']) ?><div class="small text-muted"><?= e($rule['name']) ?></div></td>
                <td><?= e($rule['rule_type']) ?></td>
                <td colspan="4">
                    <form method="post" action="<?= e(url('/fraud/rules/' . (int) $rule['id'])) ?>" class="row g-1 align-items-center">
                        <?= csrf_field() ?>
                        <div class="col-auto"><input class="form-control form-control-sm os-mono" style="width:5.5rem" name="threshold" value="<?= e((string) $rule['threshold']) ?>" aria-label="Threshold"></div>
                        <div class="col-auto"><input class="form-control form-control-sm os-mono" style="width:5rem" name="window_seconds" value="<?= e((string) $rule['window_seconds']) ?>" aria-label="Window seconds"></div>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" name="action" aria-label="Action">
                                <option value="alert"<?= $rule['action'] === 'alert' ? ' selected' : '' ?>>alert</option>
                                <option value="suspend"<?= $rule['action'] === 'suspend' ? ' selected' : '' ?>>suspend</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" name="status" aria-label="Status">
                                <option value="active"<?= $rule['status'] === 'active' ? ' selected' : '' ?>>active</option>
                                <option value="disabled"<?= $rule['status'] === 'disabled' ? ' selected' : '' ?>>disabled</option>
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit" data-once>Save</button></div>
                    </form>
                </td>
            </tr>
            <?php else: ?>
            <tr><td class="os-mono"><?= e($rule['code']) ?></td><td><?= e($rule['rule_type']) ?></td><td class="os-num"><?= e($rule['threshold']) ?></td><td class="os-num"><?= e($rule['window_seconds']) ?></td><td><?= e($rule['action']) ?></td><td><?= e($rule['status']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?></tbody>
    </table></div></div>
<?php endif; ?>
