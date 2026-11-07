<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
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
            'title' => 'Dialer',
            'accounts' => $accounts,
            'error' => $accounts === [] ? self::NO_ACCOUNT : '',
            'webrtc' => $webrtc,
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
        $mode = strtolower((string) ($row['connection_mode'] ?? ''));
        if ($mode === 'ip' || trim((string) ($row['password_enc'] ?? '')) === '') {
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
        $host = strtolower(trim((string) $r->header('host')));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return $this->json(['ok' => false, 'error' => 'Could not determine this site hostname.'], 500);
        }
        $webrtc = (new WebrtcService())->status();
        return $this->json([
            'ok' => true,
            'username' => (string) $row['username'],
            'password' => $password,
            'domain' => $host,
            'ws_url' => 'wss://' . $host . '/sip-ws',
            'webrtc_enabled' => !empty($webrtc['enabled']),
        ]);
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
                   AND s.connection_mode = 'dynamic'
                   AND s.password_enc IS NOT NULL
                   AND s.password_enc <> ''
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
