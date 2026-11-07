<?php
/** @var array $status */
$s = $status;
$when = '';
if (($s['not_after'] ?? '') !== '') {
    $ts = strtotime((string) $s['not_after']);
    $when = $ts ? date('M j, Y H:i', $ts) . ' UTC' : (string) $s['not_after'];
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">SSL</h1>
        <p class="os-page-sub">Let's Encrypt certificate for the portal domain</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="os-panel p-3 h-100">
            <h2 class="h6">Certificate</h2>
            <?php if (!empty($s['installed'])): ?>
                <p class="mb-1"><span class="badge text-bg-success">Installed</span></p>
                <div class="small text-muted">Domain</div>
                <div class="mb-2 os-mono"><?= e((string) ($s['domain'] ?: '—')) ?></div>
                <div class="small text-muted">Expires</div>
                <div class="mb-2"><?= e($when !== '' ? $when : '—') ?></div>
                <div class="small text-muted">Issuer</div>
                <div class="small"><?= e((string) ($s['issuer'] ?: '—')) ?></div>
            <?php else: ?>
                <p class="mb-0 text-muted">No Let's Encrypt certificate is installed yet.</p>
            <?php endif; ?>
            <?php if (empty($s['helper'])): ?>
                <div class="alert alert-warning mt-3 mb-0">The SSL helper is not on this server yet. Open Version and click Update now, then come back.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="os-panel p-3">
            <h2 class="h6">Install certificate</h2>
            <p class="small text-muted">The domain must already point at this server. Port 80 must answer the Let's Encrypt check. A valid certificate is kept until it is close to expiry.</p>
            <form method="post" action="<?= e(url('/settings/ssl')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="ssl-email">Email address</label>
                    <input class="form-control" id="ssl-email" name="email" type="email" required value="<?= e((string) ($s['email'] ?? '')) ?>" autocomplete="email" placeholder="admin@example.com">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ssl-domain">Domain</label>
                    <input class="form-control os-mono" id="ssl-domain" name="domain" required value="<?= e((string) ($s['domain'] ?? '')) ?>" placeholder="voip.example.com" autocapitalize="off" spellcheck="false">
                </div>
                <button class="btn btn-primary" type="submit" data-once<?= empty($s['helper']) ? ' disabled' : '' ?>>Install SSL</button>
            </form>
        </div>
    </div>
</div>
