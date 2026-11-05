<?php
/** @var string $title @var string $blurb @var list<string> $columns @var string $slug */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= e($blurb) ?></p>
    </div>
</div>

<div class="alert alert-info mb-3" role="status">
    MagnusBilling menu parity: this module is scaffolded so navigation matches Magnus.
    The operational engine for <span class="os-mono"><?= e($slug) ?></span> is not live yet — no fake data is shown.
</div>

<div class="os-table-wrap">
    <div class="os-empty">
        <div class="os-empty-title">No <?= e(strtolower($title)) ?> records</div>
        <div>Create/import support will land when this module is activated. Core VoIP billing (Users, SIP, Plans, Tariffs, Prefixes, Trunks, Trunk Groups, CDR, Refills) is available from the same menu.</div>
    </div>
    <div class="table-responsive border-top">
        <table class="table table-sm mb-0 os-table">
            <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <tr><td colspan="<?= e((string) count($columns)) ?>" class="text-muted text-center py-4">— empty —</td></tr>
            </tbody>
        </table>
    </div>
</div>
