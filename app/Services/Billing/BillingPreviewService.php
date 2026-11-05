<?php

declare(strict_types=1);

namespace Ownswitch\Services\Billing;

/** Admin billing calculator. Server-side only: always delegates to RatingService. */
final class BillingPreviewService
{
    public const PRESETS = [
        '6/6'   => [6, 6],
        '12/6'  => [12, 6],
        '30/6'  => [30, 6],
        '60/60' => [60, 60],
    ];

    public function __construct(private RatingService $rating = new RatingService())
    {
    }

    /**
     * @param array<string,mixed> $in rate, initial, subsequent, duration, connect_fee, [carrier_rate, carrier_initial, carrier_subsequent, carrier_connect_fee]
     * @return array{errors:array<string,string>,result:?array<string,mixed>}
     */
    public function preview(array $in): array
    {
        $errors = [];
        $rate = trim((string) ($in['rate'] ?? ''));
        $fee = trim((string) ($in['connect_fee'] ?? '0')) ?: '0';
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $rate)) {
            $errors['rate'] = 'Rate per minute must be a non-negative decimal (max 8 decimals).';
        }
        if (!preg_match('/^\d+(\.\d{1,6})?$/', $fee)) {
            $errors['connect_fee'] = 'Connection fee must be a non-negative decimal (max 6 decimals).';
        }
        $i = $this->posInt($in['initial'] ?? null, 'initial', 'Initial increment', $errors, 1);
        $s = $this->posInt($in['subsequent'] ?? null, 'subsequent', 'Subsequent increment', $errors, 1);
        $d = $this->posInt($in['duration'] ?? null, 'duration', 'Duration', $errors, 0);
        if ($errors) {
            return ['errors' => $errors, 'result' => null];
        }

        $customer = $this->rating->rate($rate, $i, $s, $d, $fee);
        $result = [
            'input'      => ['rate' => $rate, 'initial' => $i, 'subsequent' => $s, 'duration' => $d, 'connect_fee' => $fee],
            'customer'   => $customer,
            'steps'      => $this->steps($d, $i, $s, $customer['billable_seconds'], $rate),
            'comparison' => [],
            'carrier'    => null,
            'margin'     => null,
        ];

        foreach (self::PRESETS as $label => [$pi, $ps]) {
            if ($label === '30/6' || $label === '60/60') {
                continue; // keep the side-by-side to the spec presets + custom
            }
            $result['comparison'][$label] = $this->rating->rate($rate, $pi, $ps, $d, $fee);
        }
        $result['comparison']['custom ' . $i . '/' . $s] = $customer;

        $cRate = trim((string) ($in['carrier_rate'] ?? ''));
        if ($cRate !== '') {
            $cErr = [];
            if (!preg_match('/^\d+(\.\d{1,8})?$/', $cRate)) {
                $cErr['carrier_rate'] = 'Carrier rate must be a non-negative decimal.';
            }
            $ci = $this->posInt($in['carrier_initial'] ?? $i, 'carrier_initial', 'Carrier initial', $cErr, 1);
            $cs = $this->posInt($in['carrier_subsequent'] ?? $s, 'carrier_subsequent', 'Carrier subsequent', $cErr, 1);
            $cFee = trim((string) ($in['carrier_connect_fee'] ?? '0')) ?: '0';
            if (!preg_match('/^\d+(\.\d{1,6})?$/', $cFee)) {
                $cErr['carrier_connect_fee'] = 'Carrier connection fee must be a non-negative decimal.';
            }
            if ($cErr) {
                return ['errors' => $cErr, 'result' => null];
            }
            $carrier = $this->rating->rate($cRate, $ci, $cs, $d, $cFee);
            $result['carrier'] = $carrier + ['rate' => $cRate, 'initial' => $ci, 'subsequent' => $cs];
            $result['margin'] = $this->rating->margin($customer['total'], $carrier['total']);
        }
        return ['errors' => [], 'result' => $result];
    }

    /**
     * Human-readable breakdown of how the billable seconds were built.
     *
     * @return list<array{label:string,seconds:int,amount:string}>
     */
    public function steps(int $d, int $i, int $s, int $billable, string $rate): array
    {
        if ($d === 0) {
            return [['label' => 'Zero duration: nothing billable', 'seconds' => 0, 'amount' => '0.000000']];
        }
        $steps = [['label' => "Initial block ({$i}s)", 'seconds' => $i, 'amount' => $this->rating->usageCharge($rate, $i)]];
        $n = intdiv(max(0, $billable - $i), $s);
        if ($n > 0) {
            $steps[] = [
                'label'   => "{$n} x subsequent increment ({$s}s) = " . ($n * $s) . 's',
                'seconds' => $n * $s,
                'amount'  => $this->rating->usageCharge($rate, $n * $s),
            ];
        }
        return $steps;
    }

    /** @param array<string,string> $errors */
    private function posInt(mixed $v, string $key, string $label, array &$errors, int $min): int
    {
        $s = trim((string) $v);
        if (!preg_match('/^\d{1,7}$/', $s) || (int) $s < $min) {
            $errors[$key] = "{$label} must be a whole number >= {$min}.";
            return 0;
        }
        return (int) $s;
    }
}
