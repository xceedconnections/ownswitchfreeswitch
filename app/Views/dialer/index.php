<?php
/** @var list<array{id:int,username:string}> $accounts @var string $error @var array $webrtc @var string $sipDomain */
?>
<div class="os-page-head os-dialer-head">
    <div>
        <h1 class="os-page-title">Web-Dialer</h1>
        <p class="os-page-sub">Online web phone</p>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger os-dialer-alert"><?= e($error) ?></div>
<?php else: ?>
    <?php if (empty($webrtc['enabled']) || empty($webrtc['listening'])): ?>
        <div class="alert alert-warning os-dialer-alert">WebRTC is not ready on this server yet. An administrator should open Settings → WebRTC and click Enable WebRTC.</div>
    <?php endif; ?>
    <div class="os-dialer-wrap">
        <div class="os-phone" id="os-dialer"
             data-cred="<?= e(url('/web-dialer/credentials')) ?>"
             data-domain="<?= e((string) ($sipDomain ?? '')) ?>">
            <?= csrf_field() ?>
            <div class="os-phone-top">
                <div class="os-phone-brand">ON Switch</div>
                <div class="os-phone-link" id="os-dialer-link" data-state="offline">
                    <span class="os-phone-dot" aria-hidden="true"></span>
                    <span id="os-dialer-link-text">Disconnected</span>
                </div>
            </div>
            <div class="os-phone-status" id="os-dialer-status">Not registered</div>
            <div class="os-phone-timer" id="os-dialer-timer" hidden>00:00</div>

            <div class="os-phone-field">
                <label for="os-dialer-account">SIP user</label>
                <select id="os-dialer-account">
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= e((string) $a['id']) ?>"><?= e($a['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="os-phone-field">
                <label for="os-dialer-cli">Caller ID</label>
                <input id="os-dialer-cli" class="os-mono" inputmode="numeric" autocomplete="off" placeholder="Optional — blank uses the SIP user default">
            </div>
            <div class="os-phone-field">
                <label for="os-dialer-number">Number</label>
                <input id="os-dialer-number" class="os-mono os-phone-number" inputmode="tel" autocomplete="off" placeholder="Type, paste, or use the pad">
            </div>

            <div class="os-phone-meters">
                <div class="os-phone-meter">
                    <div class="os-phone-meter-label"><i class="bi bi-mic-fill" aria-hidden="true"></i> Mic</div>
                    <div class="os-vu" id="os-dialer-vu" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
                </div>
                <div class="os-phone-meter os-phone-meter-controls">
                    <button type="button" class="os-phone-icon-btn" id="os-dialer-mute" title="Mute microphone" aria-pressed="false">
                        <i class="bi bi-mic-fill" id="os-dialer-mute-icon" aria-hidden="true"></i>
                    </button>
                    <label class="os-phone-vol" for="os-dialer-volume">
                        <i class="bi bi-volume-up-fill" aria-hidden="true"></i>
                        <input type="range" id="os-dialer-volume" min="0" max="100" value="80" aria-label="Speaker volume">
                    </label>
                </div>
            </div>

            <div class="os-phone-pad" id="os-dialer-pad">
                <?php
                $keys = [
                    ['1', ''], ['2', 'ABC'], ['3', 'DEF'],
                    ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'],
                    ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'],
                    ['*', ''], ['0', '+'], ['#', ''],
                ];
                foreach ($keys as [$key, $letters]):
                ?>
                    <button type="button" class="os-phone-key" data-key="<?= e($key) ?>">
                        <span class="os-phone-key-digit"><?= e($key) ?></span>
                        <?php if ($letters !== ''): ?><span class="os-phone-key-letters"><?= e($letters) ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="os-phone-actions">
                <button type="button" class="os-phone-btn os-phone-btn-ghost" id="os-dialer-back" title="Delete">
                    <i class="bi bi-backspace" aria-hidden="true"></i>
                </button>
                <button type="button" class="os-phone-btn os-phone-btn-call" id="os-dialer-call" title="Call (Enter)">
                    <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                </button>
                <button type="button" class="os-phone-btn os-phone-btn-hang" id="os-dialer-hangup" title="Hang up (Esc)" disabled>
                    <i class="bi bi-telephone-x-fill" aria-hidden="true"></i>
                </button>
            </div>
            <p class="os-phone-hint">Enter dials · Esc hangs up · paste a number into the field</p>
            <p class="os-phone-powered">Powered by <a href="https://xceedconnections.com" target="_blank" rel="noopener noreferrer">Xceed Connections</a></p>
            <audio id="os-dialer-remote" autoplay playsinline></audio>
        </div>
    </div>
<?php endif; ?>
