<?php
/**
 * @var array<string,string> $values @var list<string> $errors @var list<array<string,mixed>> $recent
 * @var ?string $balance @var ?string $currency @var bool $hasCustomer
 */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Redeem voucher</h1>
        <p class="os-page-sub">Enter a voucher code to add its value to your balance</p>
    </div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$hasCustomer): ?>
    <div class="os-panel"><div class="os-empty"><div class="os-empty-title">No customer account</div><div>Vouchers can only be redeemed from a customer login.</div></div></div>
<?php else: ?>
<?php if ($balance !== null): ?>
    <p class="mb-3">Current balance: <strong class="os-mono"><?= e(fmt_money($balance, 2)) ?></strong> <?= e((string) $currency) ?></p>
<?php endif; ?>
<form method="post" action="<?= e(url('/billing/redeem-voucher')) ?>" class="os-panel col-12 col-lg-6 p-3 p-md-4 mb-4">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold" for="code">Voucher code</label>
        <input class="form-control os-mono" id="code" name="code" value="<?= e($values['code'] ?? '') ?>" maxlength="32" placeholder="XXXX-XXXX-XXXX" autocomplete="off" autocapitalize="characters" required>
        <div class="form-text">Each code can be used once and may expire.</div>
    </div>
    <button class="btn btn-primary" type="submit" data-once><i class="bi bi-ticket-perforated me-1"></i>Redeem</button>
</form>

<?php if ($recent): ?>
<h2 class="h6 fw-semibold mb-2">Recent redemptions</h2>
<div class="os-table-wrap">
    <div class="os-table-scroll">
        <table class="os-table">
            <thead><tr><th scope="col">Code</th><th scope="col">Amount</th><th scope="col">Redeemed</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td class="os-mono"><?= e((string) $row['code']) ?></td>
                    <td class="os-mono"><?= e(fmt_money((string) $row['amount'], 2)) ?> <?= e((string) $row['currency']) ?></td>
                    <td class="os-mono small"><?= e(substr((string) $row['redeemed_at'], 0, 19)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
