<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use Ownswitch\Support\TenantScope;
use PDO;

/**
 * Mass-edit sell (rate_plan_tariffs) or buy (carrier_tariffs) rates by % or absolute value.
 */
final class BatchRateUpdateService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int> $ids
     * @return array{updated:int}
     */
    public function apply(
        string $kind,
        TenantScope $scope,
        array $ids,
        string $mode,
        string $value,
        ?string $prefixFilter = null,
    ): array {
        $table = $kind === 'carrier' ? 'carrier_tariffs' : 'rate_plan_tariffs';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return ['updated' => 0];
        }
        if (!in_array($mode, ['percent', 'absolute'], true)) {
            throw new \InvalidArgumentException('mode must be percent or absolute');
        }
        if (!preg_match('/^-?\d+(\.\d{1,8})?$/', $value)) {
            throw new \InvalidArgumentException('value must be a decimal');
        }
        if ($mode === 'absolute' && bccomp($value, '0', 8) < 0) {
            throw new \InvalidArgumentException('absolute rate cannot be negative');
        }

        [$tw, $tp] = $scope->where('none');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, rate, prefix FROM `{$table}` WHERE id IN ({$ph}) AND ({$tw})";
        $st = $this->pdo->prepare($sql);
        $st->execute([...$ids, ...$tp]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($prefixFilter !== null && $prefixFilter !== '') {
            $pf = preg_replace('/\D+/', '', $prefixFilter) ?? '';
            $rows = array_values(array_filter($rows, static function (array $row) use ($pf): bool {
                $p = preg_replace('/\D+/', '', (string) ($row['prefix'] ?? '')) ?? '';
                return $pf === '' || str_starts_with($p, $pf) || str_starts_with($pf, $p);
            }));
        }

        $upd = $this->pdo->prepare("UPDATE `{$table}` SET rate = ? WHERE id = ?");
        $n = 0;
        foreach ($rows as $row) {
            $old = RatingService::round((string) $row['rate'], 8);
            if ($mode === 'absolute') {
                $new = RatingService::round($value, 8);
            } else {
                $factor = bcadd('1', bcdiv($value, '100', 10), 10);
                $new = RatingService::round(bcmul($old, $factor, 10), 8);
            }
            if (bccomp($new, '0', 8) < 0) {
                $new = '0.00000000';
            }
            if (bccomp($old, $new, 8) === 0) {
                continue;
            }
            $upd->execute([$new, (int) $row['id']]);
            $n += $upd->rowCount() > 0 ? 1 : 0;
        }
        return ['updated' => $n];
    }
}
