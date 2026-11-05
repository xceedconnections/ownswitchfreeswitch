<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Switching\FreeswitchCallFlow;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\MediaPolicy;

/** FreeSWITCH mod_xml_curl + mod_xml_cdr. Loopback nginx only. */
final class FreeswitchXmlController
{
    public function xml(Request $r, array $p = []): Response
    {
        if (!$this->authorized($r)) {
            return Response::text('forbidden', 403);
        }
        $section = strtolower(trim((string) $r->input('section', '')));
        if ($section === '') {
            $section = str_contains($r->path, 'directory') ? 'directory' : (str_contains($r->path, 'dialplan') ? 'dialplan' : '');
        }
        $body = match ($section) {
            'directory' => $this->directory($r),
            'dialplan' => (new FreeswitchCallFlow(App::db()))->dialplan($r),
            default => $this->notFound(),
        };
        return new Response($body, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function cdr(Request $r, array $p = []): Response
    {
        if (!$this->authorized($r)) {
            return Response::text('forbidden', 403);
        }
        $raw = (string) file_get_contents('php://input');
        if ($raw === '' && isset($r->post['cdr'])) {
            $raw = (string) $r->post['cdr'];
        }
        if ($raw !== '' && !str_contains($raw, '<cdr')) {
            $parsed = [];
            parse_str($raw, $parsed);
            if (isset($parsed['cdr']) && is_string($parsed['cdr'])) {
                $raw = $parsed['cdr'];
            }
        }
        try {
            (new FreeswitchCallFlow(App::db()))->ingestCdr($raw);
        } catch (\Throwable $e) {
            error_log('[ownswitch] fs cdr: ' . $e->getMessage());
            return Response::text('error', 500);
        }
        return Response::text('ok');
    }

    private function directory(Request $r): string
    {
        $user = trim((string) ($r->input('user', $r->input('sip_auth_username', ''))));
        $domain = trim((string) ($r->input('domain', $r->input('sip_auth_realm', ''))));
        if ($user === '' || $domain === '') {
            return $this->notFound();
        }
        $st = App::db()->prepare(
            "SELECT * FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$user]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->notFound();
        }
        $mode = strtolower((string) ($row['connection_mode'] ?? 'dynamic'));
        $host = trim((string) ($row['sip_host'] ?? ''));
        $params = '';
        if ($mode === 'ip' && filter_var($host, FILTER_VALIDATE_IP)) {
            $params .= '<param name="cidr" value="' . $this->x($host) . '/32"/>'
                . '<param name="allow-empty-password" value="true"/>';
        } else {
            // Challenge realm is whatever the phone sent (public IP or portal hostname).
            $ha1 = '';
            if (!empty($row['password_enc'])) {
                try {
                    $plain = Crypto::decrypt((string) $row['password_enc']);
                    $ha1 = md5($user . ':' . $domain . ':' . $plain);
                } catch (\Throwable) {
                    $ha1 = '';
                }
            }
            if ($ha1 === '') {
                $ha1 = (string) ($row['ha1'] ?? '');
            }
            if ($ha1 === '') {
                return $this->notFound();
            }
            $params .= '<param name="a1-hash" value="' . $this->x($ha1) . '"/>';
        }
        $media = MediaPolicy::resolve(
            isset($row['media_policy']) ? (string) $row['media_policy'] : null,
            App::db()
        );
        $bypass = $media === 'direct' ? 'true' : 'false';
        $xmlUser = '<user id="' . $this->x($user) . '">'
            . '<params>' . $params . '<param name="dial-string" value="{presence_id=' . $this->x($user) . '@${dialed_domain}}${sofia_contact(${dialed_user}@${dialed_domain})}"/>'
            . '</params>'
            . '<variables>'
            . '<variable name="user_context" value="ownswitch"/>'
            . '<variable name="os_sip_user" value="' . $this->x($user) . '"/>'
            . '<variable name="os_media_policy" value="' . $this->x($media) . '"/>'
            . '<variable name="bypass_media" value="' . $bypass . '"/>'
            . '</variables></user>';
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<document type="freeswitch/xml"><section name="directory">'
            . '<domain name="' . $this->x($domain) . '">'
            . '<params><param name="dial-string" value="{presence_id=${dialed_user}@${dialed_domain}}${sofia_contact(${dialed_user}@${dialed_domain})}"/></params>'
            . '<groups><group name="default"><users>' . $xmlUser . '</users></group></groups>'
            . '</domain></section></document>';
    }

    private function authorized(Request $r): bool
    {
        $want = (string) App::config('app.integrations.freeswitch.xml_secret', '');
        if ($want === '') {
            return $r->ip() === '127.0.0.1';
        }
        $got = (string) ($r->query['key'] ?? $r->input('key', ''));
        return hash_equals($want, $got);
    }

    private function notFound(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<document type="freeswitch/xml"><section name="result"><result status="not found"/></section></document>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
