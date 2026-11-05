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
        $username = trim($username);
        $ip = trim($ip);
        if ($username === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detail' => 'Invalid username or IP'];
        }
        if ($peerPort < 0 || $peerPort > 65535) {
            $peerPort = 5060;
        }
        return $this->run(['sync', $username, $ip, (string) $peerPort]);
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
