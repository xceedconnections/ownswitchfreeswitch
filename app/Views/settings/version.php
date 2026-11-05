<?php
/** @var array $status */
$s = $status;
$upd = !empty($s['update_available']);
$can = !empty($s['can_update']) || !empty($s['helper']);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Version</h1>
        <p class="os-page-sub">Installed Ownswitch build vs GitHub <code><?= e((string) ($s['repo'] ?? 'ownswitch')) ?></code> / <?= e((string) ($s['branch'] ?? 'main')) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-gear me-1"></i>Settings</a>
</div>

<?php if (($s['detail'] ?? '') !== '' && ($s['detail'] ?? '') !== 'ok'): ?>
    <div class="alert alert-warning"><?= e((string) $s['detail']) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 text-muted">Installed</h2>
            <div class="os-mono fs-5 mb-1"><?= e((string) ($s['current_short'] ?: '—')) ?></div>
            <?php if (($s['current_sha'] ?? '') !== ''): ?>
                <div class="small text-muted os-mono text-break"><?= e((string) $s['current_sha']) ?></div>
            <?php endif; ?>
            <?php if (($s['current_date'] ?? '') !== ''): ?>
                <div class="small mt-2"><?= e((string) $s['current_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 text-muted">GitHub main</h2>
            <div class="os-mono fs-5 mb-1"><?= e((string) ($s['remote_short'] ?: '—')) ?></div>
            <?php if (($s['remote_sha'] ?? '') !== ''): ?>
                <div class="small text-muted os-mono text-break"><?= e((string) $s['remote_sha']) ?></div>
            <?php endif; ?>
            <?php if (($s['remote_message'] ?? '') !== ''): ?>
                <div class="mt-2"><?= e((string) $s['remote_message']) ?></div>
            <?php endif; ?>
            <?php if (($s['remote_date'] ?? '') !== ''): ?>
                <div class="small text-muted mt-1"><?= e((string) $s['remote_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-panel p-3">
    <?php if ($upd): ?>
        <div class="alert alert-info mb-3">
            <strong>New update available</strong> on GitHub. Click Update now to pull and deploy (same as <code>refresh-ui.sh</code>).
        </div>
    <?php else: ?>
        <p class="mb-3">You are on the latest GitHub <code>main</code> commit<?= ($s['current_short'] ?? '') !== '' ? ' (' . e((string) $s['current_short']) . ')' : '' ?>. You can still redeploy to refresh helpers and assets.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/version/update')) ?>" data-confirm="Update / redeploy Ownswitch from GitHub now? This runs as root via sudo and may briefly interrupt the admin session.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary" data-once<?= !$can ? ' disabled' : '' ?>>
            <i class="bi bi-cloud-download me-1"></i><?= $upd ? 'Update now' : 'Redeploy now' ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/settings/version')) ?>"><i class="bi bi-arrow-clockwise me-1"></i>Check again</a>
    </form>

    <?php if (!$can): ?>
        <div class="alert alert-warning mt-3 mb-0">
            Update helper is not installed yet (needs one root command). On the server run:
            <pre class="mb-0 mt-2 small">sudo bash /opt/ownswitch-src/deploy/install-update-helper.sh</pre>
            Then reopen this page — <strong>Update now</strong> will work from the portal.
        </div>
    <?php endif; ?>
</div>
