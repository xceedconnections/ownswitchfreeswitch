<?php
/** @var list<array{id:int,username:string}> $accounts @var string $error @var array $webrtc */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Dialer</h1>
        <p class="os-page-sub">Online Web Dialer</p>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php else: ?>
    <?php if (empty($webrtc['enabled'])): ?>
        <div class="alert alert-warning">WebRTC is not enabled on this server yet. An administrator can turn it on under Settings → WebRTC.</div>
    <?php endif; ?>
    <div class="os-dialer" id="os-dialer"
         data-cred="<?= e(url('/dialer/credentials')) ?>">
        <?= csrf_field() ?>
        <div class="os-dialer-status" id="os-dialer-status">Not registered</div>
        <div class="mb-3">
            <label class="form-label" for="os-dialer-account">SIP user</label>
            <select class="form-select" id="os-dialer-account">
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= e((string) $a['id']) ?>"><?= e($a['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="os-dialer-cli">Caller ID</label>
            <input class="form-control os-mono" id="os-dialer-cli" inputmode="numeric" autocomplete="off" placeholder="Digits to present. Leave blank to use the SIP user default.">
        </div>
        <div class="mb-3">
            <label class="form-label" for="os-dialer-number">Number</label>
            <input class="form-control os-mono os-dialer-number" id="os-dialer-number" readonly value="">
        </div>
        <div class="os-dialer-pad" id="os-dialer-pad">
            <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key): ?>
                <button type="button" class="btn btn-outline-secondary os-dialer-key" data-key="<?= e($key) ?>"><?= e($key) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" id="os-dialer-back">Delete</button>
            <button type="button" class="btn btn-success" id="os-dialer-call">Call</button>
            <button type="button" class="btn btn-danger" id="os-dialer-hangup" disabled>Hang up</button>
        </div>
    </div>
<?php endif; ?>
