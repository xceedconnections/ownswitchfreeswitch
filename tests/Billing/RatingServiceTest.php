<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Billing;

use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Tests\TestCase;

/** Fixtures come straight from BILLING_RULES.md sections 1 and 2. */
final class RatingServiceTest extends TestCase
{
    private RatingService $r;

    protected function setUp(): void
    {
        $this->r = new RatingService();
    }

    public function testSpecFixtureTable(): void
    {
        // [D, I, S, expected billable]
        $fixtures = [
            [0, 6, 6, 0], [1, 6, 6, 6], [6, 6, 6, 6], [7, 6, 6, 12],
            [1, 12, 6, 12], [12, 12, 6, 12], [13, 12, 6, 18], [16, 12, 6, 18], [18, 12, 6, 18], [19, 12, 6, 24],
            [61, 60, 60, 120], [30, 30, 6, 30], [31, 30, 6, 36],
        ];
        foreach ($fixtures as [$d, $i, $s, $expected]) {
            $this->assertSame($expected, $this->r->billableSeconds($d, $i, $s), "D={$d} I/S={$i}/{$s}");
        }
    }

    public function testSixSix(): void
    {
        $this->assertSame(0, $this->r->billableSeconds(0, 6, 6));
        $this->assertSame(6, $this->r->billableSeconds(1, 6, 6));
        $this->assertSame(6, $this->r->billableSeconds(6, 6, 6));
        $this->assertSame(12, $this->r->billableSeconds(7, 6, 6));
        $this->assertSame(60, $this->r->billableSeconds(55, 6, 6));
        $this->assertSame(60, $this->r->billableSeconds(60, 6, 6));
        $this->assertSame(66, $this->r->billableSeconds(61, 6, 6));
    }

    public function testTwelveSix(): void
    {
        $this->assertSame(12, $this->r->billableSeconds(1, 12, 6));
        $this->assertSame(12, $this->r->billableSeconds(12, 12, 6));
        $this->assertSame(18, $this->r->billableSeconds(13, 12, 6));
        $this->assertSame(18, $this->r->billableSeconds(16, 12, 6));
        $this->assertSame(18, $this->r->billableSeconds(18, 12, 6));
        $this->assertSame(24, $this->r->billableSeconds(19, 12, 6));
        $this->assertSame(66, $this->r->billableSeconds(61, 12, 6));   // 12 + ceil(49/6)=9 * 6 = 66
    }

    public function testCustomIncrements(): void
    {
        // I=15 S=10
        $this->assertSame(15, $this->r->billableSeconds(15, 15, 10));
        $this->assertSame(25, $this->r->billableSeconds(16, 15, 10));
        $this->assertSame(25, $this->r->billableSeconds(25, 15, 10));
        $this->assertSame(35, $this->r->billableSeconds(26, 15, 10));
        // I smaller than S (18/6 inverse: 1/60) is legal
        $this->assertSame(1, $this->r->billableSeconds(1, 1, 60));
        $this->assertSame(61, $this->r->billableSeconds(2, 1, 60));
        // 60/60
        $this->assertSame(60, $this->r->billableSeconds(60, 60, 60));
        $this->assertSame(120, $this->r->billableSeconds(61, 60, 60));
        // 1/1 is exact-second billing
        $this->assertSame(137, $this->r->billableSeconds(137, 1, 1));
    }

    public function testBoundariesAroundInitialAndSubsequent(): void
    {
        foreach ([[12, 6], [30, 6], [6, 6], [60, 60], [15, 10]] as [$i, $s]) {
            $this->assertSame($i, $this->r->billableSeconds($i - 1, $i, $s), "D=I-1 for {$i}/{$s}");
            $this->assertSame($i, $this->r->billableSeconds($i, $i, $s), "D=I for {$i}/{$s}");
            $this->assertSame($i + $s, $this->r->billableSeconds($i + 1, $i, $s), "D=I+1 for {$i}/{$s}");
            $this->assertSame($i + $s, $this->r->billableSeconds($i + $s, $i, $s), "D=I+S for {$i}/{$s}");
            $this->assertSame($i + 2 * $s, $this->r->billableSeconds($i + $s + 1, $i, $s), "D=I+S+1 for {$i}/{$s}");
        }
    }

