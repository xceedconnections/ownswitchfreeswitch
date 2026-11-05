<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Services\Settings\SettingsService;
use PDO;

/** Push Settings → Failover SIP codes into OpenSIPS failure_route t_check_status(). */
final class FailoverSipCodesSync
{
    public function __construct(private PDO $pdo, private string $cfgPath = '/etc/opensips/opensips.cfg')
    {
    }

    public function sync(): array
    {
        $codes = (new SettingsService($this->pdo))->get('failover_sip_codes', '408,480,486,503') ?? '408,480,486,503';
        $parts = [];
        foreach (preg_split('/[\s,;|]+/', (string) $codes) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p) && (int) $p >= 100 && (int) $p <= 699) {
                $parts[] = $p;
            }
        }
        if ($parts === []) {
            $parts = ['408', '480', '486', '503'];
        }
        $status = implode('|', array_unique($parts));
        // OpenSIPS t_check_status expects a regex like "408|480|503"
        $pattern = $status;

        if (!is_readable($this->cfgPath) || !is_writable($this->cfgPath)) {
            return ['ok' => false, 'detail' => 'opensips.cfg not writable', 'pattern' => $pattern];
        }
        $text = (string) file_get_contents($this->cfgPath);
        $replacement = 'if (!t_check_status("' . $pattern . '"))';
        if (preg_match('/if\s*\(\s*!t_check_status\("[^"]*"\)\s*\)/', $text)) {
            $text = preg_replace('/if\s*\(\s*!t_check_status\("[^"]*"\)\s*\)/', $replacement, $text, 1) ?? $text;
        } elseif (str_contains($text, '@@FAILOVER_SIP_STATUS@@')) {
            $text = str_replace('@@FAILOVER_SIP_STATUS@@', $pattern, $text);
        } elseif (str_contains($text, 'failure_route[carrier_failover]')) {
            // Insert check after t_was_cancelled block if missing
            $text = preg_replace(
                '/(failure_route\[carrier_failover\]\s*\{[^}]*t_was_cancelled\(\);\s*exit;\s*\}\s*)/s',
                '$1' . "\n\t{$replacement} {\n\t\txlog(\"L_INFO\", \"failover skip: reply \$T_reply_code\\n\");\n\t\trtpengine_delete();\n\t\texit;\n\t}\n",
                $text,
                1
            ) ?? $text;
        } else {
            return ['ok' => false, 'detail' => 'failure_route not found', 'pattern' => $pattern];
        }
        file_put_contents($this->cfgPath, $text);
        return ['ok' => true, 'detail' => 'updated', 'pattern' => $pattern];
    }
}
