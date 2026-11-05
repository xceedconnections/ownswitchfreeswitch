<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Billing;

use Ownswitch\Services\Billing\BillingPreviewService;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Tests\TestCase;

final class PrepaidAndPreviewTest extends TestCase
{
    private function prepaid(): PrepaidService
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->skip('pdo_sqlite not loaded');
        }
        $pdo = new \PDO('sqlite::memory:');   // maxDuration() is pure; the PDO is never queried
        return new PrepaidService($pdo, new LedgerService($pdo), new RatingService());
    }

    public function testMaxDurationFitsBudgetAndAlignsToIncrements(): void
    {
        $p = $this->prepaid();
        // 0.06/min = 0.001/s ; 1.00 budget => 1000s ; 6/6 aligned => 996 (charge 0.996)
        $this->assertSame(996, $p->maxDuration('1.000000', '0.0600', 6, 6));
        // 12/6: budget 0.1 => 100s => 12 + floor(88/6)*6 = 96
        $this->assertSame(96, $p->maxDuration('0.100000', '0.0600', 12, 6));
    }

    public function testMaxDurationRejectsWhenInitialBlockUnaffordable(): void
    {
        $p = $this->prepaid();
        $this->assertSame(0, $p->maxDuration('0.005000', '0.0600', 6, 6));   // only 5s affordable < I
        $this->assertSame(0, $p->maxDuration('0.400000', '0.0600', 6, 6, '0.5'));   // connect fee alone exceeds balance
        $this->assertSame(0, $p->maxDuration('0', '0.0600', 6, 6));
    }

    public function testMaxDurationHonoursHardCapAndZeroRate(): void
    {
        $p = $this->prepaid();
        $this->assertSame(7200, $p->maxDuration('1000', '0.0600', 6, 6));
        $this->assertSame(7200, $p->maxDuration('1', '0', 6, 6));
        $this->assertSame(3600, $p->maxDuration('1000', '0.0600', 60, 60, '0', 3600));
    }

    public function testAuthorizedMaxNeverExceedsAvailable(): void
    {
        $p = $this->prepaid();
        $rating = new RatingService();
        foreach (['0.01', '0.123', '1.5', '7.777'] as $avail) {
            foreach ([['0.0123', 6, 6], ['0.05', 12, 6], ['0.0333', 30, 6]] as [$rate, $i, $s]) {
                $b = $p->maxDuration($avail, $rate, $i, $s, '0.001');
                if ($b > 0) {
                    $total = $rating->rate($rate, $i, $s, $b, '0.001')['total'];
                    $this->assertTrue(bccomp($total, $avail, 6) <= 0, "charge {$total} must not exceed {$avail}");
                }
            }
        }
    }

    public function testBillingPreviewWorkedExample(): void
    {
        $out = (new BillingPreviewService())->preview([
            'rate' => '0.0100', 'initial' => '12', 'subsequent' => '6', 'duration' => '16', 'connect_fee' => '0',
            'carrier_rate' => '0.0060', 'carrier_initial' => '6', 'carrier_subsequent' => '6', 'carrier_connect_fee' => '0',
        ]);
        $this->assertSame([], $out['errors']);
        $this->assertSame(18, $out['result']['customer']['billable_seconds']);
        $this->assertSame('0.003000', $out['result']['customer']['total']);
        $this->assertSame('0.001800', $out['result']['carrier']['total']);
        $this->assertSame('0.001200', $out['result']['margin']);
        $this->assertContains('6/6', array_keys($out['result']['comparison']));
        $this->assertContains('12/6', array_keys($out['result']['comparison']));
        $this->assertContains('custom 12/6', array_keys($out['result']['comparison']));
        $this->assertSame(18, $out['result']['comparison']['6/6']['billable_seconds']);   // D=16 at 6/6 -> 18
    }

    public function testBillingPreviewValidatesInput(): void
    {
        $out = (new BillingPreviewService())->preview(['rate' => 'abc', 'initial' => '0', 'subsequent' => '6', 'duration' => '-1']);
        $this->assertNull($out['result']);
        $this->assertTrue(isset($out['errors']['rate']) && isset($out['errors']['initial']) && isset($out['errors']['duration']));
    }
}
