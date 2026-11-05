<?php
/**
 * Customer Mission Control dashboard (Xceed-style).
 *
 * @var ?array $customer @var string $balance @var string $available @var ?float $runwayDays
 * @var int $sipCount @var int $ipCount @var array $metrics
 * @var int $ccAllowed @var int $ccUsing @var bool $ccUnlimited
 * @var int $cpsAllowed @var bool $cpsUnlimited @var list<array> $sipLimits
 * @var bool $callsPaused @var bool $lowBalanceWarn @var bool $lowRunway @var list<array> $attention
 * @var string $ccLabel @var string $balance @var string $available
 */
$ccAllowed = $ccAllowed ?? 0;
$ccUsing = $ccUsing ?? 0;
$ccUnlimited = !empty($ccUnlimited);
$cpsAllowed = $cpsAllowed ?? 0;
$cpsUnlimited = !empty($cpsUnlimited);
$sipLimits = $sipLimits ?? [];
$sipCount = (int) ($sipCount ?? 0);
$ipCount = (int) ($ipCount ?? 0);
$attention = $attention ?? [];
$balance = bcadd((string) ($balance ?? '0'), '0', 6);
$available = bcadd((string) ($available ?? '0'), '0', 6);
// Recompute in the view so pause/low always match ledger (even if flags were missed)
$modeRaw = strtolower(trim((string) ($customer['billing_mode'] ?? 'prepaid')));
$isPrepaidLike = $modeRaw !== 'postpaid';
$fundsEmpty = bccomp($available, '0', 6) <= 0 || bccomp($balance, '0', 6) <= 0;
$statusRaw = strtolower(trim((string) ($customer['status'] ?? 'active')));
$callsPaused = $statusRaw !== 'active' || ($isPrepaidLike && $fundsEmpty);
$lowBalanceWarn = !$callsPaused && $isPrepaidLike
    && bccomp($available, '0', 6) > 0 && bccomp($available, '10', 6) < 0;
