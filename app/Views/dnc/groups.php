<?php
/** @var list<array<string,mixed>> $groups @var bool $canWrite */
$canWrite = !empty($canWrite);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">DNC</h1>
        <p class="os-page-sub">Do Not Call groups — assign a group on a trunk to block listed destinations before connect</p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/dnc/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New group</a>
    <?php endif; ?>
</div>

<div class="os-table-wrap">
<?php if ($groups === []): ?>
    <div class="os-empty">
        <div class="os-empty-title">No DNC groups</div>
        <div>Create a group, add numbers (or upload CSV/Excel), then select it on a Trunk.</div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th class="os-num">Numbers</th>
                <th>Notes</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><a href="<?= e(url('/dnc/' . (int) $g['id'])) ?>"><?= e((string) $g['name']) ?></a></td>
                    <td>
                        <?php $on = ($g['status'] ?? '') === 'active'; ?>
                        <span class="badge text-bg-<?= $on ? 'success' : 'secondary' ?>"><?= $on ? 'Active' : 'Disabled' ?></span>
                    </td>
                    <td class="os-num os-mono"><?= e(number_format((int) ($g['number_count'] ?? 0))) ?></td>
                    <td class="small text-muted"><?= e((string) ($g['notes'] ?? '—')) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/dnc/' . (int) $g['id'])) ?>">Numbers</a>
                        <?php if ($canWrite): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/dnc/' . (int) $g['id'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<p class="small text-muted mt-2 mb-0">OpenSIPS checks DNC with Redis (O(1)) or an indexed MySQL cache — not PHP — so bulk dialing stays fast.</p>
