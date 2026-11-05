<?php
/** @var array<string,string> $values @var array<string,string> $errors @var ?array $result */
$in = static function (string $name, string $label, string $help = '') use ($values, $errors): void {
    $err = $errors[$name] ?? null; ?>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-0" for="<?= e($name) ?>"><?= e($label) ?></label>
        <input class="form-control form-control-sm<?= $err ? ' is-invalid' : '' ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($values[$name] ?? '') ?>" inputmode="decimal" maxlength="16">
        <?php if ($err): ?><div class="invalid-feedback"><?= e($err) ?></div><?php elseif ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Billing Preview</h1>
        <p class="os-page-sub">Canonical RatingService formula · decimal bcmath · customer and carrier increments independent</p>
    </div>
</div>

<form method="post" action="<?= e(url('/billing/preview')) ?>" id="billing-preview-form" class="os-panel p-3 p-md-4 mb-3" novalidate>
    <?= csrf_field() ?>
    <h2 class="h6 text-uppercase text-muted">Customer side</h2>
    <div class="row g-2 mb-3">
        <?php $in('rate', 'Rate per minute', 'e.g. 0.0100'); ?>
        <?php $in('initial', 'Initial increment I (s)'); ?>
        <?php $in('subsequent', 'Subsequent increment S (s)'); ?>
        <?php $in('duration', 'Call duration D (s)'); ?>
        <?php $in('connect_fee', 'Connection fee'); ?>
    </div>
    <h2 class="h6 text-uppercase text-muted">Carrier side (optional, independent increments)</h2>
    <div class="row g-2 mb-3">
        <?php $in('carrier_rate', 'Carrier rate per minute', 'blank = skip carrier'); ?>
        <?php $in('carrier_initial', 'Carrier I (s)'); ?>
        <?php $in('carrier_subsequent', 'Carrier S (s)'); ?>
        <?php $in('carrier_connect_fee', 'Carrier connection fee'); ?>
    </div>
    <div class="d-flex align-items-center gap-3"><button type="submit" class="btn btn-primary btn-sm">Calculate</button><span class="small text-muted" id="billing-live-hint"></span></div>
</form>

<?php if ($result): $c = $result['customer']; ?>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header">Customer result</div><div class="card-body">
            <dl class="row mb-0">
                <dt class="col-6">Billable seconds</dt><dd class="col-6 os-mono"><?= e($c['billable_seconds']) ?></dd>
                <dt class="col-6">Usage charge</dt><dd class="col-6 os-mono"><?= e($c['usage_charge']) ?></dd>
                <dt class="col-6">Connection fee</dt><dd class="col-6 os-mono"><?= e($c['connect_fee']) ?></dd>
                <dt class="col-6">Total</dt><dd class="col-6 os-mono fw-bold"><?= e($c['total']) ?></dd>
            </dl>
            <h3 class="h6 mt-3">Step breakdown</h3>
            <table class="table table-sm mb-0"><?php foreach ($result['steps'] as $s): ?>
                <tr><td><?= e($s['label']) ?></td><td class="os-num"><?= e($s['seconds']) ?>s</td><td class="os-num os-mono"><?= e($s['amount']) ?></td></tr>
            <?php endforeach; ?></table>
        </div></div></div>
        <?php if ($result['carrier']): $k = $result['carrier']; ?>
            <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header">Carrier &amp; margin</div><div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Increment</dt><dd class="col-6 os-mono"><?= e($k['initial']) ?>/<?= e($k['subsequent']) ?></dd>
                    <dt class="col-6">Billable seconds</dt><dd class="col-6 os-mono"><?= e($k['billable_seconds']) ?></dd>
                    <dt class="col-6">Carrier cost</dt><dd class="col-6 os-mono"><?= e($k['total']) ?></dd>
                    <dt class="col-6">Margin</dt><dd class="col-6 os-mono fw-bold"><?= e($result['margin']) ?></dd>
                </dl>
            </div></div></div>
        <?php endif; ?>
        <div class="col-12"><div class="os-panel"><div class="card-header">Side-by-side comparison (same rate &amp; duration)</div>
            <div class="table-responsive"><table class="table table-sm mb-0 os-table">
                <thead><tr><th>Increment</th><th class="os-num">Billable s</th><th class="os-num">Usage</th><th class="os-num">Fee</th><th class="os-num">Total</th></tr></thead>
                <tbody><?php foreach ($result['comparison'] as $label => $x): ?>
                    <tr><td><?= e($label) ?></td><td class="os-num"><?= e($x['billable_seconds']) ?></td><td class="os-num os-mono"><?= e($x['usage_charge']) ?></td><td class="os-num os-mono"><?= e($x['connect_fee']) ?></td><td class="os-num os-mono"><?= e($x['total']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div></div></div>
    </div>
<?php endif; ?>
