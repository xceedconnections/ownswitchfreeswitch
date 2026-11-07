<?php

declare(strict_types=1);

namespace Ownswitch\Repositories;

use PDO;

final class RatePlanRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct(
            $pdo,
            'rate_plans',
            ['code', 'name', 'currency', 'status', 'default_rate', 'initial_increment', 'subsequent_increment', 'connect_fee',
                'min_billable_seconds', 'charge_connect_on_zero', 'current_version', 'is_demo'],
            'none',
            ['code', 'name'],
            ['id', 'code', 'name', 'status', 'created_at']
        );
    }

    /**
     * Immutable snapshot (BILLING_RULES section 8): new version row with content hash of plan defaults + tariffs.
     * Returns the new rate_plan_versions.id.
     */
    public function createVersion(int $planId, ?int $userId): int
    {
        $plan = $this->findInternal($planId);
        if ($plan === null) {
            throw new \RuntimeException('Rate plan not found');
        }
        $cols = 'prefix, rate, initial_increment, subsequent_increment, connect_fee, status';
        try {
            $this->pdo->query('SELECT plan_prefix_id FROM rate_plan_tariffs LIMIT 0');
            $cols = 'plan_prefix_id, ' . $cols;
        } catch (\Throwable) {
        }
        $st = $this->pdo->prepare("SELECT {$cols} FROM rate_plan_tariffs WHERE rate_plan_id = ? ORDER BY prefix");
        $st->execute([$planId]);
        $tariffs = $st->fetchAll(PDO::FETCH_ASSOC);
        $defaults = array_intersect_key($plan, array_flip(['default_rate', 'initial_increment', 'subsequent_increment', 'connect_fee', 'min_billable_seconds', 'charge_connect_on_zero', 'currency']));
        $hash = hash('sha256', json_encode([$defaults, $tariffs], JSON_THROW_ON_ERROR));

        $last = $this->pdo->prepare('SELECT id, content_hash, version_no FROM rate_plan_versions WHERE rate_plan_id = ? ORDER BY version_no DESC LIMIT 1');
        $last->execute([$planId]);
        $prev = $last->fetch(PDO::FETCH_ASSOC);
        if ($prev && $prev['content_hash'] === $hash) {
            return (int) $prev['id'];
        }
        $no = $prev ? (int) $prev['version_no'] + 1 : 1;
        $this->pdo->prepare('INSERT INTO rate_plan_versions (rate_plan_id, version_no, content_hash, created_by) VALUES (?,?,?,?)')
            ->execute([$planId, $no, $hash, $userId]);
        $vid = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE rate_plans SET current_version = ? WHERE id = ?')->execute([$no, $planId]);
        return $vid;
    }

    public function currentVersionId(int $planId): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM rate_plan_versions WHERE rate_plan_id = ? ORDER BY version_no DESC LIMIT 1');
        $st->execute([$planId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
