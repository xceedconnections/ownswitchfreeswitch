<?php
/** @var array $result @var string $from @var string $to @var string $focus @var array $counts @var array $query @var bool $showCost */
$showCost = !empty($showCost);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">CDR Reconciliation <span class="os-count"><?= e((string) $result['total']) ?></span></h1>
        <p class="os-page-sub">Unrated, unattributed and rating-discrepancy CDRs for finance cleanup</p>
    </div>
</div>
<form method="get" action="<?= e(url('/reports/cdr-reconciliation')) ?>" class="os-toolbar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-0" for="focus">Focus</label>
            <select class="form-select form-select-sm" id="focus" name="focus" data-autosubmit>
                <option value="all"<?= $focus === 'all' ? ' selected' : '' ?>>All issues</option>
                <option value="unrated"<?= $focus === 'unrated' ? ' selected' : '' ?>>Unrated (<?= e((string) $counts['unrated']) ?>)</option>
                <option value="unattributed"<?= $focus === 'unattributed' ? ' selected' : '' ?>>Unattributed (<?= e((string) $counts['unattributed']) ?>)</option>
                <option value="discrepancy"<?= $focus === 'discrepancy' ? ' selected' : '' ?>>Rating discrepancy (<?= e((string) $counts['discrepancy']) ?>)</option>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="os-report-hero os-report-hero--finance mb-3">
    <div class="os-report-hero-stat"><span class="lbl">Issues</span><span class="val"><?= e(number_format((int) $result['total'])) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Unrated</span><span class="val"><?= e(number_format((int) ($counts['unrated'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Unattributed</span><span class="val"><?= e(number_format((int) ($counts['unattributed'] ?? 0))) ?></span></div>
    <div class="os-report-hero-stat"><span class="lbl">Discrepancy</span><span class="val"><?= e(number_format((int) ($counts['discrepancy'] ?? 0))) ?></span></div>
</div>
<div class="os-table-wrap os-report-card">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">Clean</div><div>No reconciliation issues in this range.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>ID</th><th>Setup</th><th>Disposition</th><th>Settlement</th><th>Disc.</th><th class="os-num"><?= $showCost ? 'Sell' : 'Charged' ?></th><?php if ($showCost): ?><th class="os-num">Buy</th><?php endif; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="os-mono"><?= e((string) $row['id']) ?></td>
                <td class="os-mono"><?= e(fmt_local((string) $row['setup_at'])) ?></td>
                <td><?= e((string) $row['disposition']) ?></td>
                <td class="os-mono"><?= e((string) $row['settlement_status']) ?></td>
                <td><?= !empty($row['rating_discrepancy']) ? 'Yes' : '—' ?></td>
                <td class="os-num os-mono"><?= e((string) ($row['customer_charge'] ?? '0')) ?></td>
                <?php if ($showCost): ?><td class="os-num os-mono"><?= e((string) ($row['carrier_cost'] ?? '0')) ?></td><?php endif; ?>
                <td><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs/' . (int) $row['id'])) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
