<?php
/** @var list<array> $rows @var array $totals @var ?string $error @var string $refreshedAt */
$acdFmt = static function (string $acd): string {
    $s = (int) round((float) $acd);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Stats · Carriers</h1>
        <p class="os-page-sub">Live trunk activity · Trying / Ringing / Connected · today's ASR &amp; ACD · <?= e($refreshedAt) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/stats/users')) ?>">Users</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/stats/carriers')) ?>"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-warning"><?= e($error) ?></div>
<?php endif; ?>

<div class="os-dash-grid os-dash-kpis mb-3">
    <div class="os-kpi os-kpi--blue"><div class="lbl">Active calls</div><div class="val"><?= e((string) ($totals['active'] ?? 0)) ?></div><div class="sub">all trunks</div></div>
    <div class="os-kpi os-kpi--amber"><div class="lbl">Trying</div><div class="val"><?= e((string) ($totals['trying'] ?? 0)) ?></div><div class="sub">100 Trying</div></div>
    <div class="os-kpi os-kpi--violet"><div class="lbl">Ringing</div><div class="val"><?= e((string) ($totals['ringing'] ?? 0)) ?></div><div class="sub">180 / 183</div></div>
    <div class="os-kpi os-kpi--green"><div class="lbl">Connected</div><div class="val"><?= e((string) ($totals['connected'] ?? 0)) ?></div><div class="sub">answered</div></div>
</div>

<div class="os-table-wrap os-report-card">
<?php if ($rows === []): ?>
    <div class="os-empty"><div class="os-empty-title">No activity</div><div>No live trunk calls and no CDRs for today yet.</div></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead>
            <tr>
                <th>Trunk</th>
                <th>Carrier</th>
                <th class="os-num">Active</th>
                <th class="os-num">Trying</th>
                <th class="os-num">Ringing</th>
                <th class="os-num">Connected</th>
                <th class="os-num">Failed</th>
                <th class="os-num">Answered</th>
                <th class="os-num">ASR today</th>
                <th class="os-num">ACD today</th>
                <th class="os-num">Attempts</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr<?= ((int) ($row['active'] ?? 0) > 0) ? ' class="table-success"' : '' ?>>
                    <td><strong><?= e((string) ($row['name'] ?? '—')) ?></strong></td>
                    <td><?= e((string) ($row['carrier'] ?? '—')) ?></td>
                    <td class="os-num os-mono"><?= e((string) ($row['active'] ?? 0)) ?></td>
                    <td class="os-num"><?= e((string) ($row['trying'] ?? 0)) ?></td>
                    <td class="os-num"><?= e((string) ($row['ringing'] ?? 0)) ?></td>
                    <td class="os-num fw-semibold"><?= e((string) ($row['connected'] ?? 0)) ?></td>
                    <td class="os-num"><?= e((string) max(0, (int) ($row['attempts_today'] ?? 0) - (int) ($row['answered_today'] ?? 0))) ?></td>
                    <td class="os-num"><?= e((string) ($row['answered_today'] ?? 0)) ?></td>
                    <td class="os-num"><?= e((string) ($row['asr'] ?? '0.00')) ?>%</td>
                    <td class="os-num os-mono"><?= e($acdFmt((string) ($row['acd'] ?? '0'))) ?></td>
                    <td class="os-num text-muted"><?= e((string) ($row['attempts_today'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small text-muted px-3 py-2 mb-0">Live trunk attribution uses the dialog callee contact host matched to trunk host. Early legs without a trunk host appear under Unattributed. ASR/ACD are from today's CDRs.</p>
<?php endif; ?>
</div>
<meta http-equiv="refresh" content="5;url=<?= e(url('/stats/carriers')) ?>">
