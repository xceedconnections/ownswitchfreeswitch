<?php
/** @var array $result @var array $customers @var string $customer_id @var bool $canWrite @var bool $isCustomer @var array $query */
$isCustomer = !empty($isCustomer);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Payments <span class="os-count"><?= e((string) $result['total']) ?></span></h1>
        <p class="os-page-sub"><?= $isCustomer ? 'Credits added to your account' : 'Credits added to user accounts' ?></p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/payments/create')) ?>"><i class="bi bi-plus-lg me-1"></i>Add payment</a>
    <?php endif; ?>
</div>

<?php if (!$isCustomer): ?>
<form method="get" class="os-toolbar row g-2 align-items-end mb-3" action="<?= e(url('/payments')) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="customer_id">User</label>
        <select class="form-select form-select-sm" id="customer_id" name="customer_id" data-autosubmit>
            <option value="">All users</option>
            <?php foreach ($customers as $id => $label): ?>
                <option value="<?= e((string) $id) ?>"<?= $customer_id === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>
<?php endif; ?>

<div class="os-table-wrap os-report-card">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty">
        <div class="os-empty-title">No payments</div>
        <div><?= $isCustomer ? 'No credits have been added to your account yet.' : 'Add a payment to credit a user’s prepaid balance.' ?></div>
        <?php if ($canWrite): ?><a class="btn btn-primary btn-sm mt-3" href="<?= e(url('/payments/create')) ?>">Add the first payment</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr>
            <th>Time (UTC)</th>
            <?php if (!$isCustomer): ?><th>User</th><?php endif; ?>
            <th>Method</th><th>Reference</th><th>Description</th><th class="os-num">Amount</th>
        </tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="os-mono"><?= e(substr((string) $row['created_at'], 0, 19)) ?></td>
                <?php if (!$isCustomer): ?>
                    <td><?= e((string) $row['customer_name']) ?> <span class="text-muted os-mono"><?= e((string) $row['customer_code']) ?></span></td>
                <?php endif; ?>
                <td><?= e((string) ($row['payment_method'] ?? '—')) ?></td>
                <td class="os-mono"><?= e((string) ($row['payment_reference'] ?? $row['external_ref'] ?? '—')) ?></td>
                <td class="small"><?= e((string) ($row['description'] ?? '—')) ?></td>
                <td class="os-num os-mono text-success fw-semibold">+<?= e((string) $row['amount']) ?> <?= e((string) ($row['currency'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