$lowRunway = !empty($lowRunway) && !$callsPaused && !$lowBalanceWarn;
// Ensure attention cards exist for pause / low balance even if controller omitted them
$hasCritical = false;
$hasLowBal = false;
foreach ($attention as $a) {
    if (($a['level'] ?? '') === 'critical') {
        $hasCritical = true;
    }
    if (($a['title'] ?? '') === 'Low balance') {
        $hasLowBal = true;
    }
}
if ($callsPaused && !$hasCritical) {
    array_unshift($attention, [
        'level' => 'critical',
        'title' => 'Calling is paused',
        'body' => $statusRaw !== 'active'
            ? 'Your account is not active. New calls may be refused until support reactivates it.'
            : ('Your available balance is below the level needed to place calls. Your balance is '
                . fmt_money($available, 2) . '.'),
    ]);
} elseif ($lowBalanceWarn && !$hasLowBal) {
    array_unshift($attention, [
        'level' => 'warn',
        'title' => 'Low balance',
        'body' => 'Your available balance is ' . fmt_money($available, 2)
            . '. Add funds before it reaches zero so calling is not paused.',
    ]);
}
$attnCount = count($attention);
$limLabel = static function (int $n, bool $unlimited): string {
    return $unlimited || $n < 0 ? '∞' : (string) $n;
};
$money = static function (string $v, string $cur = ''): string {
    $n = fmt_money($v, 2);
    $sym = strtoupper($cur) === 'USD' || $cur === '' || $cur === '$' ? '$' : ($cur . ' ');
    if (str_starts_with($n, '-')) {
        return '-' . $sym . substr($n, 1);
    }
    return $sym . $n;
};
$acdFmt = static function (string $acd): string {
    $s = (int) round((float) $acd);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$w = $metrics['window60'];
$t = $metrics['today'];
$failed = $metrics['failed24'];
$traffic = $metrics['traffic60'];
$asr7 = (float) ($metrics['asr7'] ?? 0);
$asrNow = (float) ($w['asr'] ?? 0);
$asrDelta = round($asrNow - $asr7, 1);
$spend30 = (string) ($metrics['spend30'] ?? '0');
$peakPerMin = (int) ($metrics['peakPerMin'] ?? 0);
$peakCps = $peakPerMin > 0 ? round($peakPerMin / 60, 2) : 0;
$lastCdrAt = $metrics['lastCdrAt'] ?? null;
$cur = (string) ($customer['currency'] ?? '');
$billingMode = ucfirst((string) ($customer['billing_mode'] ?? 'prepaid'));
$name = strtoupper((string) ($customer['name'] ?? 'ACCOUNT'));
$maxBar = 1;
foreach ($traffic as $b) {
    $maxBar = max($maxBar, $b['answered'] + $b['failed']);
}
$yTicks = [];
if ($maxBar <= 5) {
    $yTicks = [0, 1, 2, 3, 4, 5];
} else {
    $step = max(1, (int) ceil($maxBar / 4));
    for ($i = 0; $i <= 4; $i++) {
        $yTicks[] = $i * $step;
    }
}
$connected = $sipCount > 0 || $ipCount > 0;
$cdrFresh = false;
$cdrHint = 'No call records yet';
if ($lastCdrAt) {
    $age = time() - strtotime($lastCdrAt . ' UTC');
    if ($age < 120) {
        $cdrFresh = true;
        $cdrHint = 'Last arrived just now';
    } elseif ($age < 3600) {
        $cdrFresh = true;
        $cdrHint = 'Last arrived ' . (int) floor($age / 60) . ' min ago';
    } else {
        $cdrHint = 'Last arrived ' . fmt_local($lastCdrAt, 'M j H:i');
    }
}
?>
<?php if (!$customer): ?>
    <div class="alert alert-danger">Your login is not linked to a customer account. Contact support.</div>
<?php else: ?>

<div class="mc-head">
    <div class="mc-head-left">
        <h1 class="mc-title"><?= e($name) ?> <span class="mc-title-sep">/</span> MISSION CONTROL</h1>
        <span class="mc-badge"><?= e($billingMode) ?></span>
    </div>
    <div class="mc-head-right text-muted small">
        <span class="mc-live-dot<?= $callsPaused ? ' is-paused' : ($lowBalanceWarn ? ' is-warn' : '') ?>"></span>
        <?php if ($callsPaused): ?>
            Calling paused
        <?php elseif ($lowBalanceWarn): ?>
            Low balance
        <?php else: ?>
            Completed calls
        <?php endif; ?>
        · updated just now
    </div>
</div>

<?php if ($callsPaused): ?>
<div class="mc-pause-banner">
    <div class="mc-pause-copy">
        <div class="mc-pause-title">
            <span class="mc-pause-dot"></span>
            Calling paused
        </div>
        <div class="mc-pause-sub">
            Calls are refused until funds are added
            <?php if ($attnCount > 0): ?>
                · <strong><?= e((string) $attnCount) ?> item<?= $attnCount === 1 ? '' : 's' ?> needs attention</strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="mc-pause-actions">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/payments')) ?>">Add funds</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/sip-users')) ?>">View connection</a>
    </div>
</div>
<?php elseif ($lowBalanceWarn): ?>
<div class="mc-low-banner">
    <div class="mc-low-copy">
        <div class="mc-low-title">
            <span class="mc-low-dot"></span>
            Low balance
        </div>
        <div class="mc-low-sub">
            Available <?= e($money($available, $cur)) ?> — under $10. Add funds soon so calling is not paused
            <?php if ($attnCount > 0): ?>
                · <strong><?= e((string) $attnCount) ?> item<?= $attnCount === 1 ? '' : 's' ?> needs attention</strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="mc-pause-actions">
        <a class="btn btn-warning btn-sm text-dark" href="<?= e(url('/payments')) ?>">Add funds</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/sip-users')) ?>">View connection</a>
    </div>
