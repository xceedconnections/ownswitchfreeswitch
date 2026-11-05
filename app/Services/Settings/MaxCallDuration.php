<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use PDO;

/** Resolve effective max call duration (seconds): SIP override → trunk override → system default. */
final class MaxCallDuration
{
    public const SYSTEM_DEFAULT = 7200;

    public function __construct(private PDO $pdo, private ?SettingsService $settings = null)
    {
        $this->settings ??= new SettingsService($pdo);
    }

    public function systemDefault(): int
    {
        $v = $this->settings->int('prepaid_hard_cap_seconds', self::SYSTEM_DEFAULT);
        return $v >= 60 ? $v : self::SYSTEM_DEFAULT;
    }

    /**
     * @param array<string,mixed>|null $sipAccount
     * @param array<string,mixed>|null $trunk
     */
    public function resolve(?array $sipAccount = null, ?array $trunk = null): int
    {
        $sip = isset($sipAccount['max_call_duration']) ? (int) $sipAccount['max_call_duration'] : 0;
        if ($sip > 0) {
            return min(86400 * 7, max(60, $sip));
        }
        $tr = isset($trunk['max_call_duration']) ? (int) $trunk['max_call_duration'] : 0;
        if ($tr > 0) {
            return min(86400 * 7, max(60, $tr));
        }
        return $this->systemDefault();
    }

    public function forSipUsername(?string $username, ?int $trunkId = null): int
    {
        $sip = null;
        $u = trim((string) $username);
        if ($u !== '') {
            $st = $this->pdo->prepare('SELECT max_call_duration FROM sip_accounts WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $sip = $row;
            }
        }
        $trunk = null;
        if ($trunkId !== null && $trunkId > 0) {
            $st = $this->pdo->prepare('SELECT max_call_duration FROM carrier_trunks WHERE id = ? LIMIT 1');
            $st->execute([$trunkId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $trunk = $row;
            }
        }
        return $this->resolve($sip, $trunk);
    }
}
