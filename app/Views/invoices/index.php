<?php /** @var array $result @var array $customers @var bool $canGenerate */
$query = $_GET ?? [];
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Invoices <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Billing periods built from posted ledger charges</p>
    </div>
</div>
<?php if ($canGenerate && $customers): ?>
<form method="post" action="<?= e(url('/invoices/generate')) ?>" class="os-toolbar mb-3">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-0" for="customer_id">Customer</label>
            <select class="form-select form-select-sm" id="customer_id" name="customer_id" required><option value="">— select —</option>
                <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="period_start">Period start</label><input type="date" class="form-control form-control-sm" id="period_start" name="period_start" required></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="period_end">Period end</label><input type="date" class="form-control form-control-sm" id="period_end" name="period_end" required></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit" data-once><i class="bi bi-receipt me-1"></i>Generate</button></div>
    </div>
    <div class="form-text mt-2 mb-0">Built from posted ledger call charges only. Overlapping invoices for the same customer are refused.</div>
</form>
<?php endif; ?>
<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No invoices</div><div>Invoices are generated from posted ledger charges for a billing period.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>Number</th><th>Customer</th><th>Period</th><th class="os-num">Total</th><th>Cur</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($result['rows'] as $i): ?>
            <tr><td><a href="<?= e(url('/invoices/' . (int) $i['id'])) ?>" class="os-mono"><?= e($i['number']) ?></a></td><td><?= e($customers[$i['customer_id']] ?? '#' . $i['customer_id']) ?></td>
                <td class="os-mono"><?= e($i['period_start']) ?> – <?= e($i['period_end']) ?></td><td class="os-num os-mono"><?= e($i['total']) ?></td><td><?= e($i['currency']) ?></td>
                <td><span class="badge text-bg-<?= $i['status'] === 'paid' ? 'success' : ($i['status'] === 'void' ? 'dark' : 'info') ?>"><?= e($i['status']) ?></span></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
