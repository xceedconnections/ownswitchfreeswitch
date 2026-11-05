<?php
/** @var array $customers @var array $values @var list<string> $errors */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Add payment</h1>
        <p class="os-page-sub">Credits the user’s account balance</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/payments')) ?>">Back</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/payments')) ?>" class="os-panel col-12" novalidate>
    <div class="p-3 p-md-4">
        <?= csrf_field() ?>
        <input type="hidden" name="nonce" value="<?= e(bin2hex(random_bytes(8))) ?>">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="customer_id">User <span class="text-danger">*</span></label>
                <select class="form-select" id="customer_id" name="customer_id" required>
                    <option value="">—</option>
                    <?php foreach ($customers as $id => $label): ?>
                        <option value="<?= e((string) $id) ?>"<?= ((string) $values['customer_id'] === (string) $id) ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="amount">Amount <span class="text-danger">*</span></label>
                <input class="form-control" type="text" id="amount" name="amount" value="<?= e((string) $values['amount']) ?>" inputmode="decimal" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="method">Method</label>
                <select class="form-select" id="method" name="method">
                    <?php foreach (['bank' => 'Bank transfer', 'cash' => 'Cash', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'] as $k => $l): ?>
                        <option value="<?= e($k) ?>"<?= ((string) $values['method'] === $k) ? ' selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="reference">Reference</label>
                <input class="form-control" type="text" id="reference" name="reference" value="<?= e((string) $values['reference']) ?>" maxlength="190" placeholder="Cheque / txn id">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="description">Description</label>
                <input class="form-control" type="text" id="description" name="description" value="<?= e((string) ($values['description'] ?? '')) ?>" maxlength="200" placeholder="Optional note">
            </div>
        </div>
    </div>
    <div class="border-top px-3 px-md-4 py-3 d-flex flex-wrap gap-2 bg-light rounded-bottom">
        <button type="submit" class="btn btn-primary" data-once>Add to account</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/payments')) ?>">Cancel</a>
    </div>
</form>
