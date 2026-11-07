<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Mass-edit sell (rate_plan_tariffs) or buy (carrier_tariffs) rows.
 * Blank fields are left as they are. Prefix filter is starts-with or exact.
 */
final class BatchRateUpdateService
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function prefixMatches(string $rowPrefix, string $filter, string $mode): bool
    {
        $row = preg_replace('/\D+/', '', $rowPrefix) ?? '';
        $pf = preg_replace('/\D+/', '', $filter) ?? '';
        if ($pf === '') {
            return true;
        }
        if ($mode === 'exact') {
            return $row === $pf;
        }
        return str_starts_with($row, $pf);
    }

    /**
     * @param list<int> $ids
     * @param array{
     *   rate_mode?:string,
     *   rate_value?:string,
     *   prefix_filter?:?string,
     *   prefix_mode?:string,
     *   routing_plan_id?:?string,
     *   initial_increment?:?string,
     *   subsequent_increment?:?string
     * } $changes
     * @return array{updated:int}
     */
    public function apply(string $kind, TenantScope $scope, array $ids, array $changes): array
    {
        $table = $kind === 'carrier' ? 'carrier_tariffs' : 'rate_plan_tariffs';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return ['updated' => 0];
        }

        $rateValue = trim((string) ($changes['rate_value'] ?? ''));
        $rateMode = (string) ($changes['rate_mode'] ?? 'percent');
        $changeRate = $rateValue !== '';
        if ($changeRate) {
            if (!in_array($rateMode, ['percent', 'absolute'], true)) {
                throw new \InvalidArgumentException('Price mode must be percent or absolute');
            }
            if (!preg_match('/^-?\d+(\.\d{1,8})?$/', $rateValue)) {
                throw new \InvalidArgumentException('Price must be a decimal');
            }
            if ($rateMode === 'absolute' && bccomp($rateValue, '0', 8) < 0) {
                throw new \InvalidArgumentException('Price cannot be negative');
            }
        }

        $initial = $this->block($changes['initial_increment'] ?? null, 'Initial block');
        $subsequent = $this->block($changes['subsequent_increment'] ?? null, 'Billing block');

        $setTrunk = false;
        $trunkId = null;
        if ($kind !== 'carrier' && array_key_exists('routing_plan_id', $changes) && $changes['routing_plan_id'] !== null && $changes['routing_plan_id'] !== '') {
            $setTrunk = true;
            $rawTrunk = (string) $changes['routing_plan_id'];
            if ($rawTrunk === '0') {
                $trunkId = null;
            } elseif (ctype_digit($rawTrunk) && (int) $rawTrunk > 0) {
                $trunkId = (int) $rawTrunk;
            } else {
                throw new \InvalidArgumentException('Trunk group is invalid');
            }
        }

        if (!$changeRate && $initial === null && $subsequent === null && !$setTrunk) {
            throw new \InvalidArgumentException('Choose a trunk group, price, initial block, or billing block to update.');
        }

        $prefixFilter = trim((string) ($changes['prefix_filter'] ?? ''));
        $prefixMode = ($changes['prefix_mode'] ?? 'starts') === 'exact' ? 'exact' : 'starts';

        [$tw, $tp] = $scope->where('none');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $cols = 'id, rate, prefix, initial_increment, subsequent_increment';
        if ($kind !== 'carrier') {
            $cols .= ', routing_plan_id';
        }
        $st = $this->pdo->prepare("SELECT {$cols} FROM `{$table}` WHERE id IN ({$ph}) AND ({$tw})");
        $st->execute([...$ids, ...$tp]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($prefixFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => self::prefixMatches((string) ($row['prefix'] ?? ''), $prefixFilter, $prefixMode)
            ));
        }

        $n = 0;
        foreach ($rows as $row) {
            $set = [];
            $params = [];
            if ($changeRate) {
                $old = RatingService::round((string) $row['rate'], 8);
                if ($rateMode === 'absolute') {
                    $new = RatingService::round($rateValue, 8);
                } else {
                    $factor = bcadd('1', bcdiv($rateValue, '100', 10), 10);
                    $new = RatingService::round(bcmul($old, $factor, 10), 8);
                }
                if (bccomp($new, '0', 8) < 0) {
                    $new = '0.00000000';
                }
                if (bccomp($old, $new, 8) !== 0) {
                    $set[] = 'rate = ?';
                    $params[] = $new;
                }
            }
            if ($initial !== null && (int) ($row['initial_increment'] ?? 0) !== $initial) {
                $set[] = 'initial_increment = ?';
                $params[] = $initial;
            }
            if ($subsequent !== null && (int) ($row['subsequent_increment'] ?? 0) !== $subsequent) {
                $set[] = 'subsequent_increment = ?';
                $params[] = $subsequent;
            }
            if ($setTrunk) {
                $current = $row['routing_plan_id'] ?? null;
                $current = ($current === null || $current === '') ? null : (int) $current;
                if ($current !== $trunkId) {
                    $set[] = 'routing_plan_id = ?';
                    $params[] = $trunkId;
                }
            }
            if ($set === []) {
                continue;
            }
            $params[] = (int) $row['id'];
            $upd = $this->pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE id = ?');
            $upd->execute($params);
            $n++;
        }
        return ['updated' => $n];
    }

    private function block(mixed $raw, string $label): ?int
    {
        if ($raw === null) {
            return null;
        }
        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $v)) {
            throw new \InvalidArgumentException($label . ' must be a whole number of seconds');
        }
        $n = (int) $v;
        if ($n < 1 || $n > 86400) {
            throw new \InvalidArgumentException($label . ' must be between 1 and 86400 seconds');
        }
        return $n;
    }
}
