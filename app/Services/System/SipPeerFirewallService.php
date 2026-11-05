<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Sync OS firewall allows for IP-based SIP peers (firewalld or ufw via helper).
 */
final class SipPeerFirewallService
{
    private const HELPER = '/usr/local/sbin/ownswitch-sip-peer-fw';

    public function available(): bool
    {
        return is_file(self::HELPER) && is_executable(self::HELPER);
    }

    /**
     * Allow peer IP (+ SIP listen / peer port) for this SIP username.
     *
     * @return array{ok:bool,detail:string}
     */
    public function sync(string $username, string $ip, int $peerPort): array
    {
        return $this->syncMany($username, [$ip], $peerPort);
    }

    /**
     * Allow multiple peer IPs for one SIP username.
     *
     * @param list<string> $ips
     * @return array{ok:bool,detail:string}
     */
    public function syncMany(string $username, array $ips, int $peerPort): array
    {
        $username = trim($username);
        $clean = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $clean[] = $ip;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($username === '' || $clean === []) {
            return ['ok' => false, 'detail' => 'Invalid username or IP list'];
        }
        if ($peerPort < 0 || $peerPort > 65535) {
            $peerPort = 5060;
        }
        return $this->run(['sync', $username, implode(',', $clean), (string) $peerPort]);
    }

    /** @return array{ok:bool,detail:string} */
    public function remove(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['ok' => false, 'detail' => 'Invalid username'];
        }
        return $this->run(['remove', $username]);
    }

    /**
     * @param list<string> $args
     * @return array{ok:bool,detail:string}
     */
    private function run(array $args): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'detail' => 'Firewall helper missing (run refresh-ui / install ownswitch-sip-peer-fw)'];
        }
        $cmd = 'sudo -n ' . escapeshellarg(self::HELPER);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = trim(implode("\n", $out));
        return ['ok' => $code === 0, 'detail' => $text !== '' ? $text : ($code === 0 ? 'ok' : 'firewall sync failed')];
    }
}
