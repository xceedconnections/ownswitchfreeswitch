<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\WebrtcService;

final class WebrtcSettingsController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        return $this->view('settings/webrtc', [
            'title' => 'WebRTC',
            'status' => (new WebrtcService())->status(),
        ]);
    }

    public function enable(Request $r, array $p = []): Response
    {
        $res = (new WebrtcService())->enable();
        $this->audit($r, 'webrtc_enable', 'system', null, null, ['ok' => $res['ok']]);
        return $this->redirect($r, '/settings/webrtc', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }
}
