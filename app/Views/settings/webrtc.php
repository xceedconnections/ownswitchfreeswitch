<?php
/** @var array $status */
$s = $status;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">WebRTC</h1>
        <p class="os-page-sub">Browser dialer registration on this server</p>
    </div>
</div>

<div class="os-panel p-3">
    <?php if (!empty($s['enabled']) && !empty($s['listening'])): ?>
        <p><span class="badge text-bg-success">Listening</span> SIP over WebSocket is on localhost port 5066. Browsers connect through <span class="os-mono">wss://this-host/sip-ws</span>.</p>
    <?php elseif (!empty($s['enabled'])): ?>
        <p><span class="badge text-bg-warning">Enabled</span> The proxy is configured. FreeSWITCH is not listening on port 5066 yet. Enable again to restart the SIP profile.</p>
    <?php else: ?>
        <p><span class="badge text-bg-secondary">Off</span> The dialer cannot register until WebRTC is enabled.</p>
    <?php endif; ?>
    <p class="small text-muted">Enable opens a local WebSocket for the dialer and allows the microphone on this site. Restarting the SIP profile drops current registrations for a few seconds. Calls from the dialer still follow each SIP user's CLI restriction lists.</p>
    <?php if (empty($s['helper'])): ?>
        <div class="alert alert-warning">
            The WebRTC helper is not installed on this server yet. As root run:
            <pre class="mb-0 mt-2 small">sudo bash /opt/ownswitch-src/deploy/install-ssl-webrtc-helpers.sh</pre>
            Or open Version and click Update now after the latest main is pulled, then reload this page.
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/settings/webrtc')) ?>" data-confirm="Enable WebRTC and restart the SIP profile? Current registrations drop for a few seconds.">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit" data-once<?= empty($s['helper']) ? ' disabled' : '' ?>>Enable WebRTC</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/web-dialer')) ?>">Open Web-Dialer</a>
    </form>
</div>
