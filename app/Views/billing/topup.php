<?php /** @var array $customers @var array $values @var list<string> $errors */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Refills</h1>
        <p class="os-page-sub">Posts an append-only ledger entry — mistakes are fixed with a reversal</p>
    </div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$customers): ?>
    <div class="os-panel"><div class="os-empty"><div class="os-empty-title">No customers</div><div>Create a customer first.</div></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/billing/refill')) ?>" class="os-panel col-12 p-3 p-md-4">
    <?= csrf_field() ?>
    <input type="hidden" name="nonce" value="<?= e(bin2hex(random_bytes(8))) ?>">
    <div class="mb-3"><label class="form-label fw-semibold" for="customer_id">Customer</label>
        <select class="form-select" id="customer_id" name="customer_id" required><option value="">— select —</option>
            <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $values['customer_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <div class="mb-3"><label class="form-label fw-semibold" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <option value="topup"<?= $values['type'] === 'topup' ? ' selected' : '' ?>>Top-up (credit)</option>
            <option value="adjustment"<?= $values['type'] === 'adjustment' ? ' selected' : '' ?>>Adjustment (+/-)</option>
            <option value="refund"<?= $values['type'] === 'refund' ? ' selected' : '' ?>>Refund (debit)</option>
        </select></div>
    <div class="mb-3"><label class="form-label fw-semibold" for="amount">Amount</label>
        <input class="form-control" id="amount" name="amount" value="<?= e($values['amount']) ?>" inputmode="decimal" maxlength="20" required>
        <div class="form-text">Positive decimal, up to 6 places. Adjustments may be negative.</div></div>
    <div class="mb-3"><label class="form-label fw-semibold" for="description">Description / reason</label>
        <input class="form-control" id="description" name="description" value="<?= e($values['description']) ?>" maxlength="200" required></div>
    <button class="btn btn-primary" type="submit" data-once><i class="bi bi-wallet2 me-1"></i>Post entry</button>
</form>
<?php endif; ?>
