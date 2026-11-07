<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\SslService;

final class SslController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $svc = new SslService();
        return $this->view('settings/ssl', [
            'title' => 'SSL',
            'status' => $svc->status(),
        ]);
    }

    public function install(Request $r, array $p = []): Response
    {
        $email = trim((string) $r->input('email', ''));
        $domain = trim((string) $r->input('domain', ''));
        $res = (new SslService())->install($email, $domain);
        $this->audit($r, 'ssl_install', 'system', null, null, [
            'ok' => $res['ok'],
            'domain' => $domain,
            'email' => $email,
        ]);
        return $this->redirect($r, '/settings/ssl', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }
}
