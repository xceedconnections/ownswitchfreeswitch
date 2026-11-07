<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

final class WebrtcService
{
    private const BIN = '/usr/local/sbin/ownswitch-webrtc';

    /** @return array{ok:bool,detail:string,enabled:bool,listening:bool,helper:bool} */
    public function status(): array
    {
        $res = SudoHelper::run(self::BIN, ['status']);
        $f = $res['fields'];
        return [
            'ok' => $res['ok'],
            'detail' => $res['detail'],
            'enabled' => ($f['enabled'] ?? '') === '1',
            'listening' => ($f['listening'] ?? '') === '1',
            'helper' => is_file(self::BIN),
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function enable(): array
    {
        $res = SudoHelper::run(self::BIN, ['enable']);
        return ['ok' => $res['ok'], 'detail' => $res['detail']];
    }
}
