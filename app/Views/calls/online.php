<?php
/** @var list<array> $calls @var ?string $error @var int $count @var array $stats @var array $users @var string $filterUser @var bool $canHangup @var bool $canMonitor @var string $monitorExt */
$canMonitor = !empty($canMonitor);
$monitorExt = (string) ($monitorExt ?? '');
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Calls Online <span class="os-count"><?= e((string) $count) ?></span></h1>
        <p class="os-page-sub">Live dialogs · Trying / Ringing / Connected · auto-refresh 5s</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online' . ($filterUser !== '' ? '?user=' . urlencode($filterUser) : ''))) ?>"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
</div>

<?php if ($canMonitor): ?>
<form method="post" action="<?= e(url('/calls-online/monitor-ext')) ?>" class="os-toolbar mb-3">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-0" for="monitor_ext">Monitor extension (Spy / Whisper / Barge)</label>
            <input type="text" class="form-control form-control-sm os-mono" id="monitor_ext" name="monitor_ext" value="<?= e($monitorExt) ?>" placeholder="e.g. 1001 or user/1001" maxlength="64">
        </div>
        <div class="col-auto"><button class="btn btn-outline-primary btn-sm" type="submit">Save</button></div>
        <div class="col-auto small text-muted">Admin only · rings your SIP phone into the live call</div>
    </div>
</form>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-warning"><?= e($error) ?></div>
<?php endif; ?>

<div class="os-dash-grid os-dash-kpis mb-3">
    <div class="os-kpi"><div class="lbl">Total</div><div class="val"><?= e((string) ($stats['total'] ?? 0)) ?></div><div class="sub">active dialogs</div></div>
    <div class="os-kpi"><div class="lbl">Trying</div><div class="val"><?= e((string) ($stats['trying'] ?? 0)) ?></div><div class="sub">100 Trying</div></div>
    <div class="os-kpi"><div class="lbl">Ringing</div><div class="val"><?= e((string) ($stats['ringing'] ?? 0)) ?></div><div class="sub">180 / 183 Progress</div></div>
    <div class="os-kpi"><div class="lbl">Connected</div><div class="val"><?= e((string) ($stats['connected'] ?? 0)) ?></div><div class="sub">answered / confirmed</div></div>
</div>

<?php if ($users !== []): ?>
<form method="get" action="<?= e(url('/calls-online')) ?>" class="os-toolbar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-0" for="user">Filter user / SIP</label>
            <select class="form-select form-select-sm" id="user" name="user">
                <option value="">All users</option>
                <?php foreach ($users as $id => $label): ?>
                    <option value="<?= e((string) $id) ?>"<?= (string) $filterUser === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Submit</button></div>
        <?php if ($filterUser !== ''): ?>
            <div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>">Clear</a></div>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<div class="os-rt-legend mb-2 small text-muted">
    <span class="os-rt-swatch os-rt-blue-light"></span> Waiting / trying
    <span class="os-rt-swatch os-rt-blue"></span> Waiting &gt;1m
    <span class="os-rt-swatch os-rt-blue-dark"></span> Waiting &gt;5m
    <span class="os-rt-swatch os-rt-pink-soft"></span> On call &gt;10s
    <span class="os-rt-swatch os-rt-pink"></span> On call &gt;1m
    <span class="os-rt-swatch os-rt-green"></span> On call &gt;10m
    <span class="os-rt-swatch os-rt-black"></span> Dead / ending
</div>

<div class="os-table-wrap">
<?php if ($calls === []): ?>
    <div class="os-empty"><div class="os-empty-title">No active calls</div><div>Trying, ringing (180/183) and connected dialogs appear here.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table os-rt-table">
        <thead><tr><th>SIP</th><th>From</th><th>To</th><th>Status</th><th class="os-num">MM:SS</th><th>Call-ID</th><?php if ($canMonitor || !empty($canHangup)): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($calls as $c):
            $sip = (string) ($c['sip_user'] ?? '');
            $fromDisp = (string) (($c['from_user'] !== '' ? $c['from_user'] : $c['from']) ?? '');
            $toDisp = (string) (($c['to_user'] !== '' ? $c['to_user'] : $c['to']) ?? '');
            ?>
            <tr class="<?= e((string) ($c['color'] ?? '')) ?>">
                <td class="os-mono"><?= e($sip !== '' ? $sip : '—') ?></td>
                <td class="os-mono"><?= e($fromDisp !== '' ? $fromDisp : '—') ?></td>
                <td class="os-mono"><?= e($toDisp !== '' ? $toDisp : '—') ?></td>
                <td><strong><?= e((string) ($c['state'] ?? '')) ?></strong></td>
                <td class="os-num os-mono"><?= e(fmt_duration((int) ($c['duration'] ?? 0))) ?></td>
                <td class="os-mono small text-muted"><?= e(mb_substr((string) ($c['callid'] ?? ''), 0, 40)) ?></td>
                <?php if ($canMonitor || !empty($canHangup)): ?>
                <td class="text-end text-nowrap">
                    <?php if ($canMonitor):
                        $uuid = (string) ($c['dialog'] ?? $c['uuid'] ?? '');
                        foreach (['spy' => 'Spy', 'whisper' => 'Whisper', 'barge' => 'Barge'] as $m => $lbl): ?>
                    <form method="post" action="<?= e(url('/calls-online/monitor')) ?>" class="d-inline" data-os-hangup data-confirm="<?= e($lbl) ?> this call? Your extension <?= e($monitorExt !== '' ? $monitorExt : '(set above)') ?> will ring.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="<?= e($m) ?>">
                        <input type="hidden" name="uuid" value="<?= e($uuid) ?>">
                        <input type="hidden" name="callid" value="<?= e((string) ($c['callid'] ?? '')) ?>">
                        <input type="hidden" name="monitor_ext" value="<?= e($monitorExt) ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="<?= e($lbl) ?>" data-once><?= e($lbl) ?></button>
                    </form>
                    <?php endforeach; endif; ?>
                    <?php if (!empty($canHangup)): ?>
                    <form method="post" action="<?= e(url('/calls-online/hangup')) ?>" class="d-inline" data-os-hangup data-confirm="Hang up this call? FreeSWITCH will send BYE to both sides.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="callid" value="<?= e((string) ($c['callid'] ?? '')) ?>">
                        <input type="hidden" name="from_tag" value="<?= e((string) ($c['from_tag'] ?? '')) ?>">
                        <input type="hidden" name="dialog_id" value="<?= e((string) ($c['dialog'] ?? '')) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Hang up" data-once><i class="bi bi-telephone-x"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
<?php
$refreshUrl = url('/calls-online' . ($filterUser !== '' ? '?user=' . rawurlencode($filterUser) : ''));
?>
<script>
(function () {
  var url = <?= json_encode($refreshUrl, JSON_THROW_ON_ERROR) ?>;
  var timer = window.setTimeout(function () { window.location.href = url; }, 5000);
  document.querySelectorAll('form[data-os-hangup]').forEach(function (f) {
    f.addEventListener('submit', function () { window.clearTimeout(timer); });
  });
})();
</script>
