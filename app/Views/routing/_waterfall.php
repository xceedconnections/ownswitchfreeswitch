<?php
/**
 * Trunk-group waterfall picker (recent stats + drag/drop).
 * @var array $f @var string $id @var string $name @var ?string $err @var bool $readonly
 * @var array $wfMeta @var array $wfOpts @var list<string> $wfOn @var list<string> $wfOff @var list<string> $wfUsed
 * @var int $wfStatsWindow
 */
$wfStatsWindow = \Ownswitch\Services\Routing\TrunkHourStatsService::normalizeMinutes((int) ($wfStatsWindow ?? 60));
$wfWindowLabel = \Ownswitch\Services\Routing\TrunkHourStatsService::windowLabel($wfStatsWindow);
$wfWindowOpts = [
    15 => 'Last 15 min',
    30 => 'Last 30 min',
    60 => 'Last 1 hour',
];
$wfBasePath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$ordinal = static function (int $n): string {
    $suf = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
    $v = $n % 100;
    if ($v >= 11 && $v <= 13) {
        return $n . 'th';
    }
    return $n . ($suf[$n % 10] ?? 'th');
};
$bestId = null;
$bestAsr = -1.0;
foreach ($wfOn as $tid) {
    $a = (float) ($wfMeta[$tid]['asr'] ?? 0);
    $att = (int) ($wfMeta[$tid]['attempts'] ?? 0);
    if ($att > 0 && $a > $bestAsr) {
        $bestAsr = $a;
        $bestId = $tid;
    }
}
$renderRow = static function (string $tid, bool $on, int $rank) use ($wfMeta, $wfOpts, $ordinal, $bestId, $readonly): void {
    $m = $wfMeta[$tid] ?? null;
    $title = $m['name'] ?? ($wfOpts[$tid] ?? ('Trunk #' . $tid));
    $carrier = $m['carrier'] ?? '';
    $asr = (float) ($m['asr'] ?? 0);
    $acdLabel = (string) ($m['acd_label'] ?? '0s');
    $attempts = (int) ($m['attempts'] ?? 0);
    $answered = (int) ($m['answered'] ?? 0);
    $failed = (int) ($m['failed'] ?? max(0, $attempts - $answered));
    $longAnswered = (int) ($m['long_answered'] ?? 0);
    $longAcdLabel = (string) ($m['long_acd_label'] ?? '0s');
    $share = (float) ($m['share'] ?? 0);
    $barCls = $asr >= 70 ? 'is-good' : ($asr >= 40 ? 'is-mid' : 'is-low');
    $isTop = $on && $bestId !== null && $bestId === $tid;
    ?>
    <div class="os-wf-row<?= $on ? '' : ' is-off' ?>" data-wf-row draggable="<?= $readonly ? 'false' : 'true' ?>" data-id="<?= e($tid) ?>" data-asr="<?= e((string) $asr) ?>" data-acd="<?= e((string) ($m['acd'] ?? 0)) ?>" data-long-acd="<?= e((string) ($m['long_acd'] ?? 0)) ?>" data-attempts="<?= e((string) $attempts) ?>">
        <button type="button" class="os-wf-handle" data-wf-handle aria-label="Drag to reorder"<?= $readonly ? ' disabled' : '' ?>>
            <i class="bi bi-grip-vertical"></i>
        </button>
        <div class="os-wf-rank<?= $on ? '' : ' is-off' ?>" data-wf-rank><?= $on ? e($ordinal($rank)) : 'Off' ?></div>
        <div class="os-wf-body">
            <div class="os-wf-title-row">
                <span class="os-wf-name"><?= e($title) ?></span>
                <?php if ($carrier !== ''): ?><span class="os-wf-badge"><?= e($carrier) ?></span><?php endif; ?>
                <?php if ($isTop): ?><span class="os-wf-badge os-wf-badge-top">Top performer</span><?php endif; ?>
            </div>
            <div class="os-wf-stats">
                <span class="os-wf-meter <?= e($barCls) ?>" title="<?= e(number_format($asr, 1)) ?>% ASR">
                    <span style="width:<?= e((string) max(0, min(100, $asr))) ?>%"></span>
                </span>
                <span class="os-wf-stat"><?= e(number_format($asr, 0)) ?>% ASR</span>
                <span class="os-wf-dot">·</span>
                <span class="os-wf-stat"><?= e(number_format($answered)) ?> connected</span>
                <span class="os-wf-dot">·</span>
                <span class="os-wf-stat"><?= e(number_format($failed)) ?> failed</span>
                <span class="os-wf-dot">·</span>
                <span class="os-wf-stat"><?= e($acdLabel) ?> avg talk</span>
                <span class="os-wf-dot">·</span>
                <span class="os-wf-stat"><?= e(number_format($longAnswered)) ?> &gt;12s (<?= e($longAcdLabel) ?>)</span>
                <span class="os-wf-dot">·</span>
                <span class="os-wf-stat"><?= e(number_format($attempts)) ?> attempts · <?= e(number_format($share, 0)) ?>%</span>
            </div>
        </div>
        <?php if (!$readonly): ?>
            <label class="os-wf-switch" title="<?= $on ? 'Switch off' : 'Switch on' ?>">
                <input type="checkbox" data-wf-toggle<?= $on ? ' checked' : '' ?> aria-label="Use <?= e($title) ?> for calls">
                <span class="os-wf-switch-ui"></span>
            </label>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="os-wf<?= $err ? ' is-invalid' : '' ?>" data-os-waterfall<?= $readonly ? ' data-readonly="1"' : '' ?>>
    <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e(implode(',', $wfOn)) ?>" data-wf-on-input>
    <input type="hidden" name="trunk_off_ids" value="<?= e(implode(',', $wfOff)) ?>" data-wf-off-input>
    <input type="hidden" name="stats_window" value="<?= e((string) $wfStatsWindow) ?>">

    <div class="os-wf-head">
        <div>
            <div class="os-wf-kicker">Your waterfall</div>
            <div class="form-text mb-0">Connectivity from <?= e($wfWindowLabel) ?></div>
        </div>
        <?php if (!$readonly): ?>
            <div class="os-wf-sorts">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-wf-sort="asr">Best ASR first</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-wf-sort="long">Long connected (&gt;12s) first</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="os-wf-windows mb-2" role="group" aria-label="Stats window">
        <?php foreach ($wfWindowOpts as $mins => $label):
            $qs = http_build_query(array_filter([
                'stats_window' => $mins,
            ]));
            $href = $wfBasePath . ($qs !== '' ? '?' . $qs : '');
            $active = $mins === $wfStatsWindow;
            ?>
            <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
               href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="os-wf-flow" data-wf-flow aria-live="polite"></div>

    <div class="os-wf-list" data-wf-on>
        <?php foreach ($wfOn as $i => $tid): $renderRow($tid, true, $i + 1); endforeach; ?>
    </div>
    <?php if ($wfOn === []): ?>
        <div class="os-wf-empty" data-wf-empty-on>No trunks on yet — add one below, then drag to set order.</div>
    <?php endif; ?>

    <div class="os-wf-off-head">Switched off — not used for calls</div>
    <div class="os-wf-list os-wf-list-off" data-wf-off>
        <?php foreach ($wfOff as $tid): $renderRow($tid, false, 0); endforeach; ?>
    </div>

    <?php if (!$readonly): ?>
        <div class="os-wf-add-wrap">
            <select class="form-select" data-wf-add aria-label="Add trunk">
                <option value="">+ Add trunk…</option>
                <?php foreach ($wfMeta as $tid => $m): ?>
                    <option value="<?= e($tid) ?>"
                            data-name="<?= e($m['name']) ?>"
                            data-carrier="<?= e($m['carrier']) ?>"
                            data-asr="<?= e((string) $m['asr']) ?>"
                            data-acd="<?= e((string) $m['acd']) ?>"
                            data-acd-label="<?= e($m['acd_label']) ?>"
                            data-long-acd="<?= e((string) ($m['long_acd'] ?? 0)) ?>"
                            data-answered="<?= e((string) ($m['answered'] ?? 0)) ?>"
                            data-failed="<?= e((string) ($m['failed'] ?? 0)) ?>"
                            data-attempts="<?= e((string) $m['attempts']) ?>"
                            data-share="<?= e((string) $m['share']) ?>"
                            <?= in_array((string) $tid, $wfUsed, true) ? ' disabled' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text mt-2">Stats are from <?= e($wfWindowLabel) ?>: ASR, connected, failed, and long calls (&gt;12s). Use the sort buttons to reorder by connectivity.</div>
        </div>
    <?php endif; ?>

    <template data-wf-row-tpl>
        <div class="os-wf-row" data-wf-row draggable="true" data-id="" data-asr="0" data-acd="0" data-long-acd="0" data-attempts="0">
            <button type="button" class="os-wf-handle" data-wf-handle aria-label="Drag to reorder"><i class="bi bi-grip-vertical"></i></button>
            <div class="os-wf-rank" data-wf-rank>1st</div>
            <div class="os-wf-body">
                <div class="os-wf-title-row">
                    <span class="os-wf-name" data-wf-name></span>
                    <span class="os-wf-badge" data-wf-carrier hidden></span>
                    <span class="os-wf-badge os-wf-badge-top" data-wf-top hidden>Top performer</span>
                </div>
                <div class="os-wf-stats">
                    <span class="os-wf-meter is-low" data-wf-meter><span data-wf-meter-fill style="width:0%"></span></span>
                    <span class="os-wf-stat"><span data-wf-asr>0</span>% ASR</span>
                    <span class="os-wf-dot">·</span>
                    <span class="os-wf-stat"><span data-wf-answered>0</span> connected</span>
                    <span class="os-wf-dot">·</span>
                    <span class="os-wf-stat"><span data-wf-failed>0</span> failed</span>
                    <span class="os-wf-dot">·</span>
                    <span class="os-wf-stat"><span data-wf-acd-label>0s</span> avg talk</span>
                    <span class="os-wf-dot">·</span>
                    <span class="os-wf-stat"><span data-wf-attempts>0</span> attempts · <span data-wf-share>0</span>%</span>
                </div>
            </div>
            <label class="os-wf-switch" title="Switch off">
                <input type="checkbox" data-wf-toggle checked aria-label="Use trunk for calls">
                <span class="os-wf-switch-ui"></span>
            </label>
        </div>
    </template>
</div>
