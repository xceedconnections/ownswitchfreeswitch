<?php /** @var list<array> $checks @var array $counts @var string $php @var string $time */
$badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'unconfigured' => 'secondary'];
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">System Status</h1>
        <p class="os-page-sub">PHP <?= e($php) ?> · <?= e($time) ?></p>
    </div>
</div>
<?php if (!empty($sipTip)): ?>
<div class="alert alert-warning mb-3" role="alert"><?= e($sipTip) ?></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="os-table-wrap">
            <div class="table-responsive">
                <table class="table table-sm mb-0 os-table">
                    <thead><tr><th>Component</th><th>Status</th><th>Detail</th></tr></thead>
                    <tbody><?php foreach ($checks as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($c['component']) ?></td>
                            <td><span class="badge text-bg-<?= e($badge[$c['status']] ?? 'secondary') ?>"><?= e($c['status']) ?></span></td>
                            <td class="text-muted"><?= e($c['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="os-panel">
            <div class="os-panel-cap">Row counts</div>
            <table class="table table-sm mb-0 os-table">
                <?php foreach ($counts as $t => $n): ?>
                    <tr><td class="os-mono"><?= e($t) ?></td><td class="os-num fw-semibold"><?= e($n) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
