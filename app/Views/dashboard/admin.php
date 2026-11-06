<?php
/** @var array $metrics @var bool $showDemo @var bool $isGlobal @var bool $isStaff @var array $counts */
$w = $metrics['window60'];
$t = $metrics['today'];
$failed = $metrics['failed24'];
$traffic = $metrics['traffic60'];
$daily = $metrics['daily7'];
$routes = $metrics['routes'];
$maxBar = 1;
foreach ($traffic as $b) {
    $maxBar = max($maxBar, $b['answered'] + $b['failed']);
}
$maxMin = 1.0;
foreach ($daily as $d) {
    $maxMin = max($maxMin, (float) $d['minutes']);
}
$acdFmt = static function (string $acd): string {
    $s = (int) round((float) $acd);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$flowing = $w['attempts'] > 0;
$routeAsr = (float) ($w['asr'] ?? 0);
$routeAttempts = (int) ($w['attempts'] ?? 0);
if ($routeAttempts === 0) {
    $routePill = 'No recent calls';
    $routePillClass = 'os-pill';
} elseif ($routeAsr <= 0) {
    $routePill = 'No answers';
    $routePillClass = 'os-pill os-pill-warn';
} elseif ($routeAsr < 30) {
    $routePill = 'Low ASR';
    $routePillClass = 'os-pill os-pill-warn';
} else {
    $routePill = 'Healthy';
    $routePillClass = 'os-pill os-pill-ok';
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Dashboard</h1>
        <p class="os-page-sub">Live traffic · spend · routing · UTC</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>">CDR</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/users')) ?>">Users</a>
    </div>
</div>

<?php if ($showDemo): ?>
    <div class="alert alert-warning">Demo data is present (seeded DEMO rows).</div>
<?php endif; ?>

<div class="os-banner">
    <div>
        <div class="os-banner-title">
            <span class="os-dot <?= $flowing ? 'os-dot-ok' : '' ?>" style="<?= $flowing ? '' : 'background:#c9a227' ?>"></span>
            <?= $flowing ? 'Calls flowing' : 'Quiet right now' ?>
        </div>
        <div class="small text-muted mt-1">
            <?= e(number_format($w['attempts'])) ?> attempts in 60 min ·
            <?= e($w['asr']) ?>% answered ·
            <?= e(number_format($counts['customers'] ?? 0)) ?> users ·
            <?= e(number_format($counts['sip'] ?? 0)) ?> SIP
            <?php if (($counts['openFraud'] ?? 0) > 0): ?>
                · <a href="<?= e(url('/fraud')) ?>"><?= e((string) $counts['openFraud']) ?> fraud open</a>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/system/status')) ?>">System status</a>
</div>

<div class="os-host-grid" data-os-host data-url="<?= e(url('/api/live/host')) ?>">
    <div class="os-kpi os-kpi--amber">
        <div class="lbl">CPU</div>
        <div class="val"><span data-h="cpu_pct">—</span>%</div>
        <div class="sub"><span data-h="cpu_cores">—</span> cores · load <span data-h="load1">—</span></div>
        <div class="os-host-meter" data-h-meter="cpu_pct"><span style="width:0%"></span></div>
    </div>
    <div class="os-kpi os-kpi--blue">
        <div class="lbl">RAM</div>
        <div class="val"><span data-h="ram_pct">—</span>%</div>
        <div class="sub"><span data-h="ram_used_mb">—</span> / <span data-h="ram_total_mb">—</span> MB</div>
        <div class="os-host-meter" data-h-meter="ram_pct"><span style="width:0%"></span></div>
    </div>
    <div class="os-kpi os-kpi--violet">
        <div class="lbl">Disk</div>
        <div class="val"><span data-h="disk_free_gb">—</span> GB</div>
        <div class="sub">free of <span data-h="disk_total_gb">—</span> GB (<span data-h="disk_pct">—</span>% used)</div>
        <div class="os-host-meter" data-h-meter="disk_pct"><span style="width:0%"></span></div>
    </div>
    <div class="os-kpi os-kpi--teal">
        <div class="lbl">Network ↓</div>
        <div class="val"><span data-h="net_rx_mbps">—</span></div>
        <div class="sub">Mbps down · <span data-h="net_iface">—</span></div>
    </div>
    <div class="os-kpi os-kpi--green">
        <div class="lbl">Network ↑</div>
        <div class="val"><span data-h="net_tx_mbps">—</span></div>
        <div class="sub">Mbps up · live sample</div>
    </div>
    <div class="os-kpi os-kpi--slate">
        <div class="lbl">Host</div>
        <div class="val" style="font-size:1rem"><span data-h="hostname">—</span></div>
        <div class="sub" data-h="sampled_at">—</div>
    </div>
</div>

<div class="os-dash-grid os-dash-kpis mb-3">
    <div class="os-kpi os-kpi--teal">
        <div class="lbl">Attempts (60 min)</div>
        <div class="val"><?= e(number_format($w['attempts'])) ?></div>
        <div class="sub"><?= e(number_format($w['last_min'])) ?> in the last full minute</div>
    </div>
    <div class="os-kpi os-kpi--green">
        <div class="lbl">Answered (60 min)</div>
        <div class="val"><?= e(number_format($w['answered'])) ?></div>
        <div class="sub">Avg talk <?= e($acdFmt($w['acd'])) ?></div>
    </div>
    <div class="os-kpi os-kpi--blue">
        <div class="lbl">Answer rate</div>
        <div class="val"><?= e($w['asr']) ?>%</div>
        <div class="sub">Today <?= e($t['asr']) ?>%</div>
    </div>
    <div class="os-kpi os-kpi--amber">
        <div class="lbl">Spend (60 min)</div>
        <div class="val os-mono"><?= e(fmt_money($w['spend'], 2)) ?></div>
        <div class="sub"><?= e(fmt_money($t['spend'], 2)) ?> today</div>
    </div>
    <div class="os-kpi os-kpi--violet">
        <div class="lbl">Users / SIP</div>
        <div class="val"><?= e(number_format($counts['customers'] ?? 0)) ?></div>
        <div class="sub"><?= e(number_format($counts['sip'] ?? 0)) ?> active SIP</div>
    </div>
    <div class="os-kpi os-kpi--slate">
        <div class="lbl">Routing</div>
        <div class="val" style="font-size:1.15rem"><?= e($routePill) ?></div>
        <div class="sub"><?= e((string) ($w['answered'] ?? 0)) ?> / <?= e((string) ($w['attempts'] ?? 0)) ?> answered · <?= e((string) ($metrics['trunk_groups'] ?? 0)) ?> groups</div>
    </div>
</div>

<?php if (!empty($isStaff)):
    $moneyToday = $metrics['moneyToday'] ?? ['buy' => '0', 'sell' => '0', 'profit' => '0'];
    $moneyMonth = $metrics['moneyMonth'] ?? ['buy' => '0', 'sell' => '0', 'profit' => '0'];
?>
<div class="os-dash-grid os-dash-mid mb-3">
    <div class="os-widget">
        <div class="os-widget-h"><h2 class="os-widget-title">Today · buying, selling, profit</h2></div>
        <div class="os-dash-grid" style="grid-template-columns:repeat(3,1fr);gap:.75rem">
            <div class="os-kpi os-kpi--amber"><div class="lbl">Buying</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyToday['buy'], 4)) ?></div><div class="sub">Carrier cost today</div></div>
            <div class="os-kpi os-kpi--green"><div class="lbl">Selling</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyToday['sell'], 4)) ?></div><div class="sub">Customer charge today</div></div>
            <div class="os-kpi os-kpi--teal"><div class="lbl">Profit</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyToday['profit'], 4)) ?></div><div class="sub">Selling − buying</div></div>
        </div>
    </div>
    <div class="os-widget">
        <div class="os-widget-h"><h2 class="os-widget-title">This month · buying, selling, profit</h2></div>
        <div class="os-dash-grid" style="grid-template-columns:repeat(3,1fr);gap:.75rem">
            <div class="os-kpi os-kpi--amber"><div class="lbl">Buying</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyMonth['buy'], 4)) ?></div><div class="sub">Carrier cost this month</div></div>
            <div class="os-kpi os-kpi--green"><div class="lbl">Selling</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyMonth['sell'], 4)) ?></div><div class="sub">Customer charge this month</div></div>
            <div class="os-kpi os-kpi--teal"><div class="lbl">Profit</div><div class="val os-mono" style="font-size:1.2rem"><?= e(fmt_money($moneyMonth['profit'], 4)) ?></div><div class="sub">Selling − buying</div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="os-dash-grid os-dash-mid mb-3">
    <div class="os-widget">
        <div class="os-widget-h">
            <h2 class="os-widget-title">Traffic · completed calls, last 60 minutes</h2>
        </div>
        <?php if (!$traffic): ?>
            <div class="os-empty py-4"><div class="os-empty-title">No traffic yet</div><div>Bars appear as CDRs arrive.</div></div>
        <?php else: ?>
            <div class="os-bars" aria-hidden="true">
                <?php foreach ($traffic as $b):
                    $tot = $b['answered'] + $b['failed'];
                    $h = $tot > 0 ? (100 * $tot / $maxBar) : 0;
                    $ap = $tot > 0 ? (100 * $b['answered'] / $tot) : 0;
                    $fp = 100 - $ap;
                    ?>
                    <div class="os-bar-stack" title="<?= e($b['minute'] . ': ' . $b['answered'] . ' ans / ' . $b['failed'] . ' fail') ?>">
                        <div class="os-bar-a" style="height: <?= e((string) round($h * $ap / 100, 1)) ?>%"></div>
                        <div class="os-bar-f" style="height: <?= e((string) round($h * $fp / 100, 1)) ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="os-legend">
                <span><i style="background:var(--os-teal)"></i>Answered</span>
                <span><i style="background:#d9e0e7"></i>Not answered</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="os-widget">
        <div class="os-widget-h">
            <h2 class="os-widget-title">Failed calls · 24 h</h2>
            <span class="small text-muted"><?= e($failed['pct']) ?>%</span>
        </div>
        <div class="mb-2">
            <strong><?= e(number_format($failed['total'])) ?></strong>
            <span class="text-muted"> of <?= e(number_format($failed['attempts'])) ?></span>
        </div>
        <?php foreach ($failed['reasons'] as $reason): ?>
            <div class="os-fail-row">
                <div class="meta"><span><?= e($reason['label']) ?> · <?= e((string) $reason['count']) ?></span><span><?= e((string) $reason['pct']) ?>%</span></div>
                <div class="os-fail-track"><div class="os-fail-fill" style="width:<?= e((string) min(100, $reason['pct'])) ?>%"></div></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$failed['reasons']): ?>
            <div class="small text-muted">No failed calls in the last 24 hours.</div>
        <?php endif; ?>
        <div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>">View call history</a></div>
    </div>

    <div class="os-widget">
        <div class="os-widget-h">
            <h2 class="os-widget-title">Routing</h2>
            <span class="<?= e($routePillClass) ?>"><?= e($routePill) ?></span>
        </div>
        <?php if (!$routes): ?>
            <div class="small text-muted mb-2">No trunk groups yet.</div>
            <a href="<?= e(url('/trunk-groups')) ?>">Create trunk group</a>
        <?php else: ?>
            <?php foreach ($routes as $route): ?>
                <div class="os-route-row">
                    <div>
                        <strong><?= e($route['name']) ?></strong>
                        <span class="badge text-bg-light ms-1"><?= e($route['role']) ?></span>
                        <div class="small text-muted"><?= e($route['status']) ?></div>
                    </div>
                    <div class="text-end small">
                        <div><?= (int) ($route['attempts'] ?? 0) > 0 ? e((string) $route['asr']) . '% ASR' : '— ASR' ?></div>
                        <div class="text-muted"><?= e((string) ($route['answered'] ?? 0)) ?> / <?= e((string) ($route['attempts'] ?? 0)) ?> answered</div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="mt-2"><a href="<?= e(url('/trunk-groups')) ?>">Manage routing</a></div>
        <?php endif; ?>
    </div>
