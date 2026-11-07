<?php
/** @var ?array $plan @var list<array> $tariffs @var list<array> $overrides @var list<array> $sipCaps */
$fmtCap = static function ($v): string {
    if ($v === null || $v === '') {
        return 'No CAP';
    }
    return (string) $v;
};
$fmtLim = static function ($v): string {
    $n = (int) $v;
    return $n < 0 ? 'Unlimited' : (string) $n;
};
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">My Tariff</h1>
        <p class="os-page-sub">Your sell rates and call limits (read-only)</p>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/my-rates/export')) ?>"><i class="bi bi-download me-1"></i>Download rates</a>
</div>

<?php if (!$plan): ?>
    <div class="alert alert-warning">Account not found.</div>
<?php else: ?>
    <div class="os-report-hero os-report-hero--summary mb-3">
        <div class="os-report-hero-stat">
            <span class="lbl">Plan</span>
            <span class="val" style="font-size:1.1rem"><?= e((string) ($plan['plan_name'] ?? 'No plan assigned')) ?></span>
        </div>
        <div class="os-report-hero-stat">
            <span class="lbl">Currency</span>
            <span class="val" style="font-size:1.1rem"><?= e((string) ($plan['currency'] ?? '—')) ?></span>
        </div>
        <div class="os-report-hero-stat">
            <span class="lbl">Default rate</span>
            <span class="val" style="font-size:1.1rem"><?= e((string) ($plan['default_rate'] ?? '—')) ?></span>
        </div>
        <div class="os-report-hero-stat">
            <span class="lbl">Increments</span>
            <span class="val" style="font-size:1rem"><?= e((string) ($plan['initial_increment'] ?? '—')) ?>/<?= e((string) ($plan['subsequent_increment'] ?? '—')) ?></span>
        </div>
    </div>

    <div class="os-widget mb-3">
        <div class="os-widget-h"><h2 class="os-widget-title">SIP limits &amp; CAP</h2></div>
        <?php if ($sipCaps === []): ?>
            <div class="small text-muted">No SIP users on this account.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 os-table">
                    <thead><tr><th>SIP user</th><th>Status</th><th class="os-num">CC</th><th class="os-num">CPS</th><th class="os-num">CAP (tariff $/min)</th></tr></thead>
                    <tbody>
                    <?php foreach ($sipCaps as $s): ?>
                        <tr>
                            <td class="os-mono"><?= e((string) $s['username']) ?></td>
                            <td><?= e((string) $s['status']) ?></td>
                            <td class="os-num"><?= e($fmtLim($s['max_channels'] ?? 0)) ?></td>
                            <td class="os-num"><?= e($fmtLim($s['max_cps'] ?? 0)) ?></td>
                            <td class="os-num os-mono"><?= e($fmtCap($s['rate_cap'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-text mb-0 mt-2">SIP CAP rejects calls when the customer tariff (sell) rate for the dialled prefix is higher than the CAP. Trunk CAP (on Providers → Trunks) separately limits by provider buy rate.</p>
        <?php endif; ?>
    </div>

    <?php if ($overrides !== []): ?>
    <div class="os-table-wrap os-report-card mb-3">
        <div class="px-3 pt-3"><h2 class="os-widget-title mb-2">Custom rates</h2></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 os-table">
                <thead><tr><th>Prefix</th><th class="os-num">Rate</th><th class="os-num">I/S</th><th class="os-num">Connect</th></tr></thead>
                <tbody>
                <?php foreach ($overrides as $row): ?>
                    <tr>
                        <td class="os-mono"><?= e((string) $row['prefix']) ?></td>
                        <td class="os-num os-mono"><?= e((string) $row['rate']) ?></td>
                        <td class="os-num"><?= e((string) ($row['initial_increment'] ?? '—')) ?>/<?= e((string) ($row['subsequent_increment'] ?? '—')) ?></td>
                        <td class="os-num os-mono"><?= e((string) ($row['connect_fee'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="os-table-wrap os-report-card">
        <div class="px-3 pt-3"><h2 class="os-widget-title mb-2">Plan rates</h2></div>
        <?php if ($tariffs === []): ?>
            <div class="os-empty"><div class="os-empty-title">No rates listed</div><div>Ask your provider to assign a rate plan with tariffs.</div></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 os-table">
                    <thead><tr><th>Prefix</th><th>Name</th><th>Code</th><th>Destination</th><th class="os-num">Rate</th><th class="os-num">I/S</th><th class="os-num">Connect</th></tr></thead>
                    <tbody>
                    <?php foreach ($tariffs as $row): ?>
                        <tr>
                            <td class="os-mono"><?= e((string) (($row['dial_prefix'] ?? '') !== '' ? $row['dial_prefix'] : '—')) ?></td>
                            <td><?= e((string) (($row['dial_name'] ?? '') !== '' ? $row['dial_name'] : '—')) ?></td>
                            <td class="os-mono"><?= e((string) $row['prefix']) ?></td>
                            <td><?= e((string) ($row['destination'] ?? '—')) ?></td>
                            <td class="os-num os-mono"><?= e((string) $row['rate']) ?></td>
                            <td class="os-num"><?= e((string) ($row['initial_increment'] ?? '—')) ?>/<?= e((string) ($row['subsequent_increment'] ?? '—')) ?></td>
                            <td class="os-num os-mono"><?= e((string) ($row['connect_fee'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
