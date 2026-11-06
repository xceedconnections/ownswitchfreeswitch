<?php

declare(strict_types=1);

namespace Ownswitch\Services\Switching;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Cdr\CdrIngestService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Settings\SettingsService;
use Ownswitch\Services\Routing\RouteSimulator;
use Ownswitch\Services\Routing\TrunkHourStatsService;
use Ownswitch\Support\Destination;
use Ownswitch\Support\DstFormat;
use Ownswitch\Support\MediaPolicy;
use Ownswitch\Support\SipHosts;
use PDO;

/**
 * Portal call flow for FreeSWITCH xml_curl.
 * REGISTER → directory. INVITE → this dialplan (authz, policies, prepaid, DNC, TG waterfall, DID).
 */
final class FreeswitchCallFlow
{
    public function __construct(private PDO $db)
    {
    }

    public function dialplan(Request $r): string
    {
        $settings = new SettingsService($this->db);
        if ($settings->bool('kill_switch')) {
            return $this->reject('503', 'CALL_REJECTED');
        }

        $rawDest = (string) $r->input('Caller-Destination-Number', '');
        $dest = Destination::normalize($rawDest);
        $user = $this->sipUserFromRequest($r);
        $ip = (string) $r->input('variable_sip_network_ip', $r->input('network_addr', $r->input('ip', '')));
        $account = $this->findAccount($user, $ip);
        $didUser = $dest !== '' ? $this->didOwner($dest) : null;

        if ($didUser !== null && ($account === null || strcasecmp((string) $account['username'], $didUser) !== 0)) {
            return $this->bridgeUser($didUser);
        }
        if ($account === null) {
            return $this->reject('403', 'CALL_REJECTED');
        }
        if (!$this->allowsCalls($account)) {
            return $this->reject('402', 'CALL_REJECTED');
        }
        if ($dest === '') {
            return $this->reject('484', 'INVALID_NUMBER_FORMAT');
        }

        $cid = (int) ($account['customer_id'] ?? 0);
        if ($this->destinationBlocked($cid, $dest)) {
            return $this->reject('403', 'CALL_REJECTED');
        }

        $groupId = (int) ($account['dnc_group_id'] ?? 0);
        if ($groupId > 0 && $this->dncHit($groupId, $dest)) {
            return $this->reject('403', 'CALL_REJECTED');
        }

        $callId = trim((string) $r->input('variable_sip_call_id', $r->input('Unique-ID', '')));
        if ($callId === '') {
            $callId = 'fs-' . bin2hex(random_bytes(8));
        }
        $maxSeconds = $this->prepaidGate($account, $dest, $callId);
        if ($maxSeconds === false) {
            return $this->reject('402', 'CALL_REJECTED');
        }

        $resolver = new RateResolver($this->db, $settings);
        $rate = $cid > 0 ? $resolver->customer($cid, $dest) : null;
        if ($rate === null) {
            return $this->reject('404', 'NO_ROUTE_DESTINATION');
        }
        if ($this->userOverCap($account, $rate)) {
            return $this->reject('403', 'CALL_REJECTED');
        }

        // Align with RouteSimulator: SIP user TG override → tariff TG (not customers.routing_plan_id).
        $planId = (int) ($account['routing_plan_id'] ?? 0);
        if ($planId <= 0 && !empty($rate['routing_plan_id'])) {
            $planId = (int) $rate['routing_plan_id'];
        }
        $trunks = $this->trunksFor($planId, $dest);
        if ($trunks === []) {
            return $this->reject('503', 'NORMAL_TEMPORARY_FAILURE');
        }

        $maxAttempts = max(1, min(20, $settings->int('failover_max_retries', 5)));
        $trunks = array_slice($trunks, 0, $maxAttempts);

        $userFmt = DstFormat::normalize((string) ($account['dst_format'] ?? DstFormat::AS_IS));
        $media = MediaPolicy::resolve(
            isset($account['media_policy']) ? (string) $account['media_policy'] : null,
            $this->db
        );
        // direct = peer-to-peer RTP (bypass_media); relay/record = media through FreeSWITCH.
        $bypass = $media === 'direct' ? 'true' : 'false';
        $inboundCid = $this->inboundCallerId($r);

        $bridges = [];
        foreach ($trunks as $t) {
            $cidNum = $this->outboundCallerId($account, $t, $inboundCid, $dest);
            if ($this->callerIdLengthReject($account, $t, $cidNum)) {
                continue; // try next trunk / reject if none remain
            }
            $bridges[] = $this->bridgeLeg($account, $t, $dest, $userFmt, $cidNum);
        }
        if ($bridges === []) {
            return $this->reject('484', 'CALL_REJECTED');
        }
        $data = implode('|', $bridges);
        $userName = (string) $account['username'];
        $userMax = (int) ($account['max_call_duration'] ?? 0);
        if ($userMax > 0) {
            $maxSeconds = $maxSeconds > 0 ? min($maxSeconds, $userMax) : $userMax;
        }
        if ($maxSeconds <= 0) {
            $maxSeconds = max(0, $settings->int('prepaid_hard_cap_seconds', 0));
        }
        $cap = $maxSeconds > 0
            ? '<action application="sched_hangup" data="+' . $maxSeconds . ' ALLOTTED_TIMEOUT"/>'
            : '';
        $failCodes = $this->continueOnFail($settings);

        $limit = '';
        $maxCh = (int) ($account['max_channels'] ?? 0);
        if ($maxCh > 0) {
            // Concurrent-call guard (mod_hash / limit).
            $limit .= '<action application="limit" data="hash outbound ' . $this->x($userName) . ' ' . $maxCh . '"/>';
        }
        $maxCps = (int) ($account['max_cps'] ?? 0);
        if ($maxCps > 0) {
            // Calls-per-second: N new calls per 1-second window.
            $limit .= '<action application="limit" data="hash cps ' . $this->x($userName) . ' ' . $maxCps . '/1"/>';
        }

        $dtmf = $this->fsDtmfType((string) ($account['dtmfmode'] ?? 'RFC2833'));
        $dtmfActs = '';
        if ($dtmf !== '') {
            $dtmfActs .= '<action application="set" data="dtmf_type=' . $dtmf . '"/>';
            if ($dtmf === 'inband') {
                $dtmfActs .= '<action application="start_dtmf"/>';
            }
        }
        if (strtolower((string) ($account['dtmf_pass'] ?? 'yes')) === 'no') {
            // Absorb keypad digits so they are not forwarded to the B-leg / vendor.
            $dtmfActs .= '<action application="set" data="drop_dtmf=true"/>';
        }

        $record = '';
        if ($media === 'record') {
            $recDir = rtrim((string) App::config('app.recordings_dir', ''), '/\\');
            if ($recDir === '') {
                $recDir = '/usr/local/freeswitch/recordings';
            }
            $record = '<action application="set" data="RECORD_STEREO=true"/>'
                . '<action application="record_session" data="' . $this->x($recDir) . '/${uuid}.wav"/>';
        }

        return $this->extension(
            'outbound',
            '.*',
            '<action application="set" data="bypass_media=' . $bypass . '"/>'
            . '<action application="set" data="hangup_after_bridge=true"/>'
            . '<action application="set" data="continue_on_fail=' . $this->x($failCodes) . '"/>'
            . '<action application="set" data="fail_on_single_reject=false"/>'
            . '<action application="set" data="os_sip_user=' . $this->x($userName) . '"/>'
            . '<action application="set" data="os_direction=outbound"/>'
            . '<action application="set" data="os_media_policy=' . $this->x($media) . '"/>'
            . $dtmfActs
            . $limit
            . $record
            . $cap
            . '<action application="bridge" data="' . $this->x($data) . '"/>'
        );
    }

