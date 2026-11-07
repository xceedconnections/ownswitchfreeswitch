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
            'justUpdated' => (string) $r->query('updated', '') === '1',
        ]);
    }

    public function update(Request $r, array $p = []): Response
    {
        try {
            $svc = new VersionService();
            $res = $svc->updateNow();
            try {
                $this->audit($r, 'system_update', 'system', null, null, ['ok' => $res['ok']]);
            } catch (\Throwable) {
            }
            $msg = $res['ok']
                ? 'Update finished. This page reloads once the new files are active.'
                : ('Update failed: ' . mb_substr($res['detail'], 0, 300));
            $path = $res['ok'] ? '/settings/version?updated=1' : '/settings/version';
            return $this->redirect($r, $path, $msg, $res['ok'] ? 'success' : 'danger');
        } catch (\Throwable $e) {
            error_log('[ownswitch] version update: ' . $e->getMessage());
            return $this->redirect($r, '/settings/version', 'Update failed: ' . mb_substr($e->getMessage(), 0, 300), 'danger');
        }
    }
}
