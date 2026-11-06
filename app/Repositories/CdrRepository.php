<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

/**
 * cdrs.customer_charge / carrier_cost are TOTALS (usage + connect fee); *_connect_fee hold the fee component.
 */
final class CdrRepository extends BaseRepository
{
    public const COLUMNS = [
        'uuid', 'source_system', 'external_cdr_key', 'sip_call_id', 'reseller_id', 'customer_id', 'sip_account_id', 'ip_endpoint_id',
        'src', 'dst_raw', 'dst_normalized', 'dst_prefix', 'carrier_id', 'carrier_trunk_id', 'routing_rule_id',
        'setup_at', 'answer_at', 'end_at', 'duration_actual', 'duration_billable_customer', 'duration_billable_carrier',
        'customer_rate', 'carrier_rate', 'customer_initial', 'customer_subsequent', 'carrier_initial', 'carrier_subsequent',
        'customer_charge', 'carrier_cost', 'customer_connect_fee', 'carrier_connect_fee', 'gross_margin',
        'customer_rate_plan_version_id', 'carrier_tariff_version_id', 'sip_response', 'disposition',
        'direction', 'hangup_initiator', 'hangup_cause', 'failure_reason', 'sip_trace',
        'source_ip', 'media_ip',
        'media_policy_requested', 'media_policy_actual', 'recording_status', 'fraud_status', 'fraud_event_id',
        'settlement_status', 'rating_source', 'rated_at', 'rating_discrepancy', 'cgrates_cost', 'currency', 'is_demo',
    ];

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'cdrs', self::COLUMNS, 'owned', ['src', 'dst_raw', 'dst_normalized', 'sip_call_id'],
            ['id', 'setup_at', 'duration_actual', 'customer_charge', 'carrier_cost', 'disposition']);
    }

    public function findIdByKey(string $key): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM cdrs WHERE external_cdr_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
