<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

final class SslService
{
    private const BIN = '/usr/local/sbin/ownswitch-ssl';

    /** @return array{ok:bool,detail:string,installed:bool,domain:string,email:string,not_after:string,issuer:string,subject:string,helper:bool} */
    public function status(): array
    {
        $res = SudoHelper::run(self::BIN, ['status']);
        $f = $res['fields'];
        return [
            'ok' => $res['ok'],
            'detail' => $res['detail'],
            'installed' => ($f['installed'] ?? '') === '1',
            'domain' => (string) ($f['domain'] ?? ''),
            'email' => (string) ($f['email'] ?? ''),
            'not_after' => (string) ($f['not_after'] ?? ''),
            'issuer' => (string) ($f['issuer'] ?? ''),
            'subject' => (string) ($f['subject'] ?? ''),
            'helper' => is_file(self::BIN),
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function install(string $email, string $domain): array
    {
        $email = trim($email);
        $domain = strtolower(trim($domain));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'detail' => 'Enter a valid email address.'];
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
            return ['ok' => false, 'detail' => 'Enter the domain name that points at this server.'];
        }
        $res = SudoHelper::run(self::BIN, ['install', $email, $domain]);
        return ['ok' => $res['ok'], 'detail' => $res['detail']];
    }
}
