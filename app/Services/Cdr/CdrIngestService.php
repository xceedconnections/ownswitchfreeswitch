<?php

declare(strict_types=1);

namespace Ownswitch\Services\Cdr;

use Ownswitch\Repositories\CdrRepository;
use Ownswitch\Repositories\IpEndpointRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Settings\SettingsService;
use Ownswitch\Support\Destination;
use PDO;

/**
 * Ingest one CDR: attribute -> rate (customer + carrier via RatingService) -> persist -> settle to ledger.
 * Idempotent on external_cdr_key, so retries and failover duplicates never double charge.
 * Only ANSWERED calls with duration > 0 produce charges; failed failover attempts are stored with 0 charge.
 */
final class CdrIngestService
{
    private const DISPOSITIONS = ['ANSWERED', 'NO_ANSWER', 'BUSY', 'FAILED', 'CANCELLED'];

    public function __construct(
        private PDO $pdo,
        private RatingService $rating,
        private RateResolver $resolver,
        private LedgerService $ledger,
        private PrepaidService $prepaid,
        private SettingsService $settings,
        private CdrRepository $cdrs,
    ) {
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{status:string,id?:int,errors?:list<string>,settlement?:string,discrepancy?:bool}
     */
    public function ingest(array $raw, string $sourceSystem = 'api'): array
    {
        [$d, $errors] = $this->validate($raw);
        if ($errors) {
            return ['status' => 'invalid', 'errors' => $errors];
        }
        $key = $sourceSystem . ':' . $d['external_key'];
        $existing = $this->cdrs->findIdByKey($key);
        if ($existing !== null) {
            return ['status' => 'duplicate', 'id' => $existing];
        }

        // --- attribution (never trust a client-supplied customer_id unless the source is internal) ---
        $att = $this->attribute($d, $sourceSystem);
        $customer = $att['customer'];
        $dst = $d['dst_normalized'];

        $answered = $d['disposition'] === 'ANSWERED' && $d['duration'] > 0;
        $duration = $answered ? $d['duration'] : 0;

        $row = [
            'uuid' => self::uuid(), 'source_system' => $sourceSystem, 'external_cdr_key' => $key, 'sip_call_id' => $d['sip_call_id'],
            'reseller_id' => $customer['reseller_id'] ?? null, 'customer_id' => $customer['id'] ?? null,
            'sip_account_id' => $att['sip_account_id'], 'ip_endpoint_id' => $att['ip_endpoint_id'],
            'src' => $d['src'], 'dst_raw' => $d['dst_raw'], 'dst_normalized' => $dst, 'dst_prefix' => null,
            'carrier_trunk_id' => $d['carrier_trunk_id'], 'carrier_id' => null, 'routing_rule_id' => $d['routing_rule_id'],
            'setup_at' => $d['setup_at'], 'answer_at' => $d['answer_at'], 'end_at' => $d['end_at'],
            'duration_actual' => $duration, 'duration_billable_customer' => 0, 'duration_billable_carrier' => 0,
            'customer_charge' => '0.000000', 'carrier_cost' => '0.000000', 'customer_connect_fee' => '0.000000',
            'carrier_connect_fee' => '0.000000', 'gross_margin' => '0.000000',
            'sip_response' => $d['sip_response'], 'disposition' => $d['disposition'], 'failure_reason' => $d['failure_reason'],
            'source_ip' => $d['source_ip'] ?? null,
            'media_ip' => $this->resolveMediaIp($d),
            'media_policy_requested' => $d['media_policy_requested'], 'media_policy_actual' => $d['media_policy_actual'],
            'recording_status' => $d['recording_status'], 'settlement_status' => $customer === null ? 'unattributed' : 'no_charge',
            'currency' => $customer['currency'] ?? null, 'is_demo' => 0,
        ];

        $ledgerPost = null;
        $discrepancy = false;
        $callId = $d['sip_call_id'];

        if ($answered && $customer !== null) {
            $crate = $this->resolver->customer((int) $customer['id'], $dst);
            if ($crate === null) {
                $row['settlement_status'] = 'unrated';   // no matching tariff: flagged for ops, never silently free
            } else {
                $c = $this->rating->rate($crate['rate'], $crate['initial'], $crate['subsequent'], $duration, $crate['connect_fee'], $crate['charge_connect_on_zero'], $crate['min_billable']);
                $row = array_merge($row, [
                    'dst_prefix' => $crate['prefix'] !== '' ? $crate['prefix'] : null,
                    'customer_rate' => $crate['rate'], 'customer_initial' => $crate['initial'], 'customer_subsequent' => $crate['subsequent'],
                    'duration_billable_customer' => $c['billable_seconds'], 'customer_charge' => $c['total'], 'customer_connect_fee' => $c['connect_fee'],
                    'customer_rate_plan_version_id' => $crate['version_id'], 'rating_source' => 'portal:' . $crate['source'],
                    'rated_at' => gmdate('Y-m-d H:i:s.v'), 'settlement_status' => 'settled', 'currency' => $crate['currency'],
                ]);
                $carrierTotal = '0.000000';
                if ($d['carrier_trunk_id'] !== null && ($k = $this->resolver->carrier($d['carrier_trunk_id'], $dst)) !== null) {
                    $kr = $this->rating->rate($k['rate'], $k['initial'], $k['subsequent'], $duration, $k['connect_fee']);
                    $carrierTotal = $kr['total'];
                    $row = array_merge($row, [
                        'carrier_id' => $k['carrier_id'], 'carrier_rate' => $k['rate'], 'carrier_initial' => $k['initial'], 'carrier_subsequent' => $k['subsequent'],
                        'duration_billable_carrier' => $kr['billable_seconds'], 'carrier_cost' => $kr['total'], 'carrier_connect_fee' => $kr['connect_fee'],
                    ]);
                } elseif ($d['carrier_trunk_id'] !== null) {
                    $row['carrier_id'] = $this->carrierOfTrunk($d['carrier_trunk_id']);
                }
                $row['gross_margin'] = $this->rating->margin($c['total'], $carrierTotal);

                if ($d['cgrates_cost'] !== null) {   // reconcile (BILLING_RULES s7): portal formula stays the invoice truth
                    $row['cgrates_cost'] = $d['cgrates_cost'];
                    $tol = $this->settings->get('rating_tolerance', '0.000001') ?? '0.000001';
                    $diff = bcsub($c['total'], $d['cgrates_cost'], 6);
                    $diff = str_starts_with($diff, '-') ? substr($diff, 1) : $diff;
                    $discrepancy = bccomp($diff, $tol, 6) > 0;
                    $row['rating_discrepancy'] = $discrepancy ? 1 : 0;
                }
            }
        } elseif ($d['carrier_trunk_id'] !== null) {
            $row['carrier_id'] = $this->carrierOfTrunk($d['carrier_trunk_id']);
        }

        // Persist CDR + ledger atomically so a crash can never leave a charge without a CDR (or vice versa).
        $id = $this->ledger->tx(function () use ($row, $customer, $callId, &$ledgerPost, $key): int {
            $id = $this->cdrs->insert($row);
            if ($row['settlement_status'] === 'settled' && $customer !== null && bccomp((string) $row['customer_charge'], '0', 6) > 0) {
                $ledgerPost = $this->ledger->post([
                    'customer_id' => (int) $customer['id'], 'reseller_id' => $customer['reseller_id'] !== null ? (int) $customer['reseller_id'] : null,
                    'entry_type' => 'call_charge', 'amount' => bcmul((string) $row['customer_charge'], '-1', 6), 'currency' => (string) $row['currency'],
                    'cdr_id' => $id, 'source_system' => $row['source_system'], 'external_ref' => $key, 'run_type' => 'customer',
                    'idempotency_key' => 'cdr:' . $key . ':customer', 'description' => 'Call ' . $row['dst_normalized'],
                ]);
            }
            if ($customer !== null && $callId !== null) {
                $this->prepaid->release((int) $customer['id'], $callId, 'settled');
            }
            return $id;
        });

        return ['status' => 'ingested', 'id' => $id, 'settlement' => (string) $row['settlement_status'], 'discrepancy' => $discrepancy];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function validate(array $raw): array
    {
        $e = [];
        $key = trim((string) ($raw['external_cdr_key'] ?? $raw['uuid'] ?? ''));
        if ($key === '' || strlen($key) > 150) {
            $e[] = 'external_cdr_key is required (max 150 chars).';
        }
        $disp = strtoupper(trim((string) ($raw['disposition'] ?? 'FAILED')));
        if (!in_array($disp, self::DISPOSITIONS, true)) {
            $e[] = 'disposition must be one of ' . implode(', ', self::DISPOSITIONS);
        }
        $dstRaw = trim((string) ($raw['dst'] ?? ''));
        $dst = Destination::normalize($dstRaw);
        if ($dst === '' || strlen($dst) > 30) {
            $e[] = 'dst is required and must contain digits.';
        }
        $setup = $this->ts($raw['setup_at'] ?? null);
        if ($setup === null) {
            $e[] = 'setup_at must be a valid date-time.';
        }
        $dur = $raw['duration'] ?? 0;
        $durOk = is_int($dur) ? $dur >= 0 : (is_string($dur) && ctype_digit($dur));
        if (!$durOk || (int) $dur > 86400 * 2) {
            $e[] = 'duration must be an integer number of seconds (0..172800).';
        }
        $cg = $raw['cgrates_cost'] ?? null;
        if ($cg !== null && $cg !== '' && !preg_match('/^\d+(\.\d+)?$/', (string) $cg)) {
            $e[] = 'cgrates_cost must be a decimal string.';
        }
        if ($e) {
            return [[], $e];
        }
        $str = static fn ($v, int $max) => ($v === null || $v === '') ? null : mb_substr((string) $v, 0, $max);
        return [[
            'external_key' => $key, 'disposition' => $disp, 'dst_raw' => mb_substr($dstRaw, 0, 64), 'dst_normalized' => $dst,
            'src' => $str($raw['src'] ?? null, 64), 'sip_call_id' => $str($raw['sip_call_id'] ?? null, 190),
            'setup_at' => $setup, 'answer_at' => $this->ts($raw['answer_at'] ?? null), 'end_at' => $this->ts($raw['end_at'] ?? null),
            'duration' => (int) $dur, 'sip_response' => isset($raw['sip_response']) ? (int) $raw['sip_response'] : null,
            'failure_reason' => $str($raw['failure_reason'] ?? null, 190),
            'carrier_trunk_id' => isset($raw['carrier_trunk_id']) && $raw['carrier_trunk_id'] !== '' ? (int) $raw['carrier_trunk_id'] : null,
            'routing_rule_id' => isset($raw['routing_rule_id']) && $raw['routing_rule_id'] !== '' ? (int) $raw['routing_rule_id'] : null,
            'username' => $str($raw['username'] ?? null, 64), 'domain' => $str($raw['domain'] ?? null, 128),
            'source_ip' => $str($raw['source_ip'] ?? null, 45),
            'media_ip' => $str($raw['media_ip'] ?? null, 64),
            'customer_id' => isset($raw['customer_id']) ? (int) $raw['customer_id'] : null,
            'cgrates_cost' => ($cg === null || $cg === '') ? null : RatingService::round((string) $cg, 6),
            'media_policy_requested' => $str($raw['media_policy_requested'] ?? null, 16),
            'media_policy_actual' => $str($raw['media_policy_actual'] ?? null, 16),
            'recording_status' => $str($raw['recording_status'] ?? null, 16),
        ], []];
    }

    /**
     * @param array<string,mixed> $d
     * @return array{customer:?array<string,mixed>,sip_account_id:?int,ip_endpoint_id:?int}
     */
    private function attribute(array $d, string $source): array
    {
        $out = ['customer' => null, 'sip_account_id' => null, 'ip_endpoint_id' => null];
        $customerId = null;
        if ($d['username'] !== null) {
            $st = $this->pdo->prepare('SELECT id, customer_id FROM sip_accounts WHERE username = ?' . ($d['domain'] !== null ? ' AND domain = ?' : '') . ' LIMIT 2');
            $st->execute($d['domain'] !== null ? [$d['username'], $d['domain']] : [$d['username']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {   // ambiguous usernames across domains are NOT guessed
                $out['sip_account_id'] = (int) $rows[0]['id'];
                $customerId = (int) $rows[0]['customer_id'];
            }
            // Case-insensitive fallback (Eyebeam / ACC casing)
            if ($customerId === null) {
                $st = $this->pdo->prepare('SELECT id, customer_id FROM sip_accounts WHERE LOWER(username) = LOWER(?) LIMIT 2');
                $st->execute([$d['username']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) === 1) {
                    $out['sip_account_id'] = (int) $rows[0]['id'];
                    $customerId = (int) $rows[0]['customer_id'];
                }
            }
        }
        if ($customerId === null && $d['source_ip'] !== null && filter_var($d['source_ip'], FILTER_VALIDATE_IP)) {
            $ep = (new IpEndpointRepository($this->pdo))->findActiveByIp($d['source_ip']);
            if ($ep) {
                $out['ip_endpoint_id'] = (int) $ep['id'];
                $customerId = (int) $ep['customer_id'];
            }
            // Softphone: match OpenSIPS-registered contact IP → sip_accounts via portal username list later
            if ($customerId === null) {
                $st = $this->pdo->prepare(
                    "SELECT id, customer_id FROM sip_accounts
                     WHERE status = 'active' AND sip_host = ? LIMIT 2"
                );
                $st->execute([$d['source_ip']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) === 1) {
                    $out['sip_account_id'] = (int) $rows[0]['id'];
                    $customerId = (int) $rows[0]['customer_id'];
                }
            }
            // Dynamic REGISTER (ViciDial): usrloc received/contact IP → SIP username
            if ($customerId === null) {
                try {
                    $prov = \Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner::fromConfig();
                    $u = $prov->enabled() ? $prov->usernameBySourceIp((string) $d['source_ip']) : null;
                    if ($u !== null && $u !== '') {
                        $st = $this->pdo->prepare(
                            "SELECT id, customer_id FROM sip_accounts WHERE status = 'active' AND LOWER(username) = LOWER(?) LIMIT 2"
                        );
                        $st->execute([$u]);
                        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                        if (count($rows) === 1) {
                            $out['sip_account_id'] = (int) $rows[0]['id'];
                            $customerId = (int) $rows[0]['customer_id'];
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }
        // Single-tenant failsafe: exactly one active SIP account on the platform
        $trusted = self::isTrustedCdrSource($source);
        if ($customerId === null && $trusted) {
            $st = $this->pdo->query("SELECT id, customer_id FROM sip_accounts WHERE status = 'active' LIMIT 2");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            if (count($rows) === 1) {
                $out['sip_account_id'] = (int) $rows[0]['id'];
                $customerId = (int) $rows[0]['customer_id'];
            }
        }
        if ($customerId === null && $d['customer_id'] !== null && $trusted) {
            $customerId = $d['customer_id'];   // only trusted internal sources may name the customer directly
        }
        if ($customerId !== null) {
            $st = $this->pdo->prepare('SELECT id, reseller_id, currency, billing_mode, credit_limit FROM customers WHERE id = ?');
            $st->execute([$customerId]);
            $out['customer'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $out;
    }

    private static function isTrustedCdrSource(string $source): bool
    {
        $source = strtolower(trim($source));
        if (in_array($source, ['worker', 'internal', 'seed', 'freeswitch', 'opensips', 'opensips_acc', 'opensips_missed_calls'], true)) {
            return true;
        }
        return str_starts_with($source, 'opensips_');
    }

    private function carrierOfTrunk(int $trunkId): ?int
    {
        $st = $this->pdo->prepare('SELECT carrier_id FROM carrier_trunks WHERE id = ?');
        $st->execute([$trunkId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Prefer ACC/media_ip; else derive from media policy (direct = signalling IP, relay = switch public IP). */
    private function resolveMediaIp(array $d): ?string
    {
        $raw = trim((string) ($d['media_ip'] ?? ''));
        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_IP)) {
            return mb_substr($raw, 0, 64);
        }
        $policy = strtolower(trim((string) ($d['media_policy_actual'] ?? $d['media_policy_requested'] ?? '')));
        if ($policy === 'direct') {
            $src = trim((string) ($d['source_ip'] ?? ''));
            return $src !== '' ? mb_substr($src, 0, 64) : null;
        }
        if (in_array($policy, ['relay', 'record'], true)) {
            $pub = trim((string) (\Ownswitch\App::config('app.integrations.opensips.public_ip', '') ?: ''));
            return $pub !== '' ? mb_substr($pub, 0, 64) : null;
        }
        return null;
    }

    private function ts(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