</div>
<?php elseif ($customer['status'] !== 'active'): ?>
    <div class="alert alert-warning">Account status: <strong><?= e($customer['status']) ?></strong>. New calls may be rejected.</div>
<?php else: ?>
<div class="mc-ok-banner">
    <div>
        <div class="mc-ok-title"><span class="mc-ok-dot"></span> <?= $w['attempts'] > 0 ? 'Calls flowing' : 'Ready for traffic' ?></div>
        <div class="mc-ok-sub"><?= e(number_format($w['attempts'])) ?> attempts · <?= e($w['asr']) ?>% answered · balance <?= e($money($available, $cur)) ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>">Live calls</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/sip-users')) ?>">SIP users</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/payments')) ?>">Payments</a>
    </div>
</div>
<?php endif; ?>

<div class="mc-kpis">
    <div class="mc-kpi">
        <div class="lbl">Attempts · 60 min</div>
        <div class="val"><?= e(number_format($w['attempts'])) ?></div>
        <div class="sub"><?= e(number_format((int) ($w['last_min'] ?? 0))) ?> in the last full minute</div>
    </div>
    <div class="mc-kpi">
        <div class="lbl">Answered · 60 min</div>
        <div class="val"><?= e(number_format($w['answered'])) ?></div>
        <div class="sub">Avg talk <?= e($acdFmt($w['acd'])) ?></div>
    </div>
    <div class="mc-kpi">
        <div class="lbl">Answer rate</div>
        <div class="val"><?= e($w['asr']) ?>%</div>
        <div class="sub <?= $asrDelta < -1 ? 'is-down' : ($asrDelta > 1 ? 'is-up' : '') ?>">
            <?php if (abs($asrDelta) >= 0.1): ?>
                <?= $asrDelta < 0 ? '▼' : '▲' ?>
                <?= e(number_format(abs($asrDelta), 1)) ?> pts vs 7-day <?= e(number_format($asr7, 1)) ?>%
            <?php else: ?>
                vs 7-day <?= e(number_format($asr7, 1)) ?>%
            <?php endif; ?>
        </div>
    </div>
    <div class="mc-kpi">
        <div class="lbl">Spend · 60 min</div>
        <div class="val os-mono"><?= e($money($w['spend'], $cur)) ?></div>
        <div class="sub"><?= e($money($t['spend'], $cur)) ?> today</div>
    </div>
    <div class="mc-kpi<?= $callsPaused ? ' is-critical' : ($lowBalanceWarn ? ' is-warn' : '') ?>">
        <div class="lbl">Available balance</div>
        <div class="val os-mono<?= $callsPaused || bccomp($available, '0', 6) <= 0 ? ' is-crit' : ($lowBalanceWarn ? ' is-warn-val' : '') ?>"><?= e($money($available, $cur)) ?></div>
        <div class="sub"><?= $callsPaused ? 'Calls paused' : ($lowBalanceWarn ? 'Under $10 — top up soon' : ('Ledger ' . e($money($balance, $cur)))) ?></div>
    </div>
    <div class="mc-kpi<?= $callsPaused ? ' is-critical' : ($lowBalanceWarn || $lowRunway ? ' is-warn' : '') ?>">
        <div class="lbl">Runway</div>
        <div class="val<?= $callsPaused ? ' is-crit' : ($lowBalanceWarn ? ' is-warn-val' : '') ?>">
            <?php if ($callsPaused): ?>
                Paused
            <?php elseif ($runwayDays === null): ?>
                —
            <?php else: ?>
                ≈ <?= e(number_format($runwayDays, 1)) ?> d
            <?php endif; ?>
        </div>
        <div class="sub"><?= $callsPaused ? 'Calls refused until funded' : ($lowBalanceWarn ? 'Fund before balance hits zero' : ('Today pace ' . e($money($t['spend'], $cur)))) ?></div>
    </div>
    <div class="mc-kpi">
        <div class="lbl">Routing</div>
        <div class="val"><?= $connected ? 'Healthy' : 'Setup' ?></div>
        <div class="sub"><?= e((string) $sipCount) ?> SIP · <?= e($limLabel((int) $ccAllowed, $ccUnlimited)) ?> channels</div>
    </div>
