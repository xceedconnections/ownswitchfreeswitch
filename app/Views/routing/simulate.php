<?php /** @var array $customers @var ?array $result @var array $values @var ?string $error */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Route Simulator</h1>
        <p class="os-page-sub">Dry run only — blocks, rates, longest-prefix rules and carrier attempt order. No call is placed.</p>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/routing/simulate')) ?>" class="os-toolbar mb-3">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-0" for="customer_id">Customer</label>
            <select class="form-select form-select-sm" id="customer_id" name="customer_id" required><option value="">- select -</option>
                <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $values['customer_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="dst">Destination number</label><input class="form-control form-control-sm" id="dst" name="dst" value="<?= e($values['dst']) ?>" maxlength="40" required></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="seconds">Sample duration (s)</label><input class="form-control form-control-sm" id="seconds" name="seconds" value="<?= e($values['seconds']) ?>" maxlength="3" inputmode="numeric"></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Simulate</button></div>
    </div>
</form>
<?php if ($result): ?>
    <div class="os-panel mb-3"><div class="p-3 p-md-4">
        <div class="mb-2"><span class="text-muted">Normalised destination:</span> <span class="os-mono"><?= e($result['destination']) ?></span>
            <?php if (!empty($result['blocked'])): ?><span class="badge text-bg-danger ms-2">BLOCKED</span><?php endif; ?></div>
        <?php foreach ($result['notes'] as $n): ?><div class="alert alert-warning py-1 mb-2"><?= e($n) ?></div><?php endforeach; ?>
        <?php if (!empty($result['customer_rate'])): $cr = $result['customer_rate']; ?>
            <div class="mb-2"><strong>Customer rate:</strong> <span class="os-mono"><?= e($cr['rate']) ?></span>/min, I/S <span class="os-mono"><?= e($cr['initial']) ?>/<?= e($cr['subsequent']) ?></span>,
                fee <span class="os-mono"><?= e($cr['connect_fee']) ?></span>, prefix <span class="os-mono"><?= e($cr['prefix'] ?: '(default)') ?></span> via <?= e($cr['source']) ?>.
                Sample <?= e($cr['sample_seconds']) ?>s &rarr; billable <?= e($cr['sample_billable']) ?>s = <span class="os-mono"><?= e($cr['sample_total']) ?></span> <?= e($cr['currency']) ?></div>
        <?php endif; ?>
        <?php if (!empty($result['strategy'])): ?><div class="mb-2"><strong>Plan:</strong> <?= e($result['plan'] ?? '') ?> (<?= e($result['strategy']) ?>)</div><?php endif; ?>
    </div>
    <?php if (!empty($result['routes'])): ?>
        <div class="table-responsive"><table class="table table-sm mb-0 os-table">
            <thead><tr><th>#</th><th>Carrier / trunk</th><th>Host</th><th>Dialled out</th><th>From</th><th>DNC</th><th class="os-num">Priority</th><th class="os-num">Carrier rate</th><th class="os-num">Sample cost</th></tr></thead>
            <tbody><?php foreach ($result['routes'] as $rt): ?>
                <tr<?= !empty($rt['dnc_blocked']) ? ' class="table-danger"' : '' ?>><td><?= e($rt['attempt_order']) ?></td><td><?= e($rt['carrier_name']) ?> / <?= e($rt['trunk_name']) ?></td><td class="os-mono"><?= e($rt['host']) ?>:<?= e($rt['port']) ?></td>
                    <td class="os-mono"><?= e($rt['dialled_out'] ?? $result['destination'] ?? '') ?></td>
                    <td class="os-mono"><?= e($rt['from_rewrite'] ?? '—') ?></td>
                    <td><?= !empty($rt['dnc_blocked']) ? '<span class="badge text-bg-danger">Blocked</span>' : (!empty($rt['dnc_group_id']) ? '<span class="text-muted">OK</span>' : '—') ?></td>
                    <td class="os-num"><?= e($rt['priority']) ?></td><td class="os-num os-mono"><?= e($rt['carrier_rate'] ?? 'unrated') ?></td>
                    <td class="os-num os-mono"><?= e($rt['carrier_sample_cost'] ?? '-') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?></div>
<?php endif; ?>
