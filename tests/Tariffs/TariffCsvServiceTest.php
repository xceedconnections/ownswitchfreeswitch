<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Tariffs;

use Ownswitch\Services\Tariffs\TariffCsvService;
use Ownswitch\Tests\TestCase;
use PDO;

final class TariffCsvServiceTest extends TestCase
{
    private function svc(): TariffCsvService
    {
        return new TariffCsvService(new PDO('sqlite::memory:'));
    }

    public function test_rejects_negative_and_duplicate_prefixes(): void
    {
        $csv = "prefix,destination,rate,initial_increment,subsequent_increment,connect_fee,status,effective_from,effective_to\n"
            . "1,Test,-0.01,6,6,0,active,,\n"
            . "1,Dup,0.01,6,6,0,active,,\n";
        $p = $this->svc()->preview('customer', 1, $csv);
        $this->assertFalse($p['ok']);
        $this->assertTrue(count($p['errors']) > 0);
    }

    public function test_accepts_12_6_customer_rows(): void
    {
        $csv = "prefix,destination,rate,initial_increment,subsequent_increment,connect_fee,status,effective_from,effective_to\n"
            . "44,UK,0.0100,12,6,0,active,,\n"
            . "91,India,0.0080,6,6,0,active,,\n";
        $p = $this->svc()->preview('customer', 1, $csv);
        $this->assertTrue($p['ok'], implode('; ', $p['errors']));
        $this->assertSame(2, count($p['rows']));
        $this->assertSame('12', (string) $p['rows'][0]['initial_increment']);
        $this->assertSame('6', (string) $p['rows'][0]['subsequent_increment']);
    }

    public function test_export_headers_roundtrip_shape(): void
    {
        $this->assertTrue(in_array('prefix', TariffCsvService::HEADERS, true));
        $this->assertTrue(in_array('initial_increment', TariffCsvService::HEADERS, true));
        $this->assertTrue(in_array('subsequent_increment', TariffCsvService::HEADERS, true));
    }
}
