<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\HealthService;

final class SystemController extends BaseController
{
    public function status(Request $r, array $p = []): Response
    {
        $checks = (new HealthService())->run($this->db());
        $counts = [];
        foreach (['users', 'customers', 'cdrs', 'ledger_entries', 'audit_logs'] as $t) {
            $counts[$t] = (int) $this->db()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();   // fixed table names
        }
        $sipDomain = (string) \Ownswitch\App::config('app.integrations.opensips.sip_domain', '');
        $sipTip = 'Softphone: Domain=' . ($sipDomain !== '' ? $sipDomain : 'portal SIP domain')
            . ' · Port=5060 · Transport=UDP. If phones cannot register, on the server run: '
            . 'sudo bash /opt/ownswitch-src/deploy/fix-udp-5060.sh '
            . '(opens host FW + FreeSWITCH SIP/RTP; with /etc/ownswitch/hcloud.token also opens Hetzner Cloud Firewall). '
            . 'Magnus IP-peer trunks never show Registered — only Register-mode user/pass do.';
        return $this->view('system/status', [
            'title' => 'System Status',
            'checks' => $checks,
            'counts' => $counts,
            'php' => PHP_VERSION,
            'time' => gmdate('Y-m-d H:i:s') . ' UTC',
            'sipTip' => $sipTip,
        ]);
    }
}
