<?php
/** @var array $status @var bool $available @var string $helperPath @var string $q @var array $result @var array $query */
$s = $status;
$available = !empty($available);
$q = (string) ($q ?? '');
$result = $result ?? ['total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 10];
$query = $query ?? [];
$jails = $s['jails'] ?? [];
$sip = $jails['opensips-auth'] ?? [
    'label' => 'SIP auth', 'enabled' => !empty($s['enabled']), 'maxretry' => $s['maxretry'] ?? '5',
    'findtime' => $s['findtime'] ?? '10m', 'bantime' => $s['bantime'] ?? '1h',
    'jail_enabled' => $s['jail_enabled'] ?? 'true', 'sip_port' => $s['sip_port'] ?? '5060',
    'currently_banned' => 0,
];
$portal = $jails['ownswitch-portal'] ?? [
    'label' => 'Portal login', 'enabled' => false, 'maxretry' => '5',
    'findtime' => '10m', 'bantime' => '1h', 'jail_enabled' => 'true', 'currently_banned' => 0,
];
$entries = $s['ban_entries'] ?? [];
$details = $s['ban_details'] ?? [];
$bannedTotal = (int) ($result['total'] ?? count($entries));
$refreshQs = $query !== [] ? ('?' . http_build_query($query)) : '';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Firewall</h1>
        <p class="os-page-sub">Blocks repeated SIP and portal hacking attempts. Unknown SIP users cannot place calls. Bans stay until the duration ends.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-gear me-1"></i>Settings</a>
</div>

<?php if (empty($s['ok'])): ?>
    <div class="alert alert-warning">
        <?= e((string) ($s['detail'] ?? 'fail2ban not available')) ?>
        <div class="small mt-1">On the server: <code>sudo bash /opt/ownswitch-src/deploy/fix-portal-firewall.sh</code></div>
    </div>
<?php else: ?>
    <div class="os-dash-grid os-dash-kpis mb-3">
        <div class="os-kpi os-kpi--teal"><div class="lbl">SIP jail</div><div class="val"><?= !empty($sip['enabled']) ? 'On' : 'Off' ?></div><div class="sub">FreeSWITCH · UDP/TCP <?= e((string) ($sip['sip_port'] ?? '5060')) ?></div></div>
        <div class="os-kpi os-kpi--blue"><div class="lbl">Portal jail</div><div class="val"><?= !empty($portal['enabled']) ? 'On' : 'Off' ?></div><div class="sub">ownswitch-portal · HTTP/S</div></div>
        <div class="os-kpi os-kpi--amber"><div class="lbl">Banned now</div><div class="val"><?= e((string) ($s['currently_banned'] ?? count($entries))) ?></div><div class="sub">active blocks</div></div>
        <div class="os-kpi os-kpi--violet"><div class="lbl">Auto-unban</div><div class="val"><?= e((string) ($sip['bantime'] ?? '1h')) ?></div><div class="sub">after ban duration</div></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <?php foreach ([['key' => 'opensips-auth', 'cfg' => $sip, 'title' => 'SIP auth thresholds', 'hint' => 'Unknown users and wrong SIP passwords on 5060 are rejected, then banned'], ['key' => 'ownswitch-portal', 'cfg' => $portal, 'title' => 'Portal login thresholds', 'hint' => 'Wrong password or CAPTCHA on web login']] as $block):
            $cfg = $block['cfg'];
            $jid = preg_replace('/[^a-z0-9]/', '', $block['key']);
            ?>
            <div class="os-panel p-3 mb-3">
                <h2 class="h6 mb-1"><?= e($block['title']) ?></h2>
                <p class="small text-muted mb-3"><?= e($block['hint']) ?></p>
                <form method="post" action="<?= e(url('/settings/firewall/config')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="jail" value="<?= e($block['key']) ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="jail_enabled" id="en_<?= e($jid) ?>" value="1"<?= (($cfg['jail_enabled'] ?? 'true') === 'true' || ($cfg['jail_enabled'] ?? '') === '1') ? ' checked' : '' ?><?= !$available ? ' disabled' : '' ?>>
                        <label class="form-check-label" for="en_<?= e($jid) ?>">Enable jail</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="mr_<?= e($jid) ?>">Wrong attempts before ban</label>
                        <input class="form-control form-control-sm" type="number" min="1" max="100" id="mr_<?= e($jid) ?>" name="maxretry" value="<?= e((string) ($cfg['maxretry'] ?? '5')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ft_<?= e($jid) ?>">Window (findtime)</label>
                        <input class="form-control form-control-sm" id="ft_<?= e($jid) ?>" name="findtime" value="<?= e((string) ($cfg['findtime'] ?? '10m')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="bt_<?= e($jid) ?>">Ban duration</label>
                        <input class="form-control form-control-sm" id="bt_<?= e($jid) ?>" name="bantime" value="<?= e((string) ($cfg['bantime'] ?? '1h')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                        <div class="form-text">IP auto-unbans after this time.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" data-once<?= !$available ? ' disabled' : '' ?>>Save</button>
                </form>
            </div>
        <?php endforeach; ?>

        <div class="os-panel p-3">
            <h2 class="h6 mb-3">Manual ban</h2>
            <form method="post" action="<?= e(url('/settings/firewall/ban')) ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-12">
                    <label class="form-label" for="ban_ip">IP address</label>
                    <input class="form-control form-control-sm" id="ban_ip" name="ip" placeholder="1.2.3.4" required pattern="^[0-9a-fA-F:.]+$"<?= !$available ? ' disabled' : '' ?>>
                </div>
                <div class="col">
                    <label class="form-label" for="ban_jail">Jail</label>
                    <select class="form-select form-select-sm" id="ban_jail" name="jail"<?= !$available ? ' disabled' : '' ?>>
                        <option value="opensips-auth">SIP auth</option>
                        <option value="ownswitch-portal">Portal login</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Ban this IP?" data-once<?= !$available ? ' disabled' : '' ?>>Ban</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="os-panel overflow-hidden">
            <div class="px-3 py-2 border-bottom">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
                    <span class="fw-semibold">Currently banned <span class="text-muted fw-normal">(<?= e((string) $bannedTotal) ?>)</span></span>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/firewall' . $refreshQs)) ?>"><i class="bi bi-arrow-clockwise"></i></a>
                </div>
                <form method="get" action="<?= e(url('/settings/firewall')) ?>" class="row g-2 align-items-end">
                    <div class="col">
                        <label class="form-label small mb-0" for="ban_q">Search IP</label>
                        <input class="form-control form-control-sm" type="search" id="ban_q" name="q" value="<?= e($q) ?>" placeholder="e.g. 1.2.3.4" autocomplete="off">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Search</button>
                        <?php if ($q !== ''): ?>
                            <a class="btn btn-link btn-sm" href="<?= e(url('/settings/firewall')) ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php if ($bannedTotal === 0): ?>
                <div class="os-empty py-4">
                    <div class="os-empty-title"><?= $q !== '' ? 'No matching bans' : 'No banned IPs' ?></div>
                    <div><?= $q !== '' ? 'Try another IP fragment or clear the search.' : 'SIP wrong-password attacks and portal login floods appear here automatically.' ?></div>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($entries as $i => $e):
                        $ip = (string) $e['ip'];
                        $jail = (string) ($e['jail'] ?? 'opensips-auth');
                        $d = $details[$ip . '|' . $jail] ?? $details[$ip] ?? ['country' => '—', 'city' => '', 'isp' => '', 'reason' => '—', 'attempts' => []];
                        $cid = 'ban-detail-' . preg_replace('/[^a-zA-Z0-9]/', '', $ip . $jail) . '-' . $i;
                        $attempts = $d['attempts'] ?? [];
                        $badge = $jail === 'ownswitch-portal' ? 'bg-primary' : 'bg-secondary';
                        ?>
                        <div class="list-group-item px-3 py-3">
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                                <div class="me-auto" style="min-width:12rem">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="os-mono fw-semibold"><?= e($ip) ?></span>
                                        <span class="badge <?= e($badge) ?>"><?= e((string) ($e['label'] ?? $jail)) ?></span>
                                    </div>
                                    <?php if (($d['isp'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?= e((string) $d['isp']) ?></div>
                                    <?php endif; ?>
                                    <div class="small mt-1">
                                        <?= e((string) ($d['country'] ?? '—')) ?>
                                        <?php if (($d['city'] ?? '') !== ''): ?>
                                            <span class="text-muted">· <?= e((string) $d['city']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1"><?= e((string) ($d['reason'] ?? '—')) ?></div>
                                    <?php if ($attempts !== []): ?>
                                        <button class="btn btn-link btn-sm px-0 mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($cid) ?>" aria-expanded="false">
                                            Show more details
                                        </button>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1">No journal lines captured</div>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?= e(url('/settings/firewall/unban')) ?>" class="flex-shrink-0" data-confirm="Unban <?= e($ip) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ip" value="<?= e($ip) ?>">
                                    <input type="hidden" name="jail" value="<?= e($jail) ?>">
                                    <?php if ($q !== ''): ?><input type="hidden" name="return_q" value="<?= e($q) ?>"><?php endif; ?>
                                    <?php if ((int) ($result['page'] ?? 1) > 1): ?><input type="hidden" name="return_page" value="<?= e((string) $result['page']) ?>"><?php endif; ?>
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-once>Unban</button>
                                </form>
                            </div>
                            <?php if ($attempts !== []): ?>
                                <div class="collapse mt-2" id="<?= e($cid) ?>">
                                    <div class="border rounded bg-light p-2">
                                        <div class="small text-muted mb-1">Recent failures</div>
                                        <ul class="mb-0 ps-3" style="max-height:10rem;overflow:auto">
                                            <?php foreach ($attempts as $line): ?>
                                                <li class="os-mono" style="font-size:.72rem;word-break:break-all"><?= e($line) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php include __DIR__ . '/../partials/pagination.php'; ?>
            <?php endif; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">Helper: <code><?= e($helperPath) ?></code> · CAPTCHA + fail2ban protect portal login; SIP digest failures ban on 5060</p>
    </div>
</div>
