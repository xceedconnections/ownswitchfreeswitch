<?php /** @var array $inv @var list<array> $lines @var array $customer */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Invoice <span class="os-mono"><?= e($inv['number']) ?></span></h1>
        <p class="os-page-sub">Invoice detail and line items</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/invoices')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<div class="os-panel col-12">
    <div class="p-3 p-md-4">
        <div class="row mb-3">
            <div class="col-md-6"><div class="text-muted small">Customer</div><div><?= e($customer['name'] ?? '—') ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Period</div><div class="os-mono"><?= e($inv['period_start']) ?> &ndash; <?= e($inv['period_end']) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Status</div><div><?= e($inv['status']) ?></div></div>
        </div>
        <table class="table table-sm os-table">
            <thead><tr><th>#</th><th>Description</th><th class="os-num">Calls</th><th class="os-num">Billable s</th><th class="os-num">Amount (<?= e($inv['currency']) ?>)</th></tr></thead>
            <tbody><?php foreach ($lines as $l): ?>
                <tr><td><?= e($l['line_no']) ?></td><td><?= e($l['description']) ?></td><td class="os-num"><?= e($l['quantity']) ?></td><td class="os-num"><?= e($l['seconds']) ?></td><td class="os-num os-mono"><?= e($l['amount']) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot>
                <tr><td colspan="4" class="text-end">Subtotal</td><td class="os-num os-mono"><?= e($inv['subtotal']) ?></td></tr>
                <tr><td colspan="4" class="text-end">Tax</td><td class="os-num os-mono"><?= e($inv['tax']) ?></td></tr>
                <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="os-num os-mono"><?= e($inv['total']) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>
