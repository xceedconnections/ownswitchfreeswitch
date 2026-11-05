<?php
/** @var array $customers @var string $customer_id @var string $from @var string $to @var ?array $statement @var ?string $error */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Account Statements</h1>
        <p class="os-page-sub">Opening balance, ledger movements and closing balance for a user</p>
    </div>
</div>

<form method="get" class="os-toolbar row g-2 align-items-end mb-3" action="<?= e(url('/statements')) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="customer_id">User</label>
        <select class="form-select form-select-sm" id="customer_id" name="customer_id" required>
            <option value="">— Select —</option>
            <?php foreach ($customers as $id => $label): ?>
                <option value="<?= e((string) $id) ?>"<?= $customer_id === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="from">From</label>
        <input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="to">To</label>
        <input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
</form>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($statement): ?>
    <?php $c = $statement['customer']; ?>
    <div class="os-widget mb-3">
        <div class="os-widget-h">
            <h2 class="os-widget-title mb-0"><?= e((string) $c['name']) ?> · <?= e((string) $c['code']) ?></h2>
            <span class="text-muted small"><?= e($statement['from']) ?> → <?= e($statement['to']) ?> · <?= e((string) $c['currency']) ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><div class="lbl text-muted small">Opening</div><div class="os-mono fw-semibold"><?= e($statement['opening']) ?></div></div>
            <div class="col-md-4"><div class="lbl text-muted small">Closing (period)</div><div class="os-mono fw-semibold"><?= e($statement['closing']) ?></div></div>
            <div class="col-md-4"><div class="lbl text-muted small">Live balance</div><div class="os-mono fw-semibold"><?= e($statement['live_balance']) ?></div></div>
        </div>
    </div>
    <div class="os-table-wrap">
        <?php if (!$statement['rows']): ?>
            <div class="os-empty"><div class="os-empty-title">No movements</div><div>No balance-affecting ledger entries in this range.</div></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 os-table">
                <thead><tr><th>Time (<?= e(user_timezone()) ?>)</th><th>Type</th><th>Description</th><th class="os-num">Amount</th><th class="os-num">Balance</th></tr></thead>
                <tbody>
                <?php foreach ($statement['rows'] as $row): ?>
                    <tr>
                        <td class="os-mono"><?= e(fmt_local((string) $row['created_at'])) ?></td>
                        <td class="os-mono"><?= e((string) $row['entry_type']) ?></td>
                        <td><?= e((string) ($row['description'] ?? '—')) ?></td>
                        <td class="os-num os-mono"><?= e((string) $row['amount']) ?></td>
                        <td class="os-num os-mono"><?= e((string) $row['balance_after']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
<?php elseif ($customer_id === ''): ?>
    <div class="os-empty"><div class="os-empty-title">Select a user</div><div>Choose a user and date range to generate a statement.</div></div>
<?php endif; ?>