</div>

<div class="os-dash-grid os-dash-bottom">
    <div class="os-widget">
        <div class="os-widget-h">
            <h2 class="os-widget-title">Daily usage · last 7 days</h2>
            <div class="d-flex gap-2">
                <a class="small" href="<?= e(url('/reports/summary-day')) ?>">All usage</a>
                <a class="small" href="<?= e(url('/cdrs')) ?>">Call log</a>
            </div>
        </div>
        <?php if (!$daily): ?>
            <div class="os-empty py-3"><div class="os-empty-title">No usage yet</div></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 os-table">
                    <thead>
                    <tr>
                        <th>Day</th>
                        <th class="os-num">Billed</th>
                        <th class="os-num">Attempted</th>
                        <th class="os-num">Failed</th>
                        <th class="os-num">Answer %</th>
                        <th>Duration/Min</th>
                        <th class="os-num">Selling</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daily as $d): ?>
                        <tr>
                            <td class="os-mono"><?= e($d['day']) ?></td>
                            <td class="os-num"><?= e(number_format($d['answered'])) ?></td>
                            <td class="os-num"><?= e(number_format($d['attempts'])) ?></td>
                            <td class="os-num"><?= e(number_format(max(0, $d['attempts'] - $d['answered']))) ?></td>
                            <td class="os-num"><?= e($d['asr']) ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="os-mono"><?= e((string) $d['minutes']) ?></span>
                                    <div class="os-usage-bar flex-grow-1"><span style="width:<?= e((string) round(100 * $d['minutes'] / $maxMin)) ?>%"></span></div>
                                </div>
                            </td>
                            <td class="os-num os-mono"><?= e(fmt_money($d['cost'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="os-widget">
        <div class="os-widget-h">
            <h2 class="os-widget-title">Platform</h2>
        </div>
        <div class="row g-3">
            <div class="col-6">
                <div class="lbl small text-muted text-uppercase fw-bold" style="letter-spacing:.06em">Users</div>
                <div class="fs-4 fw-bold"><a href="<?= e(url('/users')) ?>"><?= e(number_format($counts['customers'] ?? 0)) ?></a></div>
            </div>
            <div class="col-6">
                <div class="lbl small text-muted text-uppercase fw-bold" style="letter-spacing:.06em">Providers</div>
                <div class="fs-4 fw-bold"><a href="<?= e(url('/providers')) ?>"><?= e(number_format($counts['carriers'] ?? 0)) ?></a></div>
            </div>
            <div class="col-6">
                <div class="lbl small text-muted text-uppercase fw-bold" style="letter-spacing:.06em">Today sell</div>
                <div class="fs-5 fw-bold os-mono"><?= e(fmt_money($t['spend'], 2)) ?></div>
            </div>
            <div class="col-6">
                <div class="lbl small text-muted text-uppercase fw-bold" style="letter-spacing:.06em">Yday by now</div>
                <div class="fs-5 fw-bold os-mono"><?= e(fmt_money($metrics['yesterday_pace'], 2)) ?></div>
            </div>
        </div>
        <div class="mt-3 d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm" href="<?= e(url('/billing/refill')) ?>">Refill</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reports/financial')) ?>">Financial</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>">Settings</a>
        </div>
    </div>
</div>
