<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\SslService;
use Ownswitch\Services\System\WebrtcService;
use Ownswitch\Support\Crypto;

/** Browser dialer for username/password SIP users. */
final class DialerController extends BaseController
{
    public const NO_ACCOUNT = 'You don\'t have a SIP username and password based connection. Please ask the administrator to create one if you want to use Online Web Dialer to make calls.';

    public function index(Request $r, array $p = []): Response
    {
        $accounts = $this->accounts($r);
        $webrtc = ['enabled' => false, 'listening' => false, 'helper' => false];
        try {
            $webrtc = (new WebrtcService())->status();
        } catch (\Throwable) {
        }
        return $this->view('dialer/index', [
            'title' => 'Web-Dialer',
            'accounts' => $accounts,
            'error' => $accounts === [] ? self::NO_ACCOUNT : '',
            'webrtc' => $webrtc,
            'sipDomain' => $this->sipDomain($r),
            'pageScripts' => $accounts === [] ? [] : ['js/jssip.min.js', 'js/dialer.js'],
        ]);
    }

    public function credentials(Request $r, array $p = []): Response
    {
        $id = (int) $r->input('sip_account_id', 0);
        $row = $id > 0 ? $this->account($r, $id) : null;
        if ($row === null) {
            return $this->json(['ok' => false, 'error' => self::NO_ACCOUNT], 403);
        }
        $mode = strtolower(trim((string) ($row['connection_mode'] ?? 'dynamic')));
        if ($mode === '') {
            $mode = 'dynamic';
        }
        $host = strtolower(trim((string) ($row['sip_host'] ?? 'dynamic')));
        if ($host === '') {
            $host = 'dynamic';
        }
        if ($mode !== 'dynamic' || $host !== 'dynamic' || trim((string) ($row['password_enc'] ?? '')) === '') {
            return $this->json(['ok' => false, 'error' => self::NO_ACCOUNT], 403);
        }
        try {
            $password = Crypto::decrypt((string) $row['password_enc']);
        } catch (\Throwable) {
            $password = '';
        }
        if ($password === '') {
            return $this->json([
                'ok' => false,
                'error' => 'This SIP user has no saved password. Ask an administrator to set one, then try again.',
            ], 422);
        }
        $domain = $this->sipDomain($r);
        if ($domain === '') {
            return $this->json([
                'ok' => false,
                'error' => 'No SSL domain is set. Ask an administrator to install a certificate under Settings → SSL.',
            ], 422);
        }
        $webrtc = (new WebrtcService())->status();
        return $this->json([
            'ok' => true,
            'username' => (string) $row['username'],
            'password' => $password,
            'domain' => $domain,
            'ws_url' => 'wss://' . $domain . '/sip-ws',
            'webrtc_enabled' => !empty($webrtc['enabled']) && !empty($webrtc['listening']),
        ]);
    }

    /** Prefer the Let's Encrypt domain from Settings → SSL so a domain change follows the new certificate. */
    private function sipDomain(Request $r): string
    {
        try {
            $ssl = (new SslService())->status();
            $domain = strtolower(trim((string) ($ssl['domain'] ?? '')));
            if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
                return $domain;
            }
        } catch (\Throwable) {
        }
        $conf = '/etc/ownswitch/ssl.conf';
        if (is_readable($conf)) {
            $raw = (string) file_get_contents($conf);
            if (preg_match('/^SSL_DOMAIN=(.+)$/m', $raw, $m)) {
                $domain = strtolower(trim($m[1]));
                if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
                    return $domain;
                }
            }
        }
        $host = strtolower(trim((string) $r->header('host')));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
            return $host;
        }
        return '';
    }

    /** @return list<array{id:int,username:string}> */
    private function accounts(Request $r): array
    {
        $scope = $this->scope($r);
        [$w, $p] = $scope->where('owned', 's');
        try {
            $st = $this->db()->prepare(
                "SELECT s.id, s.username
                 FROM sip_accounts s
                 WHERE s.status = 'active'
                   AND COALESCE(NULLIF(TRIM(s.connection_mode), ''), 'dynamic') = 'dynamic'
                   AND LOWER(TRIM(COALESCE(s.sip_host, 'dynamic'))) IN ('', 'dynamic')
                   AND s.password_enc IS NOT NULL
                   AND TRIM(s.password_enc) <> ''
                   AND ({$w})
                 ORDER BY s.username
                 LIMIT 200"
            );
            $st->execute($p);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'username' => (string) $row['username']];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function account(Request $r, int $id): ?array
    {
        $scope = $this->scope($r);
        [$w, $p] = $scope->where('owned', 's');
        $st = $this->db()->prepare(
            "SELECT s.* FROM sip_accounts s WHERE s.id = ? AND s.status = 'active' AND ({$w}) LIMIT 1"
        );
        $st->execute([$id, ...$p]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->withHeader('Cache-Control', 'no-store');
    }
}
