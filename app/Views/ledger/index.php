<?php /** @var array $result @var array $customers @var array $filters @var ?string $balance @var list<string> $types @var bool $canReverse @var array $query */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Ledger <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Append-only balance movements</p>
    </div>
    <?php if ($balance !== null): ?>
        <div class="os-card-stat"><div class="card-body py-2 px-3">
            <div class="os-stat-label mb-0">Balance</div>
            <div class="os-stat-value os-mono os-stat-sm"><?= e(fmt_money($balance, 6)) ?></div>
        </div></div>
    <?php endif; ?>
</div>
<form method="get" action="<?= e(url('/ledger')) ?>" class="os-toolbar row g-2 align-items-end">
    <?php if ($customers): ?>
        <div class="col-md-4"><label class="form-label small mb-1" for="customer_id">Customer</label>
            <select class="form-select form-select-sm" id="customer_id" name="customer_id" data-autosubmit aria-label="Customer"><option value="">All customers</option>
            <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= ($filters['customer_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-md-3"><label class="form-label small mb-1" for="entry_type">Type</label>
        <select class="form-select form-select-sm" id="entry_type" name="entry_type" data-autosubmit aria-label="Type"><option value="">All types</option>
        <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= ($filters['entry_type'] ?? '') === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>
<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No ledger entries</div><div>Entries appear after top-ups and rated calls.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>ID</th><th>Time (UTC)</th><?php if ($customers): ?><th>Customer</th><?php endif; ?><th>Type</th><th class="os-num">Amount</th><th>Cur</th><th>Counts to balance</th><th>Description</th><th>Ref</th><?php if ($canReverse): ?><th></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($result['rows'] as $e): ?>
            <tr>
                <td class="os-mono"><?= e($e['id']) ?></td><td class="os-mono"><?= e(substr((string) $e['created_at'], 0, 19)) ?></td>
                <?php if ($customers): ?><td><?= e($customers[$e['customer_id']] ?? '#' . $e['customer_id']) ?></td><?php endif; ?>
                <td><span class="badge text-bg-secondary"><?= e($e['entry_type']) ?></span></td>
                <td class="os-num os-mono <?= bccomp((string) $e['amount'], '0', 6) < 0 ? 'text-danger' : 'text-success' ?>"><?= e($e['amount']) ?></td>
                <td><?= e($e['currency']) ?></td><td><?= $e['affects_balance'] ? 'yes' : 'hold only' ?></td>
                <td><?= e($e['description']) ?></td><td class="os-mono"><?= e($e['reference_entry_id'] ?? $e['cdr_id'] ?? '') ?></td>
                <?php if ($canReverse): ?><td class="os-actions">
                    <?php if (!in_array($e['entry_type'], ['reversal', 'reservation', 'reservation_release'], true)): ?>
                        <form method="post" action="<?= e(url('/ledger/' . (int) $e['id'] . '/reverse')) ?>" data-confirm="Post a reversing entry for #<?= e($e['id']) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="reason" value="Manual reversal">
                            <button class="btn btn-outline-warning btn-sm" type="submit">Reverse</button></form>
                    <?php endif; ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
