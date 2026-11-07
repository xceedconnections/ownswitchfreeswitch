<?php /** @var list<array<string,mixed>> $groups @var bool $canWrite */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">CLI Restriction</h1>
        <p class="os-page-sub">Lists of caller IDs a SIP user must not present. Assign one or more lists on the SIP user, under CLI and dial format.</p>
    </div>
    <?php if (!empty($canWrite)): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/cli-restrictions/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New list</a>
    <?php endif; ?>
</div>
<div class="os-table-wrap">
<?php if ($groups === []): ?>
    <div class="os-empty">
        <div class="os-empty-title">No CLI restriction lists</div>
        <div>Create a list, add the caller IDs to block, then select the list on a SIP user.</div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 os-table">
            <thead><tr><th>Name</th><th>Status</th><th class="os-num">Caller IDs</th><th>Notes</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><a href="<?= e(url('/cli-restrictions/' . (int) $g['id'])) ?>"><?= e((string) $g['name']) ?></a></td>
                    <td><span class="badge text-bg-<?= ($g['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= ($g['status'] ?? '') === 'active' ? 'Active' : 'Inactive' ?></span></td>
                    <td class="os-num"><?= e((string) ($g['number_count'] ?? 0)) ?></td>
                    <td><?= e((string) ($g['notes'] ?? '')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cli-restrictions/' . (int) $g['id'])) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
