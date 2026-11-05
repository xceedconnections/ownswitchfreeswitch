<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use PDO;

/** Push Settings → prepaid_hard_cap_seconds into OpenSIPS dialog default_timeout. */
final class DialogTimeoutSync
{
    public function __construct(private PDO $pdo, private string $cfgPath = '/etc/opensips/opensips.cfg')
    {
    }

    /** @return array{ok:bool,detail:string,seconds:int} */
    public function sync(): array
    {
        $seconds = (new MaxCallDuration($this->pdo))->systemDefault();
        if (!is_readable($this->cfgPath) || !is_writable($this->cfgPath)) {
            return ['ok' => false, 'detail' => 'opensips.cfg not writable', 'seconds' => $seconds];
        }
        $text = (string) file_get_contents($this->cfgPath);
        $new = 'modparam("dialog", "default_timeout", ' . $seconds . ')';
        if (preg_match('/modparam\s*\(\s*"dialog"\s*,\s*"default_timeout"\s*,\s*\d+\s*\)/', $text)) {
            $text = preg_replace(
                '/modparam\s*\(\s*"dialog"\s*,\s*"default_timeout"\s*,\s*\d+\s*\)/',
                $new,
                $text,
                1
            ) ?? $text;
        } else {
            return ['ok' => false, 'detail' => 'default_timeout not found in opensips.cfg', 'seconds' => $seconds];
        }
        file_put_contents($this->cfgPath, $text);
        return ['ok' => true, 'detail' => 'updated', 'seconds' => $seconds];
    }
}
