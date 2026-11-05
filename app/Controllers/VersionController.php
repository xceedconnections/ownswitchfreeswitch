<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\VersionService;

final class VersionController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $svc = new VersionService();
        return $this->view('settings/version', [
            'title' => 'Version',
            'status' => $svc->status(),
        ]);
    }

    public function update(Request $r, array $p = []): Response
    {
        $svc = new VersionService();
        $res = $svc->updateNow();
        $this->audit($r, 'system_update', 'system', null, null, ['ok' => $res['ok']]);
        $msg = $res['ok']
            ? 'Update finished. Hard-refresh the browser (Ctrl+Shift+R).'
            : ('Update failed: ' . mb_substr($res['detail'], 0, 300));
        return $this->redirect($r, '/settings/version', $msg, $res['ok'] ? 'success' : 'danger');
    }
}
