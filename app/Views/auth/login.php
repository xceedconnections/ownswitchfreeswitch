<?php /** @var ?string $error @var string $email @var string $login @var array{a:int,b:int,token:string} $captcha */ ?>
<?php $loginVal = $login ?? $email ?? ''; ?>
<?php $captcha = $captcha ?? ['a' => 0, 'b' => 0, 'token' => '']; ?>
<div class="os-auth-card">
    <div class="os-auth-brand">
        <div class="os-brand-mark" aria-hidden="true">Ow</div>
        <h1>Ownswitch</h1>
        <p>Sign in with username or email + portal password</p>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="login" class="form-label fw-semibold">Username or email</label>
            <input type="text" class="form-control form-control-lg" id="login" name="login" value="<?= e($loginVal) ?>" required autofocus autocomplete="username" maxlength="190" placeholder="admin or you@company.com">
            <div class="form-text">Use the Username from Users, or Email (portal login)</div>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label fw-semibold">Password</label>
            <input type="password" class="form-control form-control-lg" id="password" name="password" required autocomplete="current-password" maxlength="256" placeholder="••••••••••••">
        </div>
        <div class="mb-3">
            <label for="captcha" class="form-label fw-semibold">Security check</label>
            <div class="input-group input-group-lg">
                <span class="input-group-text os-mono"><?= e((string) $captcha['a']) ?> + <?= e((string) $captcha['b']) ?> =</span>
                <input type="text" class="form-control" id="captcha" name="captcha" required inputmode="numeric" autocomplete="off" maxlength="3" placeholder="?" aria-describedby="captchaHelp">
            </div>
            <input type="hidden" name="captcha_token" value="<?= e((string) $captcha['token']) ?>">
            <div class="form-text" id="captchaHelp">Enter the sum to continue (required for all users)</div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" data-once>Sign in</button>
    </form>
</div>
