<?php
/** @var array $result @var bool $canDelete @var array $query */
$canDelete = !empty($canDelete);
$query = $query ?? [];
$page = (int) ($result['page'] ?? 1);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Recordings <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Call recordings when media policy is record</p>
    </div>
</div>
<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No recordings</div>
        <div>Set the SIP user media policy to <strong>Record</strong>. The file appears here after the call ends. Access is audited.</div></div>
<?php else: ?>
    <form method="post" action="<?= e(url('/recordings/delete')) ?>" id="rec-bulk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="page" value="<?= e((string) $page) ?>">
        <?php if ($canDelete): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-2 border-bottom">
            <button type="submit" class="btn btn-outline-danger btn-sm" id="rec-delete-selected" data-bulk="1" data-once disabled
                data-confirm="Delete the selected recordings and their files?">Delete selected</button>
            <span class="small text-muted">Tick the rows, or use Select all</span>
        </div>
        <?php endif; ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr>
            <?php if ($canDelete): ?><th style="width:2rem"><input type="checkbox" id="rec-check-all" aria-label="Select all"></th><?php endif; ?>
            <th>ID</th><th>Created (UTC)</th><th>SIP user</th><th>CallerID</th><th>Called number</th><th>CDR</th>
            <th class="os-num">Duration</th><th class="os-num">Size</th><th>Status</th><th></th>
        </tr></thead>
        <tbody><?php foreach ($result['rows'] as $r):
            $playUrl = url('/recordings/' . (int) $r['id'] . '/play');
            $dlUrl = url('/recordings/' . (int) $r['id'] . '/play?download=1');
            $playLabel = trim((string) ($r['src'] ?? '')) . ' → ' . trim((string) ($r['dst_normalized'] ?? ''));
            ?>
            <tr>
                <?php if ($canDelete): ?>
                <td><input type="checkbox" name="ids[]" value="<?= e((string) $r['id']) ?>" class="rec-row-check"></td>
                <?php endif; ?>
                <td class="os-mono"><?= e($r['id']) ?></td>
                <td class="os-mono"><?= e(substr((string) $r['created_at'], 0, 19)) ?></td>
                <td class="os-mono"><?= e((string) ($r['sip_username'] ?? '') !== '' ? $r['sip_username'] : '—') ?></td>
                <td class="os-mono"><?= e((string) ($r['src'] ?? '') !== '' ? $r['src'] : '—') ?></td>
                <td class="os-mono"><?= e((string) ($r['dst_normalized'] ?? '') !== '' ? $r['dst_normalized'] : '—') ?></td>
                <td><?php if ($r['cdr_id']): ?><a href="<?= e(url('/cdrs/' . (int) $r['cdr_id'])) ?>" class="os-mono"><?= e($r['cdr_id']) ?></a><?php else: ?>—<?php endif; ?></td>
                <td class="os-num os-mono"><?= e(fmt_duration($r['duration_sec'] ?? 0)) ?></td>
                <td class="os-num"><?= e(number_format((int) $r['size_bytes'] / 1024, 1)) ?> KB</td>
                <td><span class="badge text-bg-<?= $r['status'] === 'available' ? 'success' : 'secondary' ?>"><?= e($r['status']) ?></span></td>
                <td class="text-end text-nowrap">
                    <?php if ($r['status'] === 'available'): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" title="Play"
                            data-os-play data-src="<?= e($playUrl) ?>" data-download="<?= e($dlUrl) ?>" data-label="<?= e($playLabel) ?>">
                            <i class="bi bi-play-circle"></i>
                        </button>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e($dlUrl) ?>" title="Download"><i class="bi bi-download"></i></a>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-once
                            formaction="<?= e(url('/recordings/' . (int) $r['id'] . '/delete')) ?>"
                            data-confirm="Delete this recording and its file?">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    </form>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <script>
    (function () {
        var form = document.getElementById('rec-bulk-form');
        var all = document.getElementById('rec-check-all');
        var bulk = document.getElementById('rec-delete-selected');
        if (!form) { return; }
        var sync = function () {
            var boxes = form.querySelectorAll('.rec-row-check');
            var n = 0;
            boxes.forEach(function (cb) { if (cb.checked) { n++; } });
            if (bulk) {
                bulk.disabled = n === 0;
                bulk.textContent = n > 0 ? 'Delete selected (' + n + ')' : 'Delete selected';
            }
            if (all) { all.checked = boxes.length > 0 && n === boxes.length; }
        };
        if (all) {
            all.addEventListener('change', function () {
                form.querySelectorAll('.rec-row-check').forEach(function (cb) { cb.checked = all.checked; });
                sync();
            });
        }
        form.querySelectorAll('.rec-row-check').forEach(function (cb) {
            cb.addEventListener('change', sync);
        });
        form.addEventListener('submit', function (ev) {
            var btn = ev.submitter;
            if (btn && btn.getAttribute('data-bulk') === '1' && !form.querySelector('.rec-row-check:checked')) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                return;
            }
            var msg = btn && btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        });
    })();
    </script>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/audio_player.php'; ?>
