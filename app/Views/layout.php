<?php
/** @var string $content @var string $title @var array $flashes @var array $menu */
$user = current_user();
$roleLabels = [
    'super_admin' => 'Super Admin', 'admin' => 'Admin', 'reseller' => 'Reseller',
    'customer_owner' => 'Customer', 'customer_tech' => 'Customer Tech', 'reporter' => 'Reporter',
];
$currentPath = \Ownswitch\App::request()?->path ?? '/';
$itemActive = static function (string $path) use ($currentPath): bool {
    return $path === '/' ? $currentPath === '/' : str_starts_with($currentPath, $path);
};
$sectionActive = static function (array $section) use ($itemActive): bool {
    foreach ($section['items'] as $item) {
        if ($itemActive($item['path'])) {
            return true;
        }
    }
    return false;
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . $b;
};
$logoUrl = null;
$footerBrand = 'Ownswitch';
try {
    $settingsSvc = new \Ownswitch\Services\Settings\SettingsService(\Ownswitch\App::db());
    $logoUrl = switch_logo_url();
    $sn = $settingsSvc->get('switch_name', '');
    if (is_string($sn) && trim($sn) !== '') {
        $footerBrand = trim($sn);
    }
} catch (\Throwable) {
    $logoUrl = null;
}
$headerBalance = null;
if ($user && in_array($user['role'] ?? '', ['customer_owner', 'customer_tech'], true) && !empty($user['customer_id'])) {
    try {
        $led = new \Ownswitch\Services\Billing\LedgerService(\Ownswitch\App::db());
        $headerBalance = $led->balance((int) $user['customer_id']);
    } catch (\Throwable) {
        $headerBalance = null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> · Ownswitch</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="os-body<?= $user ? '' : ' os-auth-body' ?>">
<?php if ($user): ?>
<header class="os-header">
    <div class="os-header-inner">
        <a class="os-logo" href="<?= e(url('/')) ?>">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="Ownswitch" class="os-logo-img">
            <?php else: ?>
                <span class="os-logo-mark">Ow</span>
                <span class="os-logo-text">Ownswitch</span>
            <?php endif; ?>
        </a>

        <button class="btn os-nav-burger d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#osTopNav" aria-controls="osTopNav" aria-expanded="false" aria-label="Menu">
            <i class="bi bi-list fs-4"></i>
        </button>

        <nav class="collapse os-top-nav" id="osTopNav">
            <a class="os-nav-link<?= $itemActive('/') ? ' is-active' : '' ?>" href="<?= e(url('/')) ?>">Dashboard</a>
            <?php foreach ($menu as $i => $section):
                $active = $sectionActive($section);
                $dd = 'osNavDd' . $i;
                ?>
                <div class="dropdown">
                    <button class="os-nav-link dropdown-toggle<?= $active ? ' is-active' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= e($section['label']) ?>
                    </button>
                    <ul class="dropdown-menu os-dd-menu shadow-sm border-0">
                        <?php foreach ($section['items'] as $item): ?>
                            <li>
                                <a class="dropdown-item<?= $itemActive($item['path']) ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>">
                                    <?= e($item['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="os-header-actions">
            <?php if ($user && in_array($user['role'] ?? '', ['customer_owner', 'customer_tech'], true) && !empty($user['customer_id'])): ?>
                <div class="os-cc-pill" id="osCcPill" title="Concurrent calls: allowed / in use" data-os-cc data-url="<?= e(url('/api/live/concurrent')) ?>">
                    <span class="os-cc-k">CC</span>
                    <span class="os-cc-v" data-os-cc-value>— / —</span>
                </div>
            <?php endif; ?>
            <?php if ($headerBalance !== null): ?>
                <div class="os-balance-chip" title="Available balance">
                    <span class="os-dot os-dot-ok"></span>
                    <span class="os-balance-label">Balance</span>
                    <strong class="os-mono"><?= e(fmt_money($headerBalance, 2)) ?></strong>
                </div>
            <?php endif; ?>
            <div class="dropdown">
                <button class="os-avatar-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account">
                    <span class="os-avatar"><?= e($initials((string) $user['name'])) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end os-dd-menu shadow-sm border-0">
                    <li class="px-3 py-2">
                        <div class="fw-semibold"><?= e((string) $user['name']) ?></div>
                        <div class="small text-muted"><?= e((string) $user['email']) ?></div>
                        <div class="small text-muted"><?= e($roleLabels[$user['role']] ?? (string) $user['role']) ?></div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= e(url('/account/profile')) ?>"><i class="bi bi-person-gear me-2"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/account/password')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <li>
                        <form method="post" action="<?= e(url('/logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>

<main class="os-main-top">
    <?php if (!empty($killSwitch)): ?>
        <div class="alert alert-danger mb-3" role="alert">
            <strong>Emergency kill switch is ON.</strong> New outbound calls should be rejected. Turn it off in Settings when safe.
            <?php
            $cu = function_exists('current_user') ? current_user() : null;
            if (is_array($cu) && in_array((string) ($cu['role'] ?? ''), ['super_admin', 'admin'], true)): ?>
                <a class="alert-link ms-1" href="<?= e(url('/settings')) ?>">Open Settings</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="os-footer">
    <div class="os-footer-inner">
        <span class="os-footer-brand"><?= e($footerBrand) ?></span>
        <span class="os-footer-sep" aria-hidden="true">·</span>
        <span class="os-footer-meta">FreeSWITCH VoIP billing</span>
    </div>
</footer>
<?php else: ?>
<main class="os-auth-page">
    <div class="os-auth-wrap">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?>" role="alert"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
        <p class="os-auth-foot mb-0"><?= e($footerBrand) ?> · FreeSWITCH VoIP billing</p>
    </div>
</main>
<?php endif; ?>
<?php if ($user): ?>
<div id="os-player" class="os-audio-dock" hidden>
    <div class="os-audio-dock-inner">
        <i class="bi bi-play-circle" aria-hidden="true"></i>
        <span class="os-audio-dock-label" id="os-player-label">Recording</span>
        <audio id="os-player-audio" controls preload="none"></audio>
        <a id="os-player-dl" class="btn btn-outline-secondary btn-sm" href="#"><i class="bi bi-download me-1"></i>Download</a>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
