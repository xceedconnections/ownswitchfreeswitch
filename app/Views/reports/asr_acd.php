<?php
/** @var list<array> $rows @var array $total @var string $from @var string $to @var string $group @var ?string $period @var list<array> $periodRows @var bool $canCarrier */
$groups = ['day' => 'Day', 'customer' => 'Customer', 'prefix' => 'Destination prefix'] + ($canCarrier ? ['carrier' => 'Carrier'] : []);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Summary per Day</h1>
        <p class="os-page-sub">ASR = answered ÷ attempts · ACD = talk seconds ÷ answered · totals from summed counters</p>
    </div>
</div>

<form method="get" action="<?= e(url('/reports/summary-day')) ?>" class="os-toolbar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="group">Group by</label>
            <select class="form-select form-select-sm" id="group" name="group"><?php foreach ($groups as $k => $l): ?><option value="<?= e($k) ?>"<?= $group === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="period">Period rollup</label>
            <select class="form-select form-select-sm" id="period" name="period">
                <option value="">None</option><option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Weekly (from aggregates)</option><option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Monthly (from aggregates)</option>
            </select></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-fill me-1"></i>Run</button></div>
    </div>
</form>

<div class="os-table-wrap mb-4">
<?php if ($total['attempts'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No call attempts in this period</div><div>Nothing to report for <?= e($from) ?> – <?= e($to) ?>.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th><?= e($groups[$group] ?? 'Group') ?></th><th class="os-num">Attempts</th><th class="os-num">Answered</th><th class="os-num">ASR %</th><th class="os-num">Talk (s)</th><th class="os-num">ACD (s)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td class="os-mono"><?= e($r['label']) ?></td><td class="os-num"><?= e($r['attempts']) ?></td><td class="os-num"><?= e($r['answered']) ?></td>
                <td class="os-num"><?= e($r['asr']) ?></td><td class="os-num"><?= e($r['seconds']) ?></td><td class="os-num"><?= e($r['acd']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="table-light fw-semibold"><td>Total (sum-based)</td><td class="os-num"><?= e($total['attempts']) ?></td><td class="os-num"><?= e($total['answered']) ?></td>
            <td class="os-num"><?= e($total['asr']) ?></td><td class="os-num"><?= e($total['seconds']) ?></td><td class="os-num"><?= e($total['acd']) ?></td></tr></tfoot>
    </table></div>
<?php endif; ?>
</div>

<?php if ($period): ?>
    <p class="os-section-label"><?= e($period === 'week' ? 'Weekly' : 'Monthly') ?> rollup (from stats_daily)</p>
    <div class="os-table-wrap">
    <?php if (!$periodRows): ?>
        <div class="os-empty"><div class="os-empty-title">No aggregate data</div><div>Run <code>php bin/rollup_stats.php</code> to populate daily aggregates.</div></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0 os-table">
            <thead><tr><th>Period</th><th class="os-num">Attempts</th><th class="os-num">Answered</th><th class="os-num">ASR %</th><th class="os-num">ACD (s)</th></tr></thead>
            <tbody><?php foreach ($periodRows as $r): ?>
                <tr><td class="os-mono"><?= e($r['period']) ?></td><td class="os-num"><?= e($r['attempts']) ?></td><td class="os-num"><?= e($r['answered']) ?></td><td class="os-num"><?= e($r['asr']) ?></td><td class="os-num"><?= e($r['acd']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    </div>
<?php endif; ?>
