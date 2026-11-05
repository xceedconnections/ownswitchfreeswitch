<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

use InvalidArgumentException;

/**
 * CANONICAL billing formula (BILLING_RULES.md). Every preview, CDR settlement and test uses this class.
 *
 *   billable: D == 0 -> 0 ; D <= I -> I ; else I + ceil((D - I) / S) * S
 *   usage   = rate_per_minute * billable / 60
 *   total   = usage + connect_fee   (connect fee only when D > 0 unless charge_connect_on_zero)
 *
 * All money math uses bcmath on decimal STRINGS. Floats are rejected (strict_types) and never used.
 */
final class RatingService
{
    /** Scale of stored per-call amounts (DECIMAL(18,6)). */
    public const STORED_SCALE = 6;
    /** Intermediate precision (>= 8 per BILLING_RULES). */
    private const CALC_SCALE = 12;

    /**
     * @param int $duration  D, answered connected seconds (>= 0)
     * @param int $initial   I (> 0)
     * @param int $subsequent S (> 0)
     * @param int $minBillable optional minimum billable seconds, applied only when D > 0
     */
    public function billableSeconds(int $duration, int $initial, int $subsequent, int $minBillable = 0): int
    {
        if ($duration < 0) {
            throw new InvalidArgumentException('Duration must be >= 0');
        }
        if ($initial <= 0 || $subsequent <= 0) {
            throw new InvalidArgumentException('Initial and subsequent increments must be > 0');
        }
        if ($minBillable < 0) {
            throw new InvalidArgumentException('Minimum billable seconds must be >= 0');
        }
        if ($duration === 0) {
            return 0;
        }
        $billable = $duration <= $initial
            ? $initial
            : $initial + intdiv($duration - $initial + $subsequent - 1, $subsequent) * $subsequent; // ceil((D-I)/S)*S
        return max($billable, $minBillable);
    }

    /**
     * usage_charge = rate * billable / 60, rounded half-up to STORED_SCALE.
     *
     * @param string $ratePerMinute non-negative decimal string
     */
    public function usageCharge(string $ratePerMinute, int $billableSeconds): string
    {
        self::assertDecimal($ratePerMinute, 'rate');
        if ($billableSeconds < 0) {
            throw new InvalidArgumentException('Billable seconds must be >= 0');
        }
        $raw = bcdiv(bcmul($ratePerMinute, (string) $billableSeconds, self::CALC_SCALE), '60', self::CALC_SCALE);
        return self::round($raw, self::STORED_SCALE);
    }

    /**
     * Full rating of one call leg.
     *
     * @return array{billable_seconds:int,usage_charge:string,connect_fee:string,total:string,answered:bool}
     */
    public function rate(
        string $ratePerMinute,
        int $initial,
        int $subsequent,
        int $duration,
        string $connectFee = '0',
        bool $chargeConnectOnZero = false,
        int $minBillable = 0,
    ): array {
        self::assertDecimal($ratePerMinute, 'rate');
        self::assertDecimal($connectFee, 'connect fee');
        $billable = $this->billableSeconds($duration, $initial, $subsequent, $minBillable);
        $usage = $this->usageCharge($ratePerMinute, $billable);
        $fee = ($duration > 0 || $chargeConnectOnZero) ? self::round($connectFee, self::STORED_SCALE) : self::round('0', self::STORED_SCALE);
        return [
            'billable_seconds' => $billable,
            'usage_charge'     => $usage,
            'connect_fee'      => $fee,
            'total'            => bcadd($usage, $fee, self::STORED_SCALE),
            'answered'         => $duration > 0,
        ];
    }

    /** Margin = customer total - carrier total (scale 6). */
    public function margin(string $customerTotal, string $carrierTotal): string
    {
        return bcsub($customerTotal, $carrierTotal, self::STORED_SCALE);
    }

    /** Round half-up (away from zero) to $scale decimals using bcmath only. */
    public static function round(string $value, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';
        return bccomp($value, '0', self::CALC_SCALE) < 0
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    public static function assertDecimal(string $v, string $label): void
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
            throw new InvalidArgumentException("Invalid {$label}: must be a non-negative decimal string");
        }
    }
}
