<?php
/** @var array $result @var string $q @var string $action @var string $entity_type @var array $query */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Audit Log <span class="os-count"><?= e((string) $result['total']) ?></span></h1>
        <p class="os-page-sub">Who changed rates, balances, routes, settings and permissions</p>
    </div>
</div>

<form method="get" class="os-toolbar row g-2 align-items-end mb-3" action="<?= e(url('/audit-logs')) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Action, entity, IP…">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="action">Action</label>
        <input type="text" class="form-control form-control-sm" id="action" name="action" value="<?= e($action) ?>" maxlength="64">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="entity_type">Entity</label>
        <input type="text" class="form-control form-control-sm" id="entity_type" name="entity_type" value="<?= e($entity_type) ?>" maxlength="64">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>

<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No audit entries</div><div>Changes appear here after operators save rates, balances, routes or settings.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Entity</th><th>ID</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="os-mono"><?= e(substr((string) $row['created_at'], 0, 19)) ?></td>
                <td><?= e((string) ($row['actor_email'] ?? ('#' . ($row['actor_user_id'] ?? '')))) ?></td>
                <td class="os-mono"><?= e((string) $row['action']) ?></td>
                <td class="os-mono"><?= e((string) ($row['entity_type'] ?? '—')) ?></td>
                <td class="os-mono"><?= e((string) ($row['entity_id'] ?? '—')) ?></td>
                <td class="os-mono"><?= e((string) ($row['ip'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
