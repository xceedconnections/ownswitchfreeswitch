<?php

declare(strict_types=1);

namespace Ownswitch\Services\Cdr;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use PDO;

/**
 * Pull OpenSIPS acc / missed_calls into the portal CDR ingest queue so failed
 * and completed calls appear under CDRs / Failed CDRs.
 */
final class OpensipsAccImporter
{
    public function __construct(
        private PDO $portalDb,
        private OpensipsProvisioner $opensips,
    ) {
    }

    /** @return int number of rows queued */
    public function import(int $limit = 200): int
    {
        if (!$this->opensips->enabled()) {
            return 0;
        }
        $os = $this->opensips->pdo();
        if ($os === null) {
            return 0;
        }
        $this->ensureStateTable();
        $queued = 0;
        $queued += $this->importTable($os, 'acc', $limit);
        $queued += $this->importTable($os, 'missed_calls', max(50, intdiv($limit, 2)));
        return $queued;
    }

    private function ensureStateTable(): void
    {
        $this->portalDb->exec(
            'CREATE TABLE IF NOT EXISTS opensips_acc_import_state (
                source_table VARCHAR(64) NOT NULL PRIMARY KEY,
                last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function lastId(string $table): int
    {
        $st = $this->portalDb->prepare('SELECT last_id FROM opensips_acc_import_state WHERE source_table = ?');
        $st->execute([$table]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    private function setLastId(string $table, int $id): void
    {
        $st = $this->portalDb->prepare(
            'INSERT INTO opensips_acc_import_state (source_table, last_id) VALUES (?,?)
             ON DUPLICATE KEY UPDATE last_id = VALUES(last_id)'
        );
        $st->execute([$table, $id]);
    }

    private function importTable(PDO $os, string $table, int $limit): int
    {
        try {
            $cols = $os->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($cols) || !in_array('id', $cols, true) || !in_array('callid', $cols, true)) {
            return 0;
        }
        $last = $this->lastId($table);
        $sql = 'SELECT * FROM `' . str_replace('`', '', $table) . '` WHERE id > ? ORDER BY id ASC LIMIT ' . (int) $limit;
        try {
            $st = $os->prepare($sql);
            $st->execute([$last]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return 0;
        }
        if ($rows === []) {
            return 0;
        }

        $ins = $this->portalDb->prepare(
            'INSERT INTO cdr_ingest_queue (payload, source, status, attempts, created_at)
             VALUES (?, ?, \'pending\', 0, UTC_TIMESTAMP())'
        );
        $maxId = $last;
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $payload = $this->mapRow($row, $table);
            if ($payload === null) {
                // Do not advance last_id past unmapped rows — retry next cycle
                error_log('[ownswitch] opensips acc skip id=' . $id . ' table=' . $table);
                break;
            }
            // Disabled SIP / Payment Required: consume ACC row but do not create portal CDR / reports
            $codeSkip = (int) ($payload['sip_response'] ?? 0);
            if ($codeSkip === 402 || $this->isDisabledSipPayload($payload)) {
                if ($id > $maxId) {
                    $maxId = $id;
                }
                continue;
            }
            try {
                $ins->execute([json_encode($payload, JSON_THROW_ON_ERROR), 'opensips_' . $table]);
                $this->recordAttemptLeg($payload);
                $n++;
                if ($id > $maxId) {
                    $maxId = $id;
                }
            } catch (\Throwable $e) {
                error_log('[ownswitch] opensips acc queue: ' . $e->getMessage());
                break;
            }
        }
        if ($maxId > $last) {
            $this->setLastId($table, $maxId);
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function mapRow(array $row, string $table): ?array
    {
        $callid = trim((string) ($row['callid'] ?? ''));
        if ($callid === '') {
            return null;
        }
        $method = strtoupper((string) ($row['method'] ?? 'INVITE'));
        if ($method !== '' && $method !== 'INVITE') {
            return null;
        }
        $code = (int) ($row['sip_code'] ?? $row['sip_status'] ?? 0);
        $reason = trim((string) ($row['sip_reason'] ?? ''));

        $sipUser = self::cleanToken((string) ($row['src_user'] ?? ''));
        if ($sipUser === '') {
            $sipUser = self::cleanToken($this->userPart((string) ($row['from_uri'] ?? $row['caller_id'] ?? $row['from_user'] ?? '')));
        }

        // Prefer ACC.cli (presented CallerID). Fall back carefully — never treat SIP username as CLI.
        $cliRaw = self::cleanToken((string) ($row['cli'] ?? ''));
        $cli = self::cleanCallerId($cliRaw);
        // Custom hybrid ACC sometimes swaps fields: src_user=phone CLI, cli=SIP username
        if (
            $sipUser !== '' && $cli !== ''
            && self::looksLikePhone($sipUser) && self::looksLikeSipUser($cli)
        ) {
            $tmp = $sipUser;
            $sipUser = $cli;
            $cli = self::cleanCallerId($tmp);
        }
        // ACC bug (pre-os_cli): cli was still SIP username after display_name From rewrite
        if ($cli === '' || ($sipUser !== '' && strcasecmp($cli, $sipUser) === 0) || self::looksLikeSipUser($cli)) {
            $fromUri = (string) ($row['from_uri'] ?? $row['caller_id'] ?? '');
            // Eyebeam-style: "12138007000" <sip:owner@host> stored in some ACC builds
            if (preg_match('/"([+0-9]{8,30})"/', $fromUri, $m)) {
                $cli = self::cleanCallerId($m[1]);
            }
            if ($cli === '' || ($sipUser !== '' && strcasecmp($cli, $sipUser) === 0) || self::looksLikeSipUser($cli)) {
                $fromCli = self::cleanCallerId($this->userPart($fromUri));
                if ($fromCli !== '' && self::looksLikePhone($fromCli) && ($sipUser === '' || strcasecmp($fromCli, $sipUser) !== 0)) {
                    $cli = $fromCli;
                }
            }
        }
        if ($cli === '' || ($sipUser !== '' && strcasecmp($cli, $sipUser) === 0)) {
            // Older ACC rows stored presented CLI in src_user — keep digits for src display
            $maybeCli = self::cleanCallerId($sipUser);
            if ($maybeCli !== '' && self::looksLikePhone($maybeCli) && !self::looksLikeSipUser($sipUser)) {
                $cli = $maybeCli;
            }
        }

        $dst = self::cleanCallerId((string) ($row['dst_user'] ?? ''));
        if ($dst === '') {
            $dst = self::cleanCallerId($this->userPart((string) ($row['to_uri'] ?? $row['callee_id'] ?? $row['to_user'] ?? $row['ruri'] ?? '')));
        }

        $sourceIp = self::cleanToken((string) ($row['src_ip'] ?? $row['ip'] ?? ''));

        // Attribution username = SIP account; src = presented CallerID (or SIP user fallback)
        $username = null;
        if ($sipUser !== '' && $cli !== '' && $sipUser !== $cli) {
            $username = $sipUser; // ACC extras: src=SIP user, cli=CallerID
        } elseif ($sipUser !== '' && !self::looksLikePhone($sipUser)) {
            $username = $sipUser;
        } elseif ($sipUser !== '' && $this->sipUsernameExists($sipUser)) {
            $username = $sipUser; // numeric SIP usernames are valid
        }
        // ViciDial / IP ACL: From=CallerID and src_user empty or phone → resolve via REGISTER contact IP
        if ($username === null || (self::looksLikePhone((string) $username) && !$this->sipUsernameExists((string) $username))) {
            $byIp = $sourceIp !== '' ? $this->opensips->usernameBySourceIp($sourceIp) : null;
            if ($byIp !== null && $byIp !== '') {
                $username = $byIp;
            }
        }
        if ($username === null) {
            $username = $this->soleActiveSipUsername();
        }

        $src = $cli !== '' ? $cli : ($sipUser !== '' ? self::cleanCallerId($sipUser) : '');
        if ($src === '') {
            $src = $sipUser !== '' ? $sipUser : 'unknown';
        }
        // If username resolved and src was wrongly the SIP user, prefer CLI digits when present
        if ($username !== null && $src === $username && $cli !== '') {
            $src = $cli;
        }
        if ($dst === '' || !preg_match('/\d/', $dst)) {
            $dst = '0000000000';
        }

        $trunkId = self::trunkIdFromGw((string) ($row['gw'] ?? ''));
        if ($trunkId === null) {
            $trunkId = $this->soleActiveTrunkId();
        }

        $setup = $row['time'] ?? $row['created'] ?? $row['setuptime'] ?? gmdate('Y-m-d H:i:s');
        $duration = (int) ($row['duration'] ?? $row['ms_duration'] ?? 0);
        if ($duration > 100000) {
            $duration = (int) round($duration / 1000); // ms → s
        }
        $duration = max(0, min(172800, $duration));

        $disposition = 'FAILED';
        if ($table === 'missed_calls') {
            $disposition = $code === 486 ? 'BUSY' : ($code === 487 ? 'CANCELLED' : 'NO_ANSWER');
            if ($code >= 500 || $code === 404 || $code === 403 || $code === 503) {
                $disposition = 'FAILED';
            }
        } elseif ($code === 200 && $duration > 0) {
            $disposition = 'ANSWERED';
        } elseif ($code === 200 && $duration === 0 && $table === 'acc') {
            // Some ACC rows land with duration 0 briefly; still mark answered if sip 200
            $disposition = 'ANSWERED';
        } elseif ($code === 486) {
            $disposition = 'BUSY';
        } elseif ($code === 487) {
            $disposition = 'CANCELLED';
        } elseif ($code === 408 || $code === 480) {
            $disposition = 'NO_ANSWER';
        }

        $mediaPolicy = $this->mediaPolicyForUser($username);
        $mediaIp = self::cleanToken((string) ($row['media_ip'] ?? ''));
        if ($mediaIp === '' && $mediaPolicy === 'direct' && $sourceIp !== '') {
            $mediaIp = $sourceIp;
        }
        if ($mediaIp === '' && in_array($mediaPolicy, ['relay', 'record'], true)) {
            $pub = trim((string) (\Ownswitch\App::config('app.integrations.opensips.public_ip', '') ?: ''));
            if ($pub !== '') {
                $mediaIp = $pub;
            }
        }

        $payload = [
            'external_cdr_key' => 'osacc:' . $table . ':' . ($row['id'] ?? $callid),
            'sip_call_id' => mb_substr($callid, 0, 190),
            'src' => $src,
            'dst' => $dst,
            'username' => $username,
            'source_ip' => $sourceIp !== '' ? $sourceIp : null,
            'media_ip' => $mediaIp !== '' ? $mediaIp : null,
            'media_policy_requested' => $mediaPolicy,
            'media_policy_actual' => $mediaPolicy,
            'setup_at' => is_string($setup) ? $setup : gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s'),
            'duration' => $disposition === 'ANSWERED' ? max(0, $duration) : 0,
            'disposition' => $disposition,
            'sip_response' => $code > 0 ? $code : null,
            'failure_reason' => $reason !== '' ? mb_substr($reason, 0, 190) : ($code > 0 ? ('SIP ' . $code) : 'opensips_acc'),
        ];
        if ($trunkId !== null) {
            $payload['carrier_trunk_id'] = $trunkId;
        }
        return $payload;
    }

    private function mediaPolicyForUser(?string $username): string
    {
        $u = trim((string) $username);
        if ($u === '') {
            return 'relay';
        }
        try {
            $st = $this->portalDb->prepare('SELECT media_policy FROM sip_accounts WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            $mp = strtolower(trim((string) ($st->fetchColumn() ?: '')));
            if (in_array($mp, ['direct', 'relay', 'record'], true)) {
                return $mp;
            }
        } catch (\Throwable) {
        }
        return 'relay';
    }

    /** @param array<string,mixed> $payload */
    private function recordAttemptLeg(array $payload): void
    {
        $callid = (string) ($payload['sip_call_id'] ?? '');
        if ($callid === '') {
            return;
        }
        try {
            $this->portalDb->exec(
                'CREATE TABLE IF NOT EXISTS cdr_attempt_legs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    sip_call_id VARCHAR(191) NOT NULL,
                    cdr_id BIGINT UNSIGNED NULL,
                    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                    carrier_trunk_id BIGINT UNSIGNED NULL,
                    trunk_name VARCHAR(120) NULL,
                    sip_code SMALLINT UNSIGNED NULL,
                    sip_reason VARCHAR(190) NULL,
                    disposition VARCHAR(32) NULL,
                    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                    KEY ix_leg_callid (sip_call_id),
                    KEY ix_leg_cdr (cdr_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $trunkId = isset($payload['carrier_trunk_id']) ? (int) $payload['carrier_trunk_id'] : null;
            $trunkName = null;
            if ($trunkId !== null) {
                $st = $this->portalDb->prepare('SELECT name FROM carrier_trunks WHERE id = ?');
                $st->execute([$trunkId]);
                $trunkName = ($v = $st->fetchColumn()) !== false ? (string) $v : null;
            }
            $cnt = $this->portalDb->prepare('SELECT COUNT(*) FROM cdr_attempt_legs WHERE sip_call_id = ?');
            $cnt->execute([$callid]);
            $attemptNo = (int) $cnt->fetchColumn() + 1;
            $this->portalDb->prepare(
                'INSERT INTO cdr_attempt_legs (sip_call_id, attempt_no, carrier_trunk_id, trunk_name, sip_code, sip_reason, disposition)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                mb_substr($callid, 0, 190),
                $attemptNo,
                $trunkId,
                $trunkName,
                isset($payload['sip_response']) ? (int) $payload['sip_response'] : null,
                isset($payload['failure_reason']) ? mb_substr((string) $payload['failure_reason'], 0, 190) : null,
                (string) ($payload['disposition'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('[ownswitch] attempt leg: ' . $e->getMessage());
        }
    }

    private function sipUsernameExists(string $username): bool
    {
        try {
            $st = $this->portalDb->prepare('SELECT 1 FROM sip_accounts WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload */
    private function isDisabledSipPayload(array $payload): bool
    {
        $u = trim((string) ($payload['sip_username'] ?? $payload['username'] ?? ''));
        if ($u === '') {
            $u = self::userPart((string) ($payload['src'] ?? ''));
        }
        if ($u === '') {
            return false;
        }
        try {
            $st = $this->portalDb->prepare("SELECT status FROM sip_accounts WHERE username = ? LIMIT 1");
            $st->execute([$u]);
            $status = $st->fetchColumn();
            return $status !== false && strtolower((string) $status) !== 'active';
        } catch (\Throwable) {
            return false;
        }
    }

    private function soleActiveSipUsername(): ?string
    {
        try {
            $st = $this->portalDb->query("SELECT username FROM sip_accounts WHERE status = 'active' LIMIT 2");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            return count($rows) === 1 ? (string) $rows[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function soleActiveTrunkId(): ?int
    {
        try {
            $st = $this->portalDb->query("SELECT id FROM carrier_trunks WHERE status = 'active' LIMIT 2");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            return count($rows) === 1 ? (int) $rows[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function userPart(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        if (preg_match('#sip:([^@;>]+)@#i', $uri, $m)) {
            return $m[1];
        }
        if (preg_match('#^([^@;:\s]+)#', $uri, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Strip Eyebeam-style quotes / whitespace from a SIP user or token. */
    public static function cleanToken(string $v): string
    {
        $v = trim($v);
        if ($v === '' || strcasecmp($v, 'null') === 0) {
            return '';
        }
        return trim($v, " \t\"'");
    }

    /** Digits/+ only for presented CallerID / destination. */
    public static function cleanCallerId(string $v): string
    {
        $v = self::cleanToken($v);
        if ($v === '') {
            return '';
        }
        $digits = preg_replace('/[^0-9+]/', '', $v) ?? '';
        return $digits;
    }

    public static function looksLikePhone(string $v): bool
    {
        return (bool) preg_match('/^[+]?[0-9]{8,30}$/', $v);
    }

    public static function looksLikeSipUser(string $v): bool
    {
        $v = self::cleanToken($v);
        return $v !== '' && !self::looksLikePhone($v);
    }

    /** Parse drouting gwid T{id} → trunk id. */
    public static function trunkIdFromGw(string $gw): ?int
    {
        $gw = self::cleanToken($gw);
        if ($gw === '') {
            return null;
        }
        if (preg_match('/^T(\d+)$/i', $gw, $m)) {
            return (int) $m[1];
        }
        if (ctype_digit($gw)) {
            return (int) $gw;
        }
        return null;
    }
}
