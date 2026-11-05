<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\CGRateS;

/**
 * Thin JSON-RPC 1.0-over-HTTP client for CGRateS (/jsonrpc). Method names follow the CGRateS v0.10 API
 * naming (CoreSv1.*, APIerSv1.*). VERIFY each method against the CGRateS version you deploy:
 * the portal never assumes an engine can represent a rate; see rateSlots().
 */
final class CgratesClient
{
    public function __construct(
        private string $url,
        private string $user = '',
        private string $password = '',
        private string $tenant = 'cgrates.org',
        private int $timeout = 5,
    ) {
    }

    public function configured(): bool
    {
        return $this->url !== '';
    }

    /** @param array<string,mixed> $params */
    public function call(string $method, array $params = []): mixed
    {
        if (!$this->configured()) {
            throw new \RuntimeException('CGRateS URL is not configured');
        }
        $payload = json_encode(['method' => $method, 'params' => [$params], 'id' => random_int(1, PHP_INT_MAX)], JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json'];
        if ($this->user !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password);
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => $this->timeout, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($this->url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('CGRateS unreachable');
        }
        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            throw new \RuntimeException('Invalid CGRateS response');
        }
        if (!empty($resp['error'])) {
            throw new \RuntimeException('CGRateS error: ' . (is_string($resp['error']) ? $resp['error'] : json_encode($resp['error'])));
        }
        return $resp['result'] ?? null;
    }

    public function ping(): bool
    {
        return $this->call('CoreSv1.Ping', ['Tenant' => $this->tenant]) === 'Pong';
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $r = $this->call('CoreSv1.Status', ['Tenant' => $this->tenant]);
        return is_array($r) ? $r : [];
    }

    /** @return array<string,mixed> */
    public function getAccount(string $account): array
    {
        $r = $this->call('APIerSv2.GetAccount', ['Tenant' => $this->tenant, 'Account' => $account]);
        return is_array($r) ? $r : [];
    }

    /**
     * Map portal (rate, I, S, connect fee) to CGRateS rate slots per BILLING_RULES section 7.
     * Single increment (I == S): one slot. Split increment (I != S): two slots, connect fee on slot 1 only.
     * Throws if the increments cannot be represented (never silently dropped).
     *
     * @return list<array{Rate:string,RateUnit:string,RateIncrement:string,GroupIntervalStart:string,ConnectFee:string}>
     */
    public static function rateSlots(string $ratePerMinute, int $initial, int $subsequent, string $connectFee = '0'): array
    {
        if ($initial <= 0 || $subsequent <= 0) {
            throw new \InvalidArgumentException('Increments must be positive; cannot provision to CGRateS.');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $ratePerMinute) || !preg_match('/^\d+(\.\d+)?$/', $connectFee)) {
            throw new \InvalidArgumentException('Rate and connect fee must be decimal strings.');
        }
        if ($initial === $subsequent) {
            return [['Rate' => $ratePerMinute, 'RateUnit' => '60s', 'RateIncrement' => "{$initial}s", 'GroupIntervalStart' => '0s', 'ConnectFee' => $connectFee]];
        }
        return [
            ['Rate' => $ratePerMinute, 'RateUnit' => '60s', 'RateIncrement' => "{$initial}s", 'GroupIntervalStart' => '0s', 'ConnectFee' => $connectFee],
            ['Rate' => $ratePerMinute, 'RateUnit' => '60s', 'RateIncrement' => "{$subsequent}s", 'GroupIntervalStart' => "{$initial}s", 'ConnectFee' => '0'],
        ];
    }
}
