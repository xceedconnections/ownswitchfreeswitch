<?php
/** @var array $cdr @var bool $showCost @var list<array> $legs */
$legs = $legs ?? [];
$code = (int) ($cdr['sip_response'] ?? 0);
$codeLabel = \Ownswitch\Support\SipCode::label($code);
$mediaPolicy = strtolower((string) ($cdr['media_policy_actual'] ?? $cdr['media_policy_requested'] ?? ''));
$mediaIp = trim((string) ($cdr['media_ip'] ?? ''));
if ($mediaIp === '') {
    if ($mediaPolicy === 'direct') {
        $mediaIp = 'peer (direct — not via switch)';
    } elseif (in_array($mediaPolicy, ['relay', 'record'], true)) {
        $pub = (string) \Ownswitch\App::config('app.integrations.opensips.public_ip', '');
        $mediaIp = $pub !== '' ? $pub . ' (RTPengine relay)' : 'switch RTPengine';
    } else {
        $mediaIp = '—';
    }
}
$sigIp = trim((string) ($cdr['source_ip'] ?? ''));
$dirLabel = match ((string) ($cdr['direction'] ?? '')) {
    'inbound-did', 'inbound' => 'Inbound DID',
    'outbound' => 'Outbound',
    default => ($cdr['direction'] ?? '') !== '' ? (string) $cdr['direction'] : '—',
};
$billed = fmt_duration($cdr['duration_billable_customer'] ?? 0);
if ($showCost) {
    $rows = [
        'UUID' => $cdr['uuid'], 'External key' => $cdr['external_cdr_key'], 'SIP Call-ID' => $cdr['sip_call_id'],
        'Customer' => $cdr['customer_name'] ?? '-', 'SIP user' => $cdr['sip_username'] ?? '-', 'CallerID' => $cdr['src'],
        'Direction' => $dirLabel,
        'Destination (raw)' => $cdr['dst_raw'], 'Destination (normalised)' => $cdr['dst_normalized'],
        'Description' => $cdr['area_description'] ?? '—',
        'Matched prefix' => $cdr['dst_prefix'],
        'Setup (' . user_timezone() . ')' => fmt_local(isset($cdr['setup_at']) ? (string) $cdr['setup_at'] : null),
        'Answer (' . user_timezone() . ')' => fmt_local(isset($cdr['answer_at']) ? (string) $cdr['answer_at'] : null),
        'End (' . user_timezone() . ')' => fmt_local(isset($cdr['end_at']) ? (string) $cdr['end_at'] : null),
        'Disposition' => $cdr['disposition'], 'SIP code' => $codeLabel,
        'Hangup initiator' => $cdr['hangup_initiator'] ?? '—',
        'Hangup cause' => $cdr['hangup_cause'] ?? $cdr['failure_reason'] ?? '—',
        'Failure reason' => $cdr['failure_reason'],
        'Caller IP' => $sigIp !== '' ? $sigIp : '—',
        'Signalling IP' => $sigIp !== '' ? $sigIp : '—',
        'Media IP' => $mediaIp,
        'Actual duration (s)' => $cdr['duration_actual'],
        'Billed duration (s)' => $cdr['duration_billable_customer'],
        'Billable customer (s)' => $cdr['duration_billable_customer'],
        'Customer rate / min' => $cdr['customer_rate'], 'Customer I/S' => ($cdr['customer_initial'] ?? '-') . '/' . ($cdr['customer_subsequent'] ?? '-'),
        'Connection fee' => $cdr['customer_connect_fee'], 'Charge (total)' => $cdr['customer_charge'] . ' ' . $cdr['currency'],
        'Rate plan version' => $cdr['customer_rate_plan_version_id'], 'Rating source' => $cdr['rating_source'], 'Settlement' => $cdr['settlement_status'],
        'Media policy (requested / actual)' => ($cdr['media_policy_requested'] ?? '-') . ' / ' . ($cdr['media_policy_actual'] ?? '-'), 'Recording' => $cdr['recording_status'],
    ];
} else {
    $rows = [
        'UUID' => $cdr['uuid'], 'External key' => $cdr['external_cdr_key'], 'SIP Call-ID' => $cdr['sip_call_id'],
        'Customer' => $cdr['customer_name'] ?? '-', 'SIP user' => $cdr['sip_username'] ?? '-', 'CallerID' => $cdr['src'],
        'Destination (raw)' => $cdr['dst_raw'], 'Destination (normalised)' => $cdr['dst_normalized'],
        'Description' => $cdr['area_description'] ?? '—',
        'Matched prefix' => $cdr['dst_prefix'],
        'Setup (' . user_timezone() . ')' => fmt_local(isset($cdr['setup_at']) ? (string) $cdr['setup_at'] : null),
        'Answer (' . user_timezone() . ')' => fmt_local(isset($cdr['answer_at']) ? (string) $cdr['answer_at'] : null),
        'End (' . user_timezone() . ')' => fmt_local(isset($cdr['end_at']) ? (string) $cdr['end_at'] : null),
        'Disposition' => $cdr['disposition'],
        'Hangup initiator' => $cdr['hangup_initiator'] ?? '—',
        'Hangup cause' => $cdr['hangup_cause'] ?? $cdr['failure_reason'] ?? '—',
        'Failure reason' => $cdr['failure_reason'],
        'Duration' => $billed,
        'Customer rate / min' => $cdr['customer_rate'], 'Customer I/S' => ($cdr['customer_initial'] ?? '-') . '/' . ($cdr['customer_subsequent'] ?? '-'),
        'Connection fee' => $cdr['customer_connect_fee'], 'Charge (total)' => $cdr['customer_charge'] . ' ' . $cdr['currency'],
        'Rate plan version' => $cdr['customer_rate_plan_version_id'], 'Rating source' => $cdr['rating_source'], 'Settlement' => $cdr['settlement_status'],
        'Recording' => $cdr['recording_status'],
    ];
}
if ($showCost) {
    $rows += [
        'Provider / Trunk' => trim(($cdr['carrier_name'] ?? '') . (($cdr['trunk_name'] ?? '') !== '' ? ' / ' . $cdr['trunk_name'] : '')) ?: '-',
        'Trunk' => $cdr['trunk_name'] ?? '-', 'Carrier' => $cdr['carrier_name'] ?? '-', 'Carrier rate / min' => $cdr['carrier_rate'], 'Carrier I/S' => ($cdr['carrier_initial'] ?? '-') . '/' . ($cdr['carrier_subsequent'] ?? '-'),
        'Billable carrier (s)' => $cdr['duration_billable_carrier'], 'Carrier cost (total)' => $cdr['carrier_cost'], 'Gross margin' => $cdr['gross_margin'],
        'CGRateS cost' => $cdr['cgrates_cost'], 'Rating discrepancy' => !empty($cdr['rating_discrepancy']) ? 'YES - investigate' : 'no',
    ];
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">CDR #<?= e($cdr['id']) ?><?= !empty($cdr['is_demo']) ? ' <span class="badge text-bg-warning">DEMO</span>' : '' ?></h1>
        <p class="os-page-sub">Full call detail record</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($cdr['recording_id'])): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/recordings/' . (int) $cdr['recording_id'] . '/play')) ?>"><i class="bi bi-play-circle me-1"></i>Play</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>
