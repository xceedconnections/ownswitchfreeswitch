<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Integrations;

use Ownswitch\Database\Migrator;
use Ownswitch\Integrations\CGRateS\CgratesClient;
use Ownswitch\Integrations\RTPengine\RtpengineClient;
use Ownswitch\Repositories\IpEndpointRepository;
use Ownswitch\Services\Media\MediaPolicyService;
use Ownswitch\Services\Media\RecordingService;
use Ownswitch\Support\Csv;
use Ownswitch\Support\Destination;
use Ownswitch\Tests\TestCase;

/** Pure-logic checks for helpers and integration payload builders (no network, no MySQL). */
final class SupportAndIntegrationsTest extends TestCase
{
    public function testBencodeRoundTrip(): void
    {
        $data = ['command' => 'ping', 'n' => 42, 'list' => ['a', 'bc'], 'flags' => ['trust-address']];
        $enc = RtpengineClient::bencode($data);
        $this->assertSame($data['command'], RtpengineClient::bdecode($enc)['command']);
        $this->assertSame(42, RtpengineClient::bdecode($enc)['n']);
        $this->assertSame('i-3e', RtpengineClient::bencode(-3));
        $this->assertSame('0:', RtpengineClient::bencode(''));
    }

    public function testCgratesRateSlotsFollowBillingRulesSection7(): void
    {
        $one = CgratesClient::rateSlots('0.0100', 6, 6, '0.02');
        $this->assertCount(1, $one);
        $this->assertSame('6s', $one[0]['RateIncrement']);
        $this->assertSame('0.02', $one[0]['ConnectFee']);

        $two = CgratesClient::rateSlots('0.0100', 12, 6, '0.02');
        $this->assertCount(2, $two);
        $this->assertSame(['0s', '12s'], [$two[0]['GroupIntervalStart'], $two[1]['GroupIntervalStart']]);
        $this->assertSame(['12s', '6s'], [$two[0]['RateIncrement'], $two[1]['RateIncrement']]);
        $this->assertSame(['0.02', '0'], [$two[0]['ConnectFee'], $two[1]['ConnectFee']], 'connect fee only once');

        $this->assertThrows(\InvalidArgumentException::class, fn () => CgratesClient::rateSlots('0.01', 0, 6));
        $this->assertThrows(\InvalidArgumentException::class, fn () => CgratesClient::rateSlots('abc', 6, 6));
    }

    public function testMediaPolicyValidation(): void
    {
        $this->assertTrue(MediaPolicyService::valid('relay'));
        $this->assertFalse(MediaPolicyService::valid('totally-bogus'));
        $this->assertFalse(MediaPolicyService::valid(''));
    }

    public function testDestinationNormalizationAndPrefixCandidates(): void
    {
        $this->assertSame('442071234567', Destination::normalize('+44 (20) 7123-4567'));
        $this->assertSame('442071234567', Destination::normalize('00442071234567'));
        $this->assertSame('', Destination::normalize('abc'));
        $this->assertSame(['4420', '442', '44', '4'], Destination::candidates('4420'));
        $this->assertSame(['123', '12', '1'], Destination::candidates('123456789012345678', 3));
        $this->assertSame('?,?,?', Destination::placeholders(['a', 'b', 'c']));
    }

    public function testCidrMatching(): void
    {
        $this->assertTrue(IpEndpointRepository::ipInCidr('203.0.113.77', '203.0.113.0', 24));
        $this->assertFalse(IpEndpointRepository::ipInCidr('203.0.114.1', '203.0.113.0', 24));
        $this->assertTrue(IpEndpointRepository::ipInCidr('203.0.113.5', '203.0.113.5', 32));
        $this->assertFalse(IpEndpointRepository::ipInCidr('203.0.113.6', '203.0.113.5', 32));
        $this->assertFalse(IpEndpointRepository::ipInCidr('2001:db8::1', '203.0.113.0', 24), 'family mismatch');
    }

    public function testCsvFormulaInjectionGuard(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", Csv::cell('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(1)", Csv::cell('@SUM(1)'));
        $this->assertSame('-0.5', Csv::cell('-0.5'));
        $this->assertSame('hello', Csv::cell('hello'));
        $this->assertSame('', Csv::cell(null));
    }

    public function testMigratorSplitsStatementsSafely(): void
    {
        $sql = "-- comment; with semicolon\nCREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b'); /* c; */\nINSERT INTO a VALUES ('it''s;ok');\n";
        $parts = Migrator::splitStatements($sql);
        $this->assertCount(2, $parts);
        $this->assertStringContains("'a;b'", $parts[0]);
        $this->assertStringContains("'it''s;ok'", $parts[1]);
    }

    public function testRecordingPathTraversalIsBlocked(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ownswitch_rec_' . bin2hex(random_bytes(4));
        mkdir($base . DIRECTORY_SEPARATOR . 'sub', 0777, true);
        file_put_contents($base . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'ok.wav', 'x');
        file_put_contents(dirname($base) . DIRECTORY_SEPARATOR . 'ownswitch_outside.txt', 'secret');
        try {
            $svc = new RecordingService(new \PDO('sqlite::memory:'), $base);
            $this->assertNotNull($svc->resolvePath('sub/ok.wav'));
            $this->assertNull($svc->resolvePath('../ownswitch_outside.txt'));
            $this->assertNull($svc->resolvePath('sub/../../ownswitch_outside.txt'));
            $this->assertNull($svc->resolvePath('..\\ownswitch_outside.txt'));
            $this->assertNull($svc->resolvePath(dirname($base) . DIRECTORY_SEPARATOR . 'ownswitch_outside.txt'), 'absolute path');
            $this->assertNull($svc->resolvePath("sub/ok.wav\0.txt"));
            $this->assertNull($svc->resolvePath('missing.wav'));
        } finally {
            @unlink($base . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'ok.wav');
            @rmdir($base . DIRECTORY_SEPARATOR . 'sub');
            @rmdir($base);
            @unlink(dirname($base) . DIRECTORY_SEPARATOR . 'ownswitch_outside.txt');
        }
    }
}
