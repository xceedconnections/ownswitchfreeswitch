<?php /** @var array $result */ $query = $_GET ?? []; ?>
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
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>ID</th><th>Created (UTC)</th><th>CDR</th><th class="os-num">Duration (s)</th><th class="os-num">Size</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($result['rows'] as $r): ?>
            <tr><td class="os-mono"><?= e($r['id']) ?></td><td class="os-mono"><?= e(substr((string) $r['created_at'], 0, 19)) ?></td>
                <td><?php if ($r['cdr_id']): ?><a href="<?= e(url('/cdrs/' . (int) $r['cdr_id'])) ?>" class="os-mono"><?= e($r['cdr_id']) ?></a><?php else: ?>-<?php endif; ?></td>
                <td class="os-num"><?= e($r['duration_sec']) ?></td><td class="os-num"><?= e(number_format((int) $r['size_bytes'] / 1024, 1)) ?> KB</td>
                <td><span class="badge text-bg-<?= $r['status'] === 'available' ? 'success' : 'secondary' ?>"><?= e($r['status']) ?></span></td>
                <td class="text-end"><?php if ($r['status'] === 'available'): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/recordings/' . (int) $r['id'] . '/play')) ?>">Play</a>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/recordings/' . (int) $r['id'] . '/play?download=1')) ?>">Download</a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
