<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use Ownswitch\App;
use PDO;

/** Key/value settings with config/app.php defaults. */
final class SettingsService
{
    /** key => [label, type, help]. Only these keys are editable. */
    public const SCHEMA = [
        'default_currency'            => ['Default currency', 'text', '3-letter ISO code'],
        'tax_rate_percent'            => ['Tax rate (%)', 'decimal', 'Applied on new invoices (0 = no tax)'],
        'charge_connect_on_zero'      => ['Charge connection fee on zero-duration calls', 'bool', 'Default off'],
        'rating_tolerance'            => ['Rating discrepancy tolerance', 'decimal', 'Flag when |CGRateS − portal| exceeds this'],
        'prepaid_hard_cap_seconds'    => [
            'Prepaid hard max call duration (s)',
            'int',
            'System default max call length in seconds (e.g. 7200 = 2 hours). Used when a SIP user or trunk leaves Max call duration at 0 / Default. Applied by FreeSWITCH dialplan (sched_hangup).',
        ],
        'system_max_concurrent'       => ['System max concurrent calls', 'int0', '0 = unlimited. Soft cap for capacity planning and health alerts.'],
        'system_max_cps'              => ['System max calls per second', 'int0', '0 = unlimited. Soft CPS guidance for the switch.'],
        'failover_max_retries'        => ['Failover max trunk attempts', 'int', 'How many trunks FreeSWITCH will try before giving up (1–20)'],
        'failover_sip_codes'          => ['Failover SIP codes', 'text', 'Comma-separated SIP codes that trigger next trunk (e.g. 408,480,486,503)'],
        'kill_switch'                 => ['Emergency kill switch', 'bool', 'When on, FreeSWITCH dialplan rejects new outbound calls'],
        'default_media_policy'        => [
            'Default media policy',
            'media',
            'FreeSWITCH: Relay = RTP through this switch (bypass_media=false). Direct = peer-to-peer RTP (bypass_media=true). Record = Relay + record_session to recordings/. Used when a SIP user leaves Media at Default.',
        ],
        'default_codecs'              => [
            'Default codecs',
            'codecs',
            'Pre-selected when creating a new SIP user or trunk (order = preference). FreeSWITCH absolute_codec_string. Softphones/vendors still negotiate within this list.',
        ],
        'recording_retention_days'    => ['Recording retention (days)', 'int', 'Days to keep FreeSWITCH record_session WAVs under recordings/ (cleanup job).'],
        'switch_logo'                 => ['Switch logo', 'logo', 'PNG/JPG/WEBP/SVG, max 2 MB. Shown in the top navigation for all users.'],
        'switch_name'                 => ['Switch display name', 'text', 'Shown in the portal footer (not the header). Leave blank for “Ownswitch”.'],
        'system_timezone'             => [
            'System timezone',
            'timezone',
            'Default for the dashboard, stats, and reports. A user who picks a timezone on My profile overrides this for their own login. Example: New York, London, or Karachi.',
        ],
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = $this->pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Throwable) {
                $this->cache = [];
            }
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }
        $cfgKey = match ($key) {
            'default_currency'            => 'currency',
            'default_customer_initial'    => 'customer_initial',
            'default_customer_subsequent' => 'customer_subsequent',
            'default_carrier_initial'     => 'carrier_initial',
            'default_carrier_subsequent'  => 'carrier_subsequent',
            'default_media_policy'        => 'media_policy',
            'default_codecs'              => 'codecs',
            default                       => $key,
        };
        $v = App::config('app.defaults.' . $cfgKey);
        return $v === null ? $default : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
    }

    public function int(string $key, int $default): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value, ?int $userId): void
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)')
            ->execute([$key, $value, $userId]);
        $this->cache = null;
    }

    /**
     * Validate a submitted value for a schema key.
     *
     * @return array{0:?string,1:?string} [normalised value, error]
     */
    public static function validate(string $key, mixed $raw): array
    {
        [$label, $type] = self::SCHEMA[$key];
        $v = is_string($raw) ? trim($raw) : '';
        switch ($type) {
            case 'bool':
                return [!empty($raw) ? '1' : '0', null];
            case 'int':
                if ($key === 'failover_max_retries') {
                    return preg_match('/^\d{1,2}$/', $v) && (int) $v >= 1 && (int) $v <= 20
                        ? [(string) (int) $v, null]
                        : [null, "{$label} must be between 1 and 20."];
                }
                if ($key === 'prepaid_hard_cap_seconds') {
                    return preg_match('/^\d{1,7}$/', $v) && (int) $v >= 60 && (int) $v <= 604800
                        ? [(string) (int) $v, null]
                        : [null, "{$label} must be between 60 and 604800 seconds (1 min – 7 days)."];
                }
                return preg_match('/^\d{1,7}$/', $v) && (int) $v >= 1 ? [(string) (int) $v, null] : [null, "{$label} must be a positive whole number."];
            case 'int0':
                return preg_match('/^\d{1,7}$/', $v) ? [(string) (int) $v, null] : [null, "{$label} must be a whole number (0 allowed)."];
            case 'decimal':
                return preg_match('/^\d+(\.\d{1,8})?$/', $v) ? [$v, null] : [null, "{$label} must be a non-negative decimal."];
            case 'media':
                return in_array($v, ['direct', 'relay', 'record'], true) ? [$v, null] : [null, "{$label} must be direct, relay or record."];
            case 'codecs':
                $stored = \Ownswitch\Support\SipCodecs::toStorage($raw);
                if ($stored === null) {
                    return [null, "{$label}: select at least one codec (e.g. PCMU, PCMA, G729)."];
                }
                return [$stored, null];
            case 'timezone':
                try {
                    new \DateTimeZone($v);
                    return [$v, null];
                } catch (\Throwable) {
                    return [null, "{$label} must be a timezone such as New York, London, or Karachi."];
                }
            case 'logo':
                // Path set by controller after upload; empty keeps existing.
                return [$v, null];
            case 'text':
                if ($key === 'default_currency') {
                    return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
                }
                if ($key === 'failover_sip_codes') {
                    $norm = preg_replace('/\s+/', '', strtoupper($v)) ?? '';
                    if ($norm === '' || !preg_match('/^\d{3}(,\d{3})*$/', $norm)) {
                        return [null, "{$label} must be comma-separated 3-digit SIP codes."];
                    }
                    return [$norm, null];
                }
                if (mb_strlen($v) > 80) {
                    return [null, "{$label} must be at most 80 characters."];
                }
                return [$v, null];
            default:
                return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
        }
    }
}