    public function testZeroDurationIsNotBillableAndHasNoConnectFee(): void
    {
        $x = $this->r->rate('0.0100', 12, 6, 0, '0.05');
        $this->assertSame(0, $x['billable_seconds']);
        $this->assertSame('0.000000', $x['usage_charge']);
        $this->assertSame('0.000000', $x['connect_fee']);
        $this->assertSame('0.000000', $x['total']);
        $this->assertFalse($x['answered']);
    }

    public function testZeroDurationWithChargeConnectOnZeroPolicy(): void
    {
        $x = $this->r->rate('0.0100', 12, 6, 0, '0.05', true);
        $this->assertSame(0, $x['billable_seconds']);   // still no usage
        $this->assertSame('0.050000', $x['connect_fee']);
        $this->assertSame('0.050000', $x['total']);
    }

    public function testSpecWorkedExample(): void
    {
        // Customer 0.0100/min, 12/6, D=16 -> billable 18 -> 0.003000
        $cust = $this->r->rate('0.0100', 12, 6, 16);
        $this->assertSame(18, $cust['billable_seconds']);
        $this->assertSame('0.003000', $cust['usage_charge']);
        $this->assertSame('0.003000', $cust['total']);
        // Carrier 0.0060/min, 6/6, D=16 -> billable 18 -> 0.001800
        $carr = $this->r->rate('0.0060', 6, 6, 16);
        $this->assertSame(18, $carr['billable_seconds']);
        $this->assertSame('0.001800', $carr['total']);
        // Margin 0.001200
        $this->assertSame('0.001200', $this->r->margin($cust['total'], $carr['total']));
    }

    public function testConnectionFeeAppliedOnceWhenAnswered(): void
    {
        $x = $this->r->rate('0.0100', 6, 6, 10, '0.05');   // billable 12
        $this->assertSame(12, $x['billable_seconds']);
        $this->assertSame('0.002000', $x['usage_charge']);
        $this->assertSame('0.050000', $x['connect_fee']);
        $this->assertSame('0.052000', $x['total']);
        $this->assertTrue($x['answered']);
    }

    public function testMinimumBillableOnlyWhenAnswered(): void
    {
        $this->assertSame(30, $this->r->billableSeconds(3, 6, 6, 30));
        $this->assertSame(36, $this->r->billableSeconds(31, 6, 6, 30));   // formula result already above the minimum
        $this->assertSame(0, $this->r->billableSeconds(0, 6, 6, 30));
    }

    public function testRoundingIsHalfUpAtSixDecimals(): void
    {
        $this->assertSame('0.000001', $this->r->usageCharge('0.000005', 6));   // exactly 0.0000005
        $this->assertSame('0.000000', $this->r->usageCharge('0.000004', 6));   // 0.0000004
        $this->assertSame('0.001440', $this->r->usageCharge('0.01234567', 7));  // 0.00144032...
    }

    public function testNoFloatErrorsOnClassicBinaryFractions(): void
    {
        $this->assertSame('0.005000', $this->r->usageCharge('0.1', 3));
        // 0.07 * 100 / 60 = 0.116666... -> 0.116667
        $this->assertSame('0.116667', $this->r->usageCharge('0.07', 100));
        // sum of many small charges is exact with decimals
        $sum = '0';
        for ($i = 0; $i < 1000; $i++) {
            $sum = bcadd($sum, $this->r->usageCharge('0.0100', 6), 6);   // 0.001 each
        }
        $this->assertSame('1.000000', $sum);
    }

    public function testLargeValuesStayExact(): void
    {
        $x = $this->r->rate('123.45678901', 60, 60, 7200);   // 120 min
        $this->assertSame(7200, $x['billable_seconds']);
        $this->assertSame('14814.814681', $x['usage_charge']);   // 123.45678901 * 120
    }

    public function testRejectsInvalidInput(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->billableSeconds(5, 0, 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->billableSeconds(5, 6, 0));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->billableSeconds(-1, 6, 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->usageCharge('-0.01', 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->usageCharge('1e3', 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->usageCharge('', 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->r->rate('0.01', 6, 6, 5, 'abc'));
    }

    public function testFloatsAreRejectedByTypes(): void
    {
        // strict_types=1 in this file: a float rate is a TypeError, never silently accepted.
        $this->assertThrows(\TypeError::class, fn () => $this->r->usageCharge(0.01, 6));
    }
}
