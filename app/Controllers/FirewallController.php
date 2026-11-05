<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\Fail2banService;

/** Settings → Firewall: SIP + portal fail2ban bans + thresholds. */
final class FirewallController extends BaseController
{
    private const PER_PAGE = 10;

    public function index(Request $r, array $p = []): Response
    {
        $svc = new Fail2banService();
        $status = $svc->status(false);
        $q = trim((string) $r->query('q', ''));
        $entries = $status['ban_entries'] ?? [];
        if ($entries === [] && !empty($status['banned'])) {
            foreach ($status['banned'] as $ip) {
                $entries[] = ['ip' => (string) $ip, 'jail' => Fail2banService::JAIL_SIP, 'label' => 'SIP auth'];
            }
        }
        if ($q !== '') {
            $needle = strtolower($q);
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e): bool => str_contains(strtolower((string) $e['ip']), $needle)
                    || str_contains(strtolower((string) ($e['label'] ?? '')), $needle)
                    || str_contains(strtolower((string) ($e['jail'] ?? '')), $needle)
            ));
        }
        $total = count($entries);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) $r->query('page', 1)));
        $slice = array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $details = [];
        foreach ($slice as $e) {
            $ip = (string) $e['ip'];
            $jail = (string) ($e['jail'] ?? Fail2banService::JAIL_SIP);
            $details[$ip . '|' . $jail] = $svc->banDetail($ip, $jail);
        }
        $status['ban_entries'] = $slice;
        $status['ban_details'] = $details;

        $query = [];
        if ($q !== '') {
            $query['q'] = $q;
        }

        return $this->view('settings/firewall', [
            'title' => 'Firewall',
            'status' => $status,
            'available' => $svc->available(),
            'helperPath' => '/usr/local/sbin/ownswitch-fail2ban',
            'q' => $q,
            'query' => $query,
            'result' => [
                'rows' => $slice,
                'total' => $total,
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'pages' => $pages,
            ],
        ]);
    }

    public function unban(Request $r, array $p = []): Response
    {
        $ip = trim((string) $r->input('ip', ''));
        $jail = trim((string) $r->input('jail', ''));
        $res = (new Fail2banService())->unban($ip, $jail !== '' ? $jail : null);
        $this->audit($r, 'firewall_unban', 'firewall', null, null, ['ip' => $ip, 'jail' => $jail, 'ok' => $res['ok']]);
        return $this->redirect($r, $this->firewallReturnPath($r), $res['detail'], $res['ok'] ? 'success' : 'danger');
    }

    public function ban(Request $r, array $p = []): Response
    {
        $ip = trim((string) $r->input('ip', ''));
        $jail = trim((string) $r->input('jail', Fail2banService::JAIL_SIP));
        $res = (new Fail2banService())->ban($ip, $jail);
        $this->audit($r, 'firewall_ban', 'firewall', null, null, ['ip' => $ip, 'jail' => $jail, 'ok' => $res['ok']]);
        return $this->redirect($r, '/settings/firewall', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }

    public function saveConfig(Request $r, array $p = []): Response
    {
        $jail = trim((string) $r->input('jail', Fail2banService::JAIL_SIP));
        $cfg = [
            'jail' => $jail,
            'maxretry' => trim((string) $r->input('maxretry', '')),
            'findtime' => trim((string) $r->input('findtime', '')),
            'bantime' => trim((string) $r->input('bantime', '')),
            'enabled' => $r->input('jail_enabled') ? 'true' : 'false',
        ];
        $res = (new Fail2banService())->setConfig($cfg);
        $this->audit($r, 'firewall_config', 'firewall', null, null, $cfg + ['ok' => $res['ok']]);
        return $this->redirect($r, '/settings/firewall', $res['detail'] !== '' ? $res['detail'] : ($res['ok'] ? 'Saved.' : 'Save failed'), $res['ok'] ? 'success' : 'danger');
    }

    private function firewallReturnPath(Request $r): string
    {
        $q = [];
        $search = trim((string) $r->input('return_q', $r->query('q', '')));
        $page = (int) $r->input('return_page', $r->query('page', 1));
        if ($search !== '') {
            $q['q'] = $search;
        }
        if ($page > 1) {
            $q['page'] = (string) $page;
        }
        return '/settings/firewall' . ($q !== [] ? ('?' . http_build_query($q)) : '');
    }
}