</div>

<div class="mc-main">
    <div class="mc-col-main">
        <div class="mc-card">
            <div class="mc-card-h">
                <h2 class="mc-card-title">Traffic · completed calls, last 60 minutes</h2>
                <div class="mc-legend">
                    <span><i class="a"></i>Answered</span>
                    <span><i class="f"></i>Not answered</span>
                </div>
            </div>
            <?php if (!$traffic): ?>
                <div class="os-empty py-4"><div class="os-empty-title">No calls yet</div></div>
            <?php else: ?>
                <div class="mc-chart">
                    <div class="mc-yaxis">
                        <?php foreach (array_reverse($yTicks) as $tick): ?>
                            <span><?= e((string) $tick) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="mc-bars-wrap">
                        <div class="mc-bars">
                            <?php foreach ($traffic as $b):
                                $tot = $b['answered'] + $b['failed'];
                                $scale = max(1, (int) ($yTicks[count($yTicks) - 1] ?? $maxBar));
                                $hAns = $tot > 0 ? (100 * $b['answered'] / $scale) : 0;
                                $hFail = $tot > 0 ? (100 * $b['failed'] / $scale) : 0;
                                $tip = $b['minute'] . ': ' . $b['answered'] . ' ans / ' . $b['failed'] . ' no';
                                $hAnsCss = round(min(100, $hAns), 1);
                                $hFailCss = round(min(100, $hFail), 1);
                                ?>
                                <div class="mc-bar" title="<?= e($tip) ?>">
                                    <div class="mc-bar-a" style="height: <?= e((string) $hAnsCss) ?>%"></div>
                                    <div class="mc-bar-f" style="height: <?= e((string) $hFailCss) ?>%"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mc-xaxis">
                            <span>60 min ago</span>
                            <span>Now</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="mc-stats">
            <div class="mc-stat">
                <div class="lbl">Peak calls / second</div>
                <div class="val"><?= e(rtrim(rtrim(number_format($peakCps, 2), '0'), '.') ?: '0') ?></div>
                <div class="sub">From busiest minute (<?= e((string) $peakPerMin) ?>/min)</div>
            </div>
            <div class="mc-stat">
                <div class="lbl">Peak simultaneous</div>
                <div class="val" data-os-cc-value><?= e((string) ($ccLabel ?? '— / —')) ?></div>
                <div class="sub">Allowed <?= e($limLabel((int) $ccAllowed, $ccUnlimited)) ?> · CPS <?= e($limLabel((int) $cpsAllowed, $cpsUnlimited)) ?></div>
            </div>
            <div class="mc-stat">
                <div class="lbl">Failed 24 h</div>
                <div class="val"><?= e(number_format($failed['total'])) ?></div>
                <div class="sub"><?= e($failed['pct']) ?>% of <?= e(number_format($failed['attempts'])) ?> attempts</div>
            </div>
            <div class="mc-stat">
                <div class="lbl">Billed 30 days</div>
                <div class="val os-mono"><?= e($money($spend30, $cur)) ?></div>
                <div class="sub">Customer charges</div>
            </div>
        </div>

        <?php if ($sipLimits !== []): ?>
        <div class="mc-card mt-3">
            <div class="mc-card-h"><h2 class="mc-card-title">Your SIP limits</h2></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 os-table">
                    <thead><tr><th>SIP</th><th class="os-num">CC</th><th class="os-num">CPS</th><th class="os-num">CAP</th></tr></thead>
                    <tbody>
                    <?php foreach ($sipLimits as $s): ?>
                        <tr>
                            <td class="os-mono"><?= e((string) $s['username']) ?></td>
                            <td class="os-num"><?= e($limLabel((int) ($s['max_channels'] ?? 0), (int) ($s['max_channels'] ?? 0) < 0)) ?></td>
                            <td class="os-num"><?= e($limLabel((int) ($s['max_cps'] ?? 0), (int) ($s['max_cps'] ?? 0) < 0)) ?></td>
                            <td class="os-num os-mono"><?= e(($s['rate_cap'] ?? null) !== null && $s['rate_cap'] !== '' ? (string) $s['rate_cap'] : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="mc-col-side">
        <div class="mc-card">
            <div class="mc-card-h">
                <h2 class="mc-card-title">Needs attention</h2>
                <?php if ($attnCount > 0): ?>
                    <span class="mc-attn-badge<?= $callsPaused ? '' : ' is-warn' ?>"><?= e((string) $attnCount) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($attnCount === 0): ?>
                <div class="mc-attn-empty">Nothing urgent. Balance and routing look fine.</div>
            <?php else: ?>
                <?php foreach ($attention as $item): ?>
                    <div class="mc-attn mc-attn-<?= e((string) ($item['level'] ?? 'warn')) ?>">
                        <div class="mc-attn-tag"><?= e(strtoupper((string) ($item['level'] ?? 'warn'))) ?></div>
                        <div class="mc-attn-title"><?= e((string) ($item['title'] ?? '')) ?></div>
                        <div class="mc-attn-body"><?= e((string) ($item['body'] ?? '')) ?></div>
                        <?php if (($item['level'] ?? '') === 'critical'): ?>
                            <a class="btn btn-primary btn-sm mt-2" href="<?= e(url('/payments')) ?>">Add funds</a>
                        <?php elseif (($item['level'] ?? '') === 'warn'): ?>
                            <a class="btn btn-warning btn-sm text-dark mt-2" href="<?= e(url('/payments')) ?>">Add funds</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="mc-card">
            <div class="mc-card-h"><h2 class="mc-card-title">System</h2></div>
            <div class="mc-sys-row">
                <span class="mc-sys-dot <?= $connected ? 'ok' : 'warn' ?>"></span>
                <div class="mc-sys-body">
                    <div class="mc-sys-label">Connection</div>
                    <div class="mc-sys-val"><?= $connected ? 'Connected' : 'Not configured' ?></div>
                    <div class="mc-sys-sub">
                        <?= e((string) $ipCount) ?> source IP<?= $ipCount === 1 ? '' : 's' ?>
                        · <?= e((string) $sipCount) ?> SIP
                        · <?= e($limLabel((int) $cpsAllowed, $cpsUnlimited)) ?> CPS
                        · <?= e($limLabel((int) $ccAllowed, $ccUnlimited)) ?> channels
                    </div>
                    <a class="mc-sys-link" href="<?= e(url('/sip-users')) ?>">View SIP setup</a>
                </div>
            </div>
            <div class="mc-sys-row">
                <span class="mc-sys-dot <?= $cdrFresh ? 'ok' : 'muted' ?>"></span>
                <div class="mc-sys-body">
                    <div class="mc-sys-label">Call records</div>
                    <div class="mc-sys-val"><?= $cdrFresh || $lastCdrAt ? 'Current' : 'Waiting' ?></div>
                    <div class="mc-sys-sub"><?= e($cdrHint) ?> · calls do not depend on this page</div>
                    <a class="mc-sys-link" href="<?= e(url('/cdrs')) ?>">Call log</a>
                </div>
            </div>
            <div class="mc-sys-row">
                <span class="mc-sys-dot ok"></span>
                <div class="mc-sys-body">
                    <div class="mc-sys-label">Account</div>
                    <div class="mc-sys-val"><?= e(ucfirst((string) $customer['status'])) ?> · <?= e($billingMode) ?></div>
                    <div class="mc-sys-sub">Tariff &amp; dialling options</div>
                    <a class="mc-sys-link" href="<?= e(url('/my-rates')) ?>">My tariff</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
