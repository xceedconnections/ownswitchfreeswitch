<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Reports;

use Ownswitch\Services\Reports\AsrAcdService;
use Ownswitch\Tests\TestCase;

final class AsrAcdTest extends TestCase
{
    public function testBasicAsrAcd(): void
    {
        $r = AsrAcdService::compute(100, 50, 6000);
        $this->assertSame('50.00', $r['asr']);
        $this->assertSame('120.0', $r['acd']);
        $r = AsrAcdService::compute(3, 2, 100);
        $this->assertSame('66.67', $r['asr']);
        $this->assertSame('50.0', $r['acd']);
    }

    public function testZeroDenominatorsNeverDivideByZero(): void
    {
        $this->assertSame(['0.00', '0.0'], [AsrAcdService::compute(0, 0, 0)['asr'], AsrAcdService::compute(0, 0, 0)['acd']]);
        $r = AsrAcdService::compute(10, 0, 0);
        $this->assertSame('0.00', $r['asr']);
        $this->assertSame('0.0', $r['acd']);
    }

    public function testHundredPercentAndRounding(): void
    {
        $this->assertSame('100.00', AsrAcdService::compute(7, 7, 70)['asr']);
        $this->assertSame('14.29', AsrAcdService::compute(7, 1, 1)['asr']);     // 14.2857..
        $this->assertSame('33.33', AsrAcdService::compute(3, 1, 1)['asr']);
        $this->assertSame('3.5', AsrAcdService::compute(10, 2, 7)['acd']);
        $this->assertSame('0.3', AsrAcdService::compute(10, 3, 1)['acd']);      // 0.333..
    }

    public function testAggregateSumsCountersInsteadOfAveragingPercentages(): void
    {
        $rows = [
            ['attempts' => 100, 'answered' => 50, 'duration_seconds' => 5000],   // 50%
            ['attempts' => 10, 'answered' => 10, 'duration_seconds' => 100],     // 100%
        ];
        $r = AsrAcdService::aggregate($rows);
        $this->assertSame(110, $r['attempts']);
        $this->assertSame(60, $r['answered']);
        $this->assertSame('54.55', $r['asr']);           // NOT the 75.00 average of 50 and 100
        $this->assertSame('85.0', $r['acd']);            // 5100/60
        $this->assertSame([], array_diff(['attempts', 'answered', 'seconds', 'asr', 'acd'], array_keys($r)));
    }

    public function testAggregateAcceptsStringsFromPdoAndEmptyInput(): void
    {
        $r = AsrAcdService::aggregate([['attempts' => '4', 'answered' => '1', 'duration_seconds' => '30']]);
        $this->assertSame('25.00', $r['asr']);
        $this->assertSame('30.0', $r['acd']);
        $this->assertSame('0.00', AsrAcdService::aggregate([])['asr']);
    }

    public function testRollupWeekUsesIsoWeeksAndSumsCounters(): void
    {
        $daily = [
            ['bucket_date' => '2025-12-31', 'attempts' => 10, 'answered' => 5, 'duration_seconds' => 500],   // ISO 2026-W01
            ['bucket_date' => '2026-01-04', 'attempts' => 10, 'answered' => 10, 'duration_seconds' => 1000],  // Sunday, still W01
            ['bucket_date' => '2026-01-05', 'attempts' => 20, 'answered' => 4, 'duration_seconds' => 80],     // Monday W02
        ];
        $w = AsrAcdService::rollupPeriods($daily, 'week');
        $this->assertCount(2, $w);
        $this->assertSame('2026-W01', $w[0]['period']);
        $this->assertSame(20, $w[0]['attempts']);
        $this->assertSame('75.00', $w[0]['asr']);
        $this->assertSame('100.0', $w[0]['acd']);
        $this->assertSame('2026-W02', $w[1]['period']);
        $this->assertSame('20.00', $w[1]['asr']);
    }

    public function testRollupMonth(): void
    {
        $daily = [
            ['bucket_date' => '2026-02-28', 'attempts' => 10, 'answered' => 5, 'duration_seconds' => 50],
            ['bucket_date' => '2026-03-01', 'attempts' => 30, 'answered' => 30, 'duration_seconds' => 300],
            ['bucket_date' => '2026-03-02', 'attempts' => 20, 'answered' => 0, 'duration_seconds' => 0],
        ];
        $m = AsrAcdService::rollupPeriods($daily, 'month');
        $this->assertSame(['2026-02', '2026-03'], array_column($m, 'period'));
        $this->assertSame('50.00', $m[0]['asr']);
        $this->assertSame('60.00', $m[1]['asr']);        // 30/50, not mean(100, 0)
        $this->assertSame('10.0', $m[1]['acd']);
    }
}