<div class="os-panel col-12 overflow-hidden"><table class="table table-sm mb-0 os-table">
    <?php foreach ($rows as $k => $v): ?>
        <tr><th class="w-50 fw-normal text-muted"><?= e($k) ?></th><td class="os-mono"><?= e($v === null || $v === '' ? '-' : $v) ?></td></tr>
    <?php endforeach; ?>
</table></div>

<?php
$sipTrace = trim((string) ($cdr['sip_trace'] ?? ''));
if ($sipTrace !== '' || $legs !== []):
?>
<div class="os-panel col-12 overflow-hidden mt-3" id="sip-trace">
    <div class="px-3 py-2 border-bottom fw-semibold"><i class="bi bi-diagram-3 me-1"></i>SIP trace</div>
    <?php if ($sipTrace !== ''): ?>
        <pre class="mb-0 px-3 py-2 small os-mono" style="white-space:pre-wrap;max-height:28rem;overflow:auto"><?= e($sipTrace) ?></pre>
    <?php endif; ?>
    <?php if ($legs !== []): ?>
    <table class="table table-sm mb-0 os-table">
        <thead><tr><th>#</th><th>Trunk</th><?php if ($showCost): ?><th>Code</th><?php endif; ?><th>Disposition</th><th>Reason</th><?php if ($showCost): ?><th>Media</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($legs as $leg): ?>
            <tr>
                <td class="os-mono"><?= e((string) ($leg['attempt_no'] ?? '')) ?></td>
                <td><?= e($leg['trunk_name'] ?? ('#' . ($leg['carrier_trunk_id'] ?? '—'))) ?></td>
                <?php if ($showCost): ?><td><?= e($leg['sip_code_label'] ?? (string) ($leg['sip_code'] ?? '—')) ?></td><?php endif; ?>
                <td><?= e((string) ($leg['disposition'] ?? '—')) ?></td>
                <td class="small text-muted"><?= e((string) ($leg['sip_reason'] ?? '')) ?></td>
                <?php if ($showCost): ?><td class="os-mono small"><?= e($mediaIp) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

