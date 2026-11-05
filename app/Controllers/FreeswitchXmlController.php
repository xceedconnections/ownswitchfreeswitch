<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Switching\FreeswitchCallFlow;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\MediaPolicy;
use Ownswitch\Support\SipHosts;

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
        $user = trim((string) ($r->input('user', $r->input('sip_auth_username', $r->input('Hunt-Username', '')))));
        $domain = trim((string) ($r->input('domain', $r->input('sip_auth_realm', ''))));
        // FreeSWITCH often sends domain as key_value (tag_name=domain, key_name=name).
        if ($domain === '') {
            $domain = trim((string) $r->input('key_value', ''));
        }
        // xml_locate "user id X" may put the username in key_value when key_name=id.
        if ($user === '' && strtolower((string) $r->input('key_name', '')) === 'id') {
            $user = trim((string) $r->input('key_value', ''));
        }
        if ($domain === '') {
            return $this->notFound();
        }

        $rows = [];
        if ($user !== '') {
            $st = App::db()->prepare(
                "SELECT * FROM sip_accounts WHERE username = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$user]);
            $one = $st->fetch(\PDO::FETCH_ASSOC);
            if ($one) {
                $rows[] = $one;
            }
        } else {
            // Domain-only locate: Sofia expects users in the domain document (not an empty shell).
            $st = App::db()->query(
                "SELECT * FROM sip_accounts WHERE status = 'active' ORDER BY id ASC LIMIT 500"
            );
            $rows = $st ? ($st->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        }
        if ($rows === []) {
            return $this->notFound();
        }

        $usersXml = '';
        foreach ($rows as $row) {
            $usersXml .= $this->directoryUserXml($row, $domain);
        }
        if ($usersXml === '') {
            return $this->notFound();
        }

        // Users directly under <domain> (no groups wrapper) — matches FS xml_curl examples
        // and avoids parse edge cases. dial-string kept for originate/user/ dialing.
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<document type="freeswitch/xml"><section name="directory">'
            . '<domain name="' . $this->x($domain) . '">'
            . '<params><param name="dial-string" value="{presence_id=${dialed_user}@${dialed_domain}}${sofia_contact(${dialed_user}@${dialed_domain})}"/></params>'
            . $usersXml
            . '</domain></section></document>';
    }

    /** @param array<string,mixed> $row */
    private function directoryUserXml(array $row, string $domain): string
    {
        $user = (string) ($row['username'] ?? '');
        if ($user === '') {
            return '';
        }
        $mode = strtolower((string) ($row['connection_mode'] ?? 'dynamic'));
        $cidr = SipHosts::cidrParam((string) ($row['sip_host'] ?? ''));
        $params = '';
        if ($mode === 'ip' && $cidr !== '') {
            $params .= '<param name="cidr" value="' . $this->x($cidr) . '"/>'
                . '<param name="allow-empty-password" value="true"/>';
        } else {
            $plain = '';
            if (!empty($row['password_enc'])) {
                try {
                    $plain = Crypto::decrypt((string) $row['password_enc']);
                } catch (\Throwable) {
                    $plain = '';
                }
            }
            $ha1 = $plain !== '' ? md5($user . ':' . $domain . ':' . $plain) : (string) ($row['ha1'] ?? '');
            if ($ha1 === '' && $plain === '') {
                return '';
            }
            // a1-hash is preferred; password is a fallback for clients that ignore HA1.
            if ($ha1 !== '') {
                $params .= '<param name="a1-hash" value="' . $this->x($ha1) . '"/>';
            }
            if ($plain !== '') {
                $params .= '<param name="password" value="' . $this->x($plain) . '"/>';
            }
        }
        $media = MediaPolicy::resolve(
            isset($row['media_policy']) ? (string) $row['media_policy'] : null,
            App::db()
        );
        $bypass = $media === 'direct' ? 'true' : 'false';
        $dtmf = match (strtolower(trim((string) ($row['dtmfmode'] ?? 'RFC2833')))) {
            'info' => 'info',
            'inband' => 'inband',
            'auto' => '',
            default => 'rfc2833',
        };
        $vars = '<variable name="user_context" value="ownswitch"/>'
            . '<variable name="os_sip_user" value="' . $this->x($user) . '"/>'
            . '<variable name="os_media_policy" value="' . $this->x($media) . '"/>'
            . '<variable name="bypass_media" value="' . $bypass . '"/>';
        if ($dtmf !== '') {
            $vars .= '<variable name="dtmf_type" value="' . $this->x($dtmf) . '"/>';
        }
        if (strtolower((string) ($row['dtmf_pass'] ?? 'yes')) === 'no') {
            $vars .= '<variable name="drop_dtmf" value="true"/>';
        }
        return '<user id="' . $this->x($user) . '">'
            . '<params>' . $params
            . '<param name="dial-string" value="{presence_id=' . $this->x($user) . '@${dialed_domain}}${sofia_contact(${dialed_user}@${dialed_domain})}"/>'
            . '</params>'
            . '<variables>' . $vars . '</variables></user>';
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