    /** Map portal DTMF mode → FreeSWITCH dtmf_type (empty = leave FS default / auto). */
    private function fsDtmfType(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'rfc2833', 'rfc-2833', '2833' => 'rfc2833',
            'info' => 'info',
            'inband' => 'inband',
            'auto' => '',
            default => 'rfc2833',
        };
    }

    public function ingestCdr(string $xml): void
    {
        if ($xml === '' || !str_contains($xml, '<cdr')) {
            return;
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            error_log('[ownswitch] fs cdr: XML parse failed');
            return;
        }
        $vars = [];
        foreach ($sx->variables->children() ?? [] as $name => $node) {
            // xml_cdr leaves stamp values percent-encoded (%20, %3A). Decode once or setup_at is rejected and the row is dropped.
            $vars[(string) $name] = urldecode((string) $node);
        }
        if (($vars['is_loopback'] ?? '') === '1' || str_starts_with((string) ($vars['channel_name'] ?? ''), 'loopback/')) {
            return;
        }
        $uuid = trim((string) ($vars['uuid'] ?? ''));
        $parent = trim((string) ($vars['originating_leg_uuid'] ?? ''));
        $isChild = $parent !== '' && $parent !== $uuid;
        $trace = $this->sipTraceText($vars, $sx);
        if ($isChild) {
            $this->attachChildLeg($parent, $vars, $trace);
            return;
        }

        $bill = (int) ($vars['billsec'] ?? 0);
        $answerEpoch = (int) ($vars['answer_epoch'] ?? 0);
        $endEpoch = (int) ($vars['end_epoch'] ?? 0);
        if ($bill <= 0 && $answerEpoch > 0 && $endEpoch > $answerEpoch) {
            $bill = $endEpoch - $answerEpoch;
        }
        $dest = (string) ($vars['destination_number'] ?? $vars['sip_req_user'] ?? $vars['sip_to_user'] ?? '');
        if ($dest === '' && isset($sx->callflow->caller_profile->destination_number)) {
            $dest = urldecode((string) $sx->callflow->caller_profile->destination_number);
        }
        $src = $this->presentedCli($vars);
        $user = (string) ($vars['os_sip_user'] ?? $vars['sip_auth_username'] ?? $vars['username'] ?? '');
        if ($user === '') {
            $user = (string) ($vars['sip_from_user'] ?? '');
        }
        $cause = strtoupper((string) ($vars['hangup_cause'] ?? 'NORMAL_CLEARING'));
        $disp = $bill > 0 ? 'ANSWERED' : match (true) {
            str_contains($cause, 'BUSY') => 'BUSY',
            str_contains($cause, 'NO_ANSWER'), str_contains($cause, 'NO_USER') => 'NO_ANSWER',
            str_contains($cause, 'CANCEL') => 'CANCELLED',
            str_contains($cause, 'REJECT') => 'FAILED',
            default => 'FAILED',
        };
        $trunkId = null;
        if (preg_match('/gw-(\d+)/', (string) ($vars['last_bridge_to'] ?? $vars['bridge_channel'] ?? $vars['channel_name'] ?? ''), $m)) {
            $trunkId = (int) $m[1];
        }
        $sipCode = (int) ($vars['sip_term_status'] ?? 0);
        if ($sipCode <= 0) {
            $sipCode = (int) ($vars['sip_invite_failure_status'] ?? 0);
        }
        if ($sipCode <= 0 && preg_match('/sip:(\d{3})/', (string) ($vars['proto_specific_hangup_cause'] ?? ''), $cm)) {
            $sipCode = (int) $cm[1];
        }
        $mediaPol = strtolower(trim((string) ($vars['os_media_policy'] ?? '')));
        if (!in_array($mediaPol, MediaPolicy::CHOICES, true)) {
            $mediaPol = '';
        }
        $direction = 'outbound';
        $ctx = strtolower((string) ($vars['context'] ?? $vars['os_direction'] ?? ''));
        if (str_contains($ctx, 'inbound') || str_contains($ctx, 'did') || !empty($vars['os_did_inbound'])) {
            $direction = 'inbound-did';
        }
        $callId = trim((string) ($vars['sip_call_id'] ?? ''));
        if ($callId === '') {
            $callId = $uuid !== '' ? $uuid : md5($xml);
        }
        $svc = new CdrIngestService(
            $this->db,
            new RatingService(),
            new RateResolver($this->db, new SettingsService($this->db)),
            new LedgerService($this->db),
            new PrepaidService($this->db, new LedgerService($this->db), new RatingService()),
            new SettingsService($this->db),
            new \Ownswitch\Repositories\CdrRepository($this->db),
        );
        $res = $svc->ingest([
            'external_cdr_key' => $uuid !== '' ? $uuid : md5($xml),
            'uuid' => $uuid,
            'disposition' => $disp,
            'dst' => $dest,
            'src' => $src,
            'username' => $user,
            'sip_call_id' => $callId,
            'setup_at' => $this->fsTime((string) ($vars['start_stamp'] ?? ''), (string) ($vars['start_epoch'] ?? '')),
            'answer_at' => $answerEpoch > 0 ? $this->fsTime((string) ($vars['answer_stamp'] ?? ''), (string) $answerEpoch) : null,
            'end_at' => $this->fsTime((string) ($vars['end_stamp'] ?? ''), (string) ($vars['end_epoch'] ?? '')),
            'duration' => (string) max(0, $bill),
            'sip_response' => $sipCode > 0 ? $sipCode : null,
            'carrier_trunk_id' => $trunkId,
            'source_ip' => (string) ($vars['sip_network_ip'] ?? $vars['sip_received_ip'] ?? ''),
            'direction' => $direction,
            'hangup_initiator' => $this->mapHangupInitiator((string) ($vars['sip_hangup_disposition'] ?? ''), $cause),
            'hangup_cause' => $cause !== '' ? $cause : null,
            'failure_reason' => $bill > 0 ? null : ($cause !== '' ? $cause : null),
            'sip_trace' => $trace,
            'media_policy_requested' => $mediaPol !== '' ? $mediaPol : null,
            'media_policy_actual' => $mediaPol !== '' ? $mediaPol : null,
            'recording_status' => $mediaPol === 'record' ? 'pending' : null,
        ], 'freeswitch');
        if (($res['status'] ?? '') === 'invalid') {
            error_log('[ownswitch] fs cdr invalid: ' . implode('; ', $res['errors'] ?? []));
            return;
        }
        $cdrId = (int) ($res['id'] ?? 0);
        if ($cdrId > 0) {
            $this->insertAttemptLeg($callId, $cdrId, 1, $trunkId, $sipCode, $cause, $disp);
            if ($uuid !== '') {
                $this->db->prepare(
                    'UPDATE cdr_attempt_legs SET sip_call_id = ?, cdr_id = ? WHERE sip_call_id = ?'
                )->execute([$callId, $cdrId, 'fsuuid:' . $uuid]);
            }
        }
    }

    /** @param array<string,string> $vars */
    private function sipTraceText(array $vars, \SimpleXMLElement $sx): string
    {
        $lines = ['--- signaling ---'];
        $map = [
            'sip_req_uri' => 'Request',
            'sip_from_display' => 'From display',
            'caller_id_name' => 'Caller ID name',
            'caller_id_number' => 'Caller ID number',
            'sip_full_from' => 'From',
            'sip_full_to' => 'To',
            'sip_call_id' => 'Call-ID',
            'sip_full_via' => 'Via',
            'sip_user_agent' => 'User-Agent',
            'sip_contact_uri' => 'Contact',
            'channel_name' => 'Channel',
            'sip_network_ip' => 'Source IP',
            'sip_local_network_addr' => 'Switch IP',
            'destination_number' => 'Destination',
            'hangup_cause' => 'Hangup cause',
            'sip_hangup_disposition' => 'SIP hangup',
            'sip_term_status' => 'Term status',
            'proto_specific_hangup_cause' => 'Proto cause',
            'sip_invite_failure_status' => 'Invite failure',
            'sip_invite_failure_phrase' => 'Invite phrase',
            'bridge_channel' => 'Bridge',
            'last_bridge_hangup_cause' => 'Bridge hangup',
            'billsec' => 'Billsec',
        ];
        foreach ($map as $key => $label) {
            $v = trim((string) ($vars[$key] ?? ''));
            if ($v !== '') {
                $lines[] = $label . ': ' . $v;
            }
        }
        if (isset($sx->app_log->application)) {
            $lines[] = '--- dialplan ---';
            foreach ($sx->app_log->application as $app) {
                $lines[] = trim((string) $app['app_name'] . ' ' . (string) $app['app_data']);
            }
        }
        return mb_substr(implode("\n", $lines), 0, 60000);
    }

    private function fsTime(string $stamp, string $epoch): string
    {
        $stamp = trim(urldecode($stamp));
        if ($stamp !== '') {
            try {
                return (new \DateTimeImmutable($stamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
            }
        }
        if ($epoch !== '' && ctype_digit($epoch) && (int) $epoch > 0) {
            return gmdate('Y-m-d H:i:s', (int) $epoch);
        }
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Gateway leg: attach SIP trace to the customer CDR. This leg is not billed on its own.
     *
     * @param array<string,string> $vars
     */
    private function attachChildLeg(string $parentUuid, array $vars, string $trace): void
    {
        $st = $this->db->prepare('SELECT id, sip_call_id, sip_trace FROM cdrs WHERE external_cdr_key = ? LIMIT 1');
        $st->execute(['freeswitch:' . $parentUuid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $callId = is_array($row) ? (string) ($row['sip_call_id'] ?? '') : '';
        if ($callId === '') {
            $callId = trim((string) ($vars['os_parent_call_id'] ?? ''));
        }
        if ($callId === '') {
            $callId = 'fsuuid:' . $parentUuid;
        }
        $cdrId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        if ($cdrId > 0 && $trace !== '') {
            $prev = (string) ($row['sip_trace'] ?? '');
            $merged = trim($prev . "\n\n--- gateway leg ---\n" . $trace);
            $this->db->prepare('UPDATE cdrs SET sip_trace = ? WHERE id = ?')->execute([mb_substr($merged, 0, 60000), $cdrId]);
        }
        $trunkId = null;
        if (preg_match('/gw-(\d+)/', (string) ($vars['channel_name'] ?? ''), $m)) {
            $trunkId = (int) $m[1];
        }
        $code = (int) ($vars['sip_term_status'] ?? $vars['sip_invite_failure_status'] ?? 0);
        $cause = strtoupper((string) ($vars['hangup_cause'] ?? ''));
        $bill = (int) ($vars['billsec'] ?? 0);
        $disp = $bill > 0 ? 'ANSWERED' : ($cause !== '' ? $cause : 'FAILED');
        $next = 2;
        if ($callId !== '') {
            $n = $this->db->prepare('SELECT COALESCE(MAX(attempt_no), 1) FROM cdr_attempt_legs WHERE sip_call_id = ?');
            $n->execute([$callId]);
            $next = ((int) $n->fetchColumn()) + 1;
        }
        $this->insertAttemptLeg($callId, $cdrId > 0 ? $cdrId : null, $next, $trunkId, $code, $cause, $disp);
    }

    private function insertAttemptLeg(string $callId, ?int $cdrId, int $attempt, ?int $trunkId, int $sipCode, string $reason, string $disp): void
    {
        if ($callId === '') {
            return;
        }
        try {
            $this->db->prepare(
                'INSERT INTO cdr_attempt_legs (sip_call_id, cdr_id, attempt_no, carrier_trunk_id, trunk_name, sip_code, sip_reason, disposition)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?)'
            )->execute([
                mb_substr($callId, 0, 190),
                $cdrId,
                $attempt,
                $trunkId,
                $sipCode > 0 ? $sipCode : null,
                mb_substr($reason, 0, 190),
                mb_substr($disp, 0, 32),
            ]);
        } catch (\Throwable $e) {
            error_log('[ownswitch] fs cdr leg: ' . $e->getMessage());
        }
    }

    /** Map FreeSWITCH sip_hangup_disposition / hangup cause to caller|callee|system. */
    private function mapHangupInitiator(string $disposition, string $cause): string
    {
        $d = strtolower(trim($disposition));
        if ($d !== '') {
            if (str_contains($d, 'recv_bye') || str_contains($d, 'recv_cancel') || str_contains($d, 'recv_refuse')) {
                return 'callee';
            }
            if (str_contains($d, 'send_bye') || str_contains($d, 'send_cancel') || str_contains($d, 'send_refuse')) {
                return 'caller';
            }
        }
        $c = strtoupper($cause);
        if (in_array($c, ['ORIGINATOR_CANCEL', 'CALL_REJECTED', 'USER_BUSY', 'NO_ANSWER', 'NO_USER_RESPONSE'], true)) {
            return 'caller';
        }
        if (in_array($c, ['NORMAL_CLEARING', 'ALLOTTED_TIMEOUT', 'MANAGER_REQUEST'], true)) {
            return 'system';
        }
        return $d !== '' ? $d : 'system';
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $trunk */
    private function bridgeLeg(array $account, array $trunk, string $destDigits, string $userFmt, string $cidNum): string
    {
        $tid = (int) $trunk['id'];
        $dial = $this->applyDstFormat($destDigits, $userFmt);
        $trunkFmt = DstFormat::normalize((string) ($trunk['dst_format'] ?? DstFormat::AS_IS));
        if ($trunkFmt !== DstFormat::AS_IS) {
            $dial = $this->applyDstFormat(Destination::normalize($dial), $trunkFmt);
        }
        $dial = $this->stripTechPrefixes($dial, (string) ($trunk['remove_prefix'] ?? ''));
        $add = (string) ($trunk['add_prefix'] ?? '');
        if ($add !== '') {
            $dial = $add . $dial;
        }

        $fromUser = trim((string) ($trunk['from_user'] ?? ''));
        $fromDomain = trim((string) ($trunk['from_domain'] ?? ''));
        $vars = [];
        $vars[] = 'os_sip_user=' . (string) ($account['username'] ?? '');
        $vars[] = 'os_parent_call_id=${sip_call_id}';
        // Trunk From user wins for SIP From; otherwise use rewritten CLI when known.
        $effectiveCid = $fromUser !== '' ? $fromUser : $cidNum;
        if ($effectiveCid !== '') {
            $vars[] = 'origination_caller_id_number=' . $effectiveCid;
            $vars[] = 'origination_caller_id_name=' . $effectiveCid;
            $vars[] = 'effective_caller_id_number=' . $effectiveCid;
            $mode = strtolower(trim((string) ($account['caller_id_mode'] ?? 'passthrough')));
            // Stamp RPID / PAI with the same CLI when mode or CallerID group requires them.
            $stampHeaders = (int) ($account['callerid_group_id'] ?? 0) > 0
                || in_array($mode, ['rpid', 'pai', 'fixed', 'display_name'], true);
            $hdrHost = $fromDomain !== '' ? $fromDomain : 'anonymous.invalid';
            if ($stampHeaders) {
                $vars[] = 'sip_h_Remote-Party-ID=<sip:' . $effectiveCid . '@' . $hdrHost . '>;party=calling;privacy=off;screen=yes';
                $vars[] = 'sip_h_P-Asserted-Identity=<sip:' . $effectiveCid . '@' . $hdrHost . '>';
            }
        }
        if ($fromUser !== '') {
            $vars[] = 'sip_invite_from_user=' . $fromUser;
        }
        if ($fromDomain !== '') {
            $vars[] = 'sip_invite_from_domain=' . $fromDomain;
        }
        $trunkMax = (int) ($trunk['max_call_duration'] ?? 0);
        if ($trunkMax > 0) {
            $vars[] = 'leg_timeout=' . $trunkMax;
        }
        $prefix = $vars !== [] ? '{' . implode(',', $vars) . '}' : '';
        return $prefix . 'sofia/gateway/gw-' . $tid . '/' . $dial;
    }

    /**
     * Resolve outbound CallerID digits before trunk From-user override.
     * CallerID group longest-prefix on dialed DST overrides mode number source.
     *
     * @param array<string,mixed> $account
     * @param array<string,mixed> $trunk
     * @param array{number:string,name:string,rpid:string,pai:string} $inbound
     */
    private function outboundCallerId(array $account, array $trunk, array $inbound, string $dest = ''): string
    {
        $groupCli = $this->callerIdFromGroup((int) ($account['callerid_group_id'] ?? 0), $dest);
        $mode = strtolower(trim((string) ($account['caller_id_mode'] ?? 'passthrough')));
        $portalCid = $this->digitsOnly(trim((string) ($account['caller_id'] ?? '')));

        if ($groupCli !== '') {
            $cid = $groupCli;
        } else {
            $cid = match ($mode) {
                'fixed' => $portalCid,
                'display_name' => $this->digitsOnly($inbound['name'] !== '' ? $inbound['name'] : $inbound['number']),
                'rpid' => $inbound['rpid'] !== '' ? $inbound['rpid'] : ($inbound['number'] !== '' ? $inbound['number'] : $portalCid),
                'pai' => $inbound['pai'] !== '' ? $inbound['pai'] : ($inbound['number'] !== '' ? $inbound['number'] : $portalCid),
                default => $inbound['number'] !== '' ? $inbound['number'] : $portalCid,
            };
        }

        $rem = trim((string) ($trunk['cid_remove_prefix'] ?? ''));
        if ($rem !== '' && $cid !== '' && str_starts_with($cid, $rem)) {
            $cid = substr($cid, strlen($rem));
        }
        $add = trim((string) ($trunk['cid_add_prefix'] ?? ''));
        if ($add !== '' && $cid !== '') {
            $cid = $add . $cid;
        }

        return $cid;
    }

    /** Longest-prefix match on dialed destination within an assigned CallerID group. */
    private function callerIdFromGroup(int $groupId, string $dest): string
    {
        if ($groupId <= 0 || $dest === '') {
            return '';
        }
        try {
            $st = $this->db->prepare(
                "SELECT prefix, cli_number FROM callerid_group_numbers
                 WHERE callerid_group_id = ? AND status = 'active'
                 ORDER BY CHAR_LENGTH(prefix) DESC, id ASC"
            );
            $st->execute([$groupId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return '';
        }
        $fallback = '';
        foreach ($rows as $row) {
            $pfx = (string) ($row['prefix'] ?? '');
            $cli = $this->digitsOnly(trim((string) ($row['cli_number'] ?? '')));
            if ($cli === '') {
                continue;
            }
            if ($pfx === '*' || $pfx === '') {
                if ($fallback === '') {
                    $fallback = $cli;
                }
                continue;
            }
            $digits = preg_replace('/\D+/', '', $pfx) ?? '';
            if ($digits !== '' && str_starts_with($dest, $digits)) {
                return $cli;
            }
        }
        return $fallback;
    }

    /**
     * CLI shown on the CDR. Softphones often put the dialled caller ID in the From display
     * name ("12138007000" <sip:owner@domain>) while caller_id_number is only the SIP username.
     *
     * @param array<string,string> $vars
     */
    private function presentedCli(array $vars): string
    {
        $candidates = [];
        foreach (['sip_from_display', 'caller_id_name'] as $k) {
            $v = trim((string) ($vars[$k] ?? ''));
            if ($v !== '') {
                $candidates[] = $v;
            }
        }
        $full = trim((string) ($vars['sip_full_from'] ?? ''));
        if (preg_match('/^\s*"([^"]+)"/', $full, $m) === 1) {
            $candidates[] = $m[1];
        }
        foreach (['sip_h_Remote-Party-ID', 'sip_h_P-Asserted-Identity', 'sip_remote_party_id', 'sip_p_asserted_identity'] as $k) {
            $v = trim((string) ($vars[$k] ?? ''));
            if ($v !== '') {
                $candidates[] = $v;
            }
        }
        foreach (['effective_caller_id_number', 'origination_caller_id_number', 'caller_id_number', 'sip_from_user_stripped', 'sip_from_user'] as $k) {
            $v = trim((string) ($vars[$k] ?? ''));
            if ($v !== '') {
                $candidates[] = $v;
            }
        }
        $fallback = '';
        foreach ($candidates as $c) {
            if ($fallback === '') {
                $fallback = $c;
            }
            $digits = $this->digitsOnly($c);
            if (strlen(ltrim($digits, '+')) >= 6) {
                return $digits;
            }
        }
        return $fallback;
    }

    /** @return array{number:string,name:string,rpid:string,pai:string} */
    private function inboundCallerId(Request $r): array
    {
        $number = '';
        foreach ([
            'Caller-Caller-ID-Number',
            'variable_sip_from_user',
            'Caller-Username',
            'variable_effective_caller_id_number',
        ] as $k) {
            $v = $this->digitsOnly(trim((string) $r->input($k, '')));
            if ($v !== '') {
                $number = $v;
                break;
            }
        }
        $name = trim((string) $r->input('Caller-Caller-ID-Name', $r->input('variable_sip_from_display', '')));
        $rpid = $this->digitsFromSipUri((string) $r->input('variable_sip_h_Remote-Party-ID', $r->input('variable_sip_h_remote-party-id', '')));
        if ($rpid === '') {
            $rpid = $this->digitsFromSipUri((string) $r->input('variable_sip_remote_party_id', ''));
        }
        $pai = $this->digitsFromSipUri((string) $r->input('variable_sip_h_P-Asserted-Identity', $r->input('variable_sip_h_p-asserted-identity', '')));
        if ($pai === '') {
            $pai = $this->digitsFromSipUri((string) $r->input('variable_sip_p_asserted_identity', ''));
        }
        return ['number' => $number, 'name' => $name, 'rpid' => $rpid, 'pai' => $pai];
    }

    private function digitsFromSipUri(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/sip:([^@>;\s]+)/i', $raw, $m)) {
            return $this->digitsOnly($m[1]);
        }
        return $this->digitsOnly($raw);
    }

    private function digitsOnly(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $plus = str_starts_with($raw, '+');
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        return $plus && $d !== '' ? '+' . $d : $d;
    }

    /** Comma/space-separated tech prefixes; longest match wins (same as RouteSimulator). */
    private function stripTechPrefixes(string $dial, string $rawRem): string
    {
        $remList = [];
        foreach (preg_split('/[\s,;|]+/', $rawRem) ?: [] as $p) {
            $p = preg_replace('/\D+/', '', $p) ?? '';
            if ($p !== '') {
                $remList[] = $p;
            }
        }
        usort($remList, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($remList as $rem) {
            if ($rem !== '' && str_starts_with($dial, $rem)) {
                return substr($dial, strlen($rem));
            }
        }
        return $dial;
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $trunk */
    private function callerIdLengthReject(array $account, array $trunk, string $cidNum): bool
    {
        // When trunk From user forces CLI, validate that value; else validate rewritten CLI.
        $fromUser = trim((string) ($trunk['from_user'] ?? ''));
        $check = $this->digitsOnly($fromUser !== '' ? $fromUser : $cidNum);
        if ($check === '') {
            // Passthrough with no digits yet — skip length gates (vendor may still reject).
            return false;
        }
        $len = strlen(ltrim($check, '+'));
        foreach ([$account, $trunk] as $src) {
            $min = isset($src['cid_min_len']) && $src['cid_min_len'] !== '' && $src['cid_min_len'] !== null
                ? (int) $src['cid_min_len'] : 0;
            $max = isset($src['cid_max_len']) && $src['cid_max_len'] !== '' && $src['cid_max_len'] !== null
                ? (int) $src['cid_max_len'] : 0;
            if ($min > 0 && $len < $min) {
                return true;
            }
            if ($max > 0 && $len > $max) {
                return true;
            }
        }
        return false;
    }

    private function applyDstFormat(string $digits, string $format): string
    {
        $digits = Destination::normalize($digits);
        return match (DstFormat::normalize($format)) {
            DstFormat::E164_PLUS => $digits === '' ? '' : (str_starts_with($digits, '+') ? $digits : '+' . $digits),
            DstFormat::E164_DIGITS => ltrim($digits, '+'),
            default => $digits,
        };
    }

    private function continueOnFail(SettingsService $settings): string
    {
        $raw = trim((string) ($settings->get('failover_sip_codes', '408,480,486,487,500,503') ?? ''));
        if ($raw === '') {
            $raw = '408,480,486,487,500,503';
        }
        $codes = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $c) {
            $c = trim($c);
            if ($c !== '' && preg_match('/^\d{3}$/', $c)) {
                $codes[] = $c;
            }
        }
        if ($codes === []) {
            $codes = ['408', '480', '486', '487', '500', '503'];
        }
        $extra = [
            'NORMAL_TEMPORARY_FAILURE', 'RECOVERY_ON_TIMER_EXPIRE', 'TIMEOUT',
            'USER_BUSY', 'NO_ANSWER', 'CALL_REJECTED', 'UNALLOCATED_NUMBER',
        ];
        return implode(',', array_merge($codes, $extra));
    }

    private function destinationBlocked(int $customerId, string $dest): bool
    {
        if ($customerId <= 0 || $dest === '') {
            return false;
        }
        try {
            $cands = Destination::candidates($dest);
            if ($cands === []) {
                return false;
            }
            $in = Destination::placeholders($cands);
            $st = $this->db->prepare(
                "SELECT action FROM destination_policies
                 WHERE (customer_id IS NULL OR customer_id = ?) AND prefix IN ({$in})
                 ORDER BY LENGTH(prefix) DESC LIMIT 1"
            );
            $st->execute([$customerId, ...$cands]);
            $action = $st->fetchColumn();
            return is_string($action) && strtolower($action) === 'block';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * SIP user CAP vs customer tariff (sell) rate — independent of trunk buy CAP.
     *
     * @param array<string,mixed> $account
     * @param array<string,mixed> $rate from RateResolver::customer()
     */
    private function userOverCap(array $account, array $rate): bool
    {
        $cap = $account['rate_cap'] ?? null;
        if ($cap === null || $cap === '' || (float) $cap <= 0) {
            return false;
        }
        return (float) ($rate['rate'] ?? 0) > (float) $cap;
    }

    /** @return array<string,mixed>|null */
    private function findAccount(string $user, string $ip): ?array
    {
        if ($user !== '') {
            $st = $this->db->prepare("SELECT * FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1");
            $st->execute([$user]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            // Exact single-IP rows + FIND_IN_SET for multi-IP sip_host lists.
            $st = $this->db->prepare(
                "SELECT * FROM sip_accounts
                 WHERE status = 'active' AND connection_mode = 'ip'
                   AND (sip_host = ? OR FIND_IN_SET(?, sip_host) > 0)
                 LIMIT 1"
            );
            $st->execute([$ip, $ip]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
            // Fallback scan (handles legacy spaces / odd separators).
            try {
                $st = $this->db->query(
                    "SELECT * FROM sip_accounts WHERE status = 'active' AND connection_mode = 'ip'"
                );
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cand) {
                    if (SipHosts::matches((string) ($cand['sip_host'] ?? ''), $ip)) {
                        return $cand;
                    }
                }
            } catch (\Throwable) {
            }
            try {
                $st = $this->db->prepare(
                    "SELECT s.* FROM ip_endpoints e
                     JOIN sip_accounts s ON s.customer_id = e.customer_id AND s.status = 'active'
                     WHERE e.status = 'active' AND e.ip_address = ?
                     ORDER BY (s.connection_mode = 'ip') DESC, s.id ASC
                     LIMIT 1"
                );
                $st->execute([$ip]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /** @param array<string,mixed> $account */
    private function prepaidGate(array $account, string $dest, string $callId): int|false
    {
        $cid = (int) ($account['customer_id'] ?? 0);
        if ($cid <= 0) {
            return 0;
        }
        $st = $this->db->prepare(
            'SELECT id, billing_mode, credit_limit, currency, reseller_id, status FROM customers WHERE id = ? LIMIT 1'
        );
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || strtolower((string) $c['status']) !== 'active') {
            return false;
        }
        if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
            return 0;
        }
        $rate = (new RateResolver($this->db, new SettingsService($this->db)))->customer($cid, $dest);
        if ($rate === null) {
            return false;
        }
        try {
            $auth = (new PrepaidService($this->db, new LedgerService($this->db), new RatingService()))->authorize(
                [
                    'id' => $cid,
                    'billing_mode' => (string) $c['billing_mode'],
                    'credit_limit' => (string) ($c['credit_limit'] ?? '0'),
                    'currency' => (string) ($c['currency'] ?? 'USD'),
                    'reseller_id' => $c['reseller_id'] !== null ? (int) $c['reseller_id'] : null,
                ],
                $callId,
                (string) $rate['rate'],
                (int) $rate['initial'],
                (int) $rate['subsequent'],
                (string) $rate['connect_fee']
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] fs prepaid: ' . $e->getMessage());
            return $this->allowsCalls($account) ? 0 : false;
        }
        if (empty($auth['authorized'])) {
            return false;
        }
        return (int) ($auth['max_seconds'] ?? 0);
    }

    private function sipUserFromRequest(Request $r): string
    {
        foreach (['variable_os_sip_user', 'Caller-Username', 'variable_sip_auth_username', 'Hunt-Username', 'user'] as $k) {
            $v = trim((string) $r->input($k, ''));
            if ($v !== '' && !str_contains($v, '@')) {
                return $v;
            }
            if (str_contains($v, '@')) {
                return explode('@', $v, 2)[0];
            }
        }
        return '';
    }

    /** @param array<string,mixed> $account */
    private function allowsCalls(array $account): bool
    {
        if (strtolower((string) ($account['status'] ?? '')) !== 'active') {
            return false;
        }
        if (isset($account['allow_calls']) && (string) $account['allow_calls'] === '0') {
            return false;
        }
        $cid = (int) ($account['customer_id'] ?? 0);
        if ($cid <= 0) {
            return true;
        }
        $st = $this->db->prepare('SELECT billing_mode, status FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || strtolower((string) $c['status']) !== 'active') {
            return false;
        }
        if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
            return true;
        }
        $b = $this->db->prepare('SELECT balance, reserved FROM balances WHERE customer_id = ? LIMIT 1');
        $b->execute([$cid]);
        $row = $b->fetch(PDO::FETCH_ASSOC) ?: ['balance' => '0', 'reserved' => '0'];
        return ((float) $row['balance'] - (float) $row['reserved']) > 0.000001;
    }

    private function dncHit(int $groupId, string $digits): bool
    {
        $alts = [$digits];
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $alts[] = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $alts[] = '1' . $digits;
        }
        $in = implode(',', array_fill(0, count($alts), '?'));
        $st = $this->db->prepare("SELECT 1 FROM dnc_numbers WHERE dnc_group_id = ? AND number_digits IN ($in) LIMIT 1");
        $st->execute(array_merge([$groupId], $alts));
        return (bool) $st->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function trunksFor(int $planId, string $dest): array
    {
        if ($planId <= 0) {
            return [];
        }
        $strategy = 'priority';
        try {
            $ps = $this->db->prepare("SELECT strategy FROM routing_plans WHERE id = ? AND status = 'active' LIMIT 1");
            $ps->execute([$planId]);
            $s = $ps->fetchColumn();
            if (is_string($s) && $s !== '') {
                $strategy = $s;
            }
        } catch (\Throwable) {
        }
        $st = $this->db->prepare(
            "SELECT t.*, r.id AS rule_id, r.prefix AS rule_prefix, r.priority
             FROM routing_rules r
             JOIN carrier_trunks t ON t.id = r.carrier_trunk_id
             JOIN carriers ca ON ca.id = t.carrier_id AND ca.status = 'active'
             WHERE r.routing_plan_id = ? AND r.status = 'active' AND t.status = 'active'
               AND (r.prefix = '*' OR ? LIKE CONCAT(r.prefix, '%'))
             ORDER BY CHAR_LENGTH(r.prefix) DESC, r.priority ASC"
        );
        $st->execute([$planId, $dest]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $groups = [];
        foreach ($rows as $row) {
            $pfx = (string) ($row['rule_prefix'] ?? $row['prefix'] ?? '*');
            $len = $pfx === '*' ? 0 : strlen($pfx);
            $groups[$len][] = $row;
        }
        krsort($groups);
        foreach ($groups as $list) {
            $out = [];
            foreach ($list as $row) {
                if ($this->overCap($row, $dest) || $this->trunkDnc($row, $dest)) {
                    continue;
                }
                $out[] = $row;
            }
            if ($out === []) {
                continue;
            }
            // Enrich for LCR / ASR ordering (same helpers as RouteSimulator).
            $rules = [];
            $resolver = new RateResolver($this->db, new SettingsService($this->db));
            $rating = new RatingService();
            foreach ($out as $row) {
                $tid = (int) ($row['id'] ?? 0);
                $buy = $tid > 0 ? $resolver->carrier($tid, $dest) : null;
                $sample = $buy
                    ? $rating->rate($buy['rate'], $buy['initial'], $buy['subsequent'], 60, $buy['connect_fee'])['total']
                    : null;
                $rules[] = [
                    'rule_id' => (int) ($row['rule_id'] ?? 0),
                    'priority' => (int) ($row['priority'] ?? 100),
                    'trunk_id' => $tid,
                    'carrier_sample_cost' => $sample,
                    'hour_asr' => -1.0,
                    'hour_attempts' => 0,
                    '_trunk' => $row,
                ];
            }
            if ($strategy === 'asr') {
                $stats = (new TrunkHourStatsService($this->db))->lastHour(array_map(static fn ($r) => (int) $r['trunk_id'], $rules));
                foreach ($rules as &$rr) {
                    $sid = (string) (int) $rr['trunk_id'];
                    $rr['hour_asr'] = $stats[$sid]['asr'] ?? -1.0;
                    $rr['hour_attempts'] = $stats[$sid]['attempts'] ?? 0;
                }
                unset($rr);
            }
            $ordered = RouteSimulator::order($rules, $strategy);
            $final = [];
            foreach ($ordered as $rr) {
                $final[] = $rr['_trunk'];
            }
            return $final;
        }
        return [];
    }

    /**
     * Trunk CAP vs provider buy rate for this trunk — independent of SIP user tariff CAP.
     * Skips this trunk when buy > CAP (failover may try the next trunk).
     *
     * @param array<string,mixed> $trunk
     */
    private function overCap(array $trunk, string $dest): bool
    {
        $cap = $trunk['rate_cap'] ?? null;
        if ($cap === null || $cap === '' || (float) $cap <= 0) {
            return false;
        }
        $tid = (int) ($trunk['id'] ?? 0);
        if ($tid <= 0) {
            return false;
        }
        $buy = (new RateResolver($this->db, new SettingsService($this->db)))->carrier($tid, $dest);
        if ($buy === null) {
            // CAP is set but no buy rate for this destination → do not send on this trunk.
            return true;
        }
        return (float) $buy['rate'] > (float) $cap;
    }

    /** @param array<string,mixed> $trunk */
    private function trunkDnc(array $trunk, string $dest): bool
    {
        $gid = (int) ($trunk['dnc_group_id'] ?? 0);
        return $gid > 0 && $this->dncHit($gid, $dest);
    }

    private function didOwner(string $digits): ?string
    {
        $st = $this->db->prepare(
            "SELECT s.username FROM dids d
             JOIN did_assignments a ON a.did_id = d.id AND a.status = 'active'
             LEFT JOIN sip_accounts s ON s.id = a.sip_account_id
             WHERE d.number_norm = ? OR d.number_e164 = ? LIMIT 1"
        );
        try {
            $st->execute([$digits, '+' . $digits]);
        } catch (\Throwable) {
            return null;
        }
        $u = $st->fetchColumn();
        return is_string($u) && $u !== '' ? $u : null;
    }

    private function bridgeUser(string $username): string
    {
        return $this->extension(
            'inbound-did',
            '.*',
            '<action application="set" data="hangup_after_bridge=true"/>'
            . '<action application="bridge" data="user/' . $this->x($username) . '"/>'
        );
    }

    private function reject(string $code, string $cause): string
    {
        return $this->extension(
            'reject',
            '.*',
            '<action application="respond" data="' . $code . '"/>'
            . '<action application="hangup" data="' . $cause . '"/>'
        );
    }

    private function extension(string $name, string $expr, string $actions): string
    {
        // mod_xml_curl's switch_xml_parse_str rejects a one-line dialplan ("Error Parsing
        // Result" → "Context ownswitch not found" → phone hears not found). Same fix as
        // the directory document: short lines, standalone="no".
        $body = preg_replace('/>\s*</', ">\n          <", $actions) ?? $actions;

        return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n"
            . "<document type=\"freeswitch/xml\">\n"
            . "  <section name=\"dialplan\">\n"
            . "    <context name=\"ownswitch\">\n"
            . "      <extension name=\"" . $this->x($name) . "\">\n"
            . "        <condition field=\"destination_number\" expression=\"" . $this->x($expr) . "\">\n"
            . "          " . $body . "\n"
            . "        </condition>\n"
            . "      </extension>\n"
            . "    </context>\n"
            . "  </section>\n"
            . "</document>\n";
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
