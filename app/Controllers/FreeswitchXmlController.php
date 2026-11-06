<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Switching\FreeswitchCallFlow;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\MediaPolicy;
use Ownswitch\Support\SipAuthLog;
use Ownswitch\Support\SipCodecs;
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
        // Digest username wins over From / Request-URI (Asterisk match_auth_username=yes).
        // Vicidial sends many carriers from one IP; each Authorization username is a different account.
        $authUser = trim((string) $r->input('sip_auth_username', ''));
        $user = $authUser !== ''
            ? $authUser
            : trim((string) ($r->input('user', $r->input('Hunt-Username', ''))));
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
            // Unknown SIP user. Sofia still rejects the digest; this line is what fail2ban bans.
            if ($user !== '') {
                $method = strtoupper(trim((string) $r->input('sip_auth_method', $r->input('method', 'REGISTER'))));
                SipAuthLog::failed($method === 'INVITE' ? 'INVITE' : 'REGISTER', $user, $this->sipSourceIp($r));
            }
            return $this->notFound();
        }

        $sharedIps = $this->sharedIpAuthHosts($rows);
        $usersXml = '';
        foreach ($rows as $row) {
            $usersXml .= $this->directoryUserXml($row, $domain, $sharedIps);
        }
        if ($usersXml === '') {
            return $this->notFound();
        }

        // Pretty-printed XML: mod_xml_curl's switch_xml_parse_str rejects our otherwise-valid
        // single-line directory documents (Error Parsing Result) but accepts the same tree
        // with newlines/indentation — verified against a bare HTTP fixture on this host.
        return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n"
            . "<document type=\"freeswitch/xml\">\n"
            . "  <section name=\"directory\">\n"
            . "    <domain name=\"" . $this->x($domain) . "\">\n"
            . "      <params>\n"
            . "        <param name=\"dial-string\" value=\"{presence_id=\${dialed_user}@\${dialed_domain}}\${sofia_contact(\${dialed_user}@\${dialed_domain})}\"/>\n"
            . "      </params>\n"
            . $usersXml
            . "    </domain>\n"
            . "  </section>\n"
            . "</document>\n";
    }

    /**
     * IPs listed on more than one IP-auth account. Sofia matches cidr before digest,
     * so a shared IP must not be pinned to the first peer.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,true>
     */
    private function sharedIpAuthHosts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (strtolower((string) ($row['connection_mode'] ?? '')) !== 'ip') {
                continue;
            }
            foreach (SipHosts::parse((string) ($row['sip_host'] ?? '')) as $ip) {
                $counts[$ip] = ($counts[$ip] ?? 0) + 1;
            }
        }
        $shared = [];
        foreach ($counts as $ip => $n) {
            if ($n > 1) {
                $shared[$ip] = true;
            }
        }
        return $shared;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,true> $sharedIps
     */
    private function directoryUserXml(array $row, string $domain, array $sharedIps = []): string
    {
        $user = (string) ($row['username'] ?? '');
        if ($user === '') {
            return '';
        }
        $mode = strtolower((string) ($row['connection_mode'] ?? 'dynamic'));
        $ips = SipHosts::parse((string) ($row['sip_host'] ?? ''));
        $uniqueIps = [];
        foreach ($ips as $ip) {
            if (!isset($sharedIps[$ip])) {
                $uniqueIps[] = $ip;
            }
        }
        $cidr = $uniqueIps === [] ? '' : SipHosts::cidrParam(implode(',', $uniqueIps));
        $params = '';
        // Username/password accounts never match by source IP. Same Vicidial server can host many users.
        if ($mode === 'ip' && $cidr !== '') {
            $params .= "          <param name=\"cidr\" value=\"" . $this->x($cidr) . "\"/>\n"
                . "          <param name=\"allow-empty-password\" value=\"true\"/>\n";
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
            // Prefer password for REGISTER; a1-hash alone is fine too. Emit both when available.
            if ($ha1 !== '') {
                $params .= "          <param name=\"a1-hash\" value=\"" . $this->x($ha1) . "\"/>\n";
            }
            if ($plain !== '') {
                $params .= "          <param name=\"password\" value=\"" . $this->x($plain) . "\"/>\n";
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
        $vars = "          <variable name=\"user_context\" value=\"ownswitch\"/>\n"
            . "          <variable name=\"os_sip_user\" value=\"" . $this->x($user) . "\"/>\n"
            . "          <variable name=\"os_media_policy\" value=\"" . $this->x($media) . "\"/>\n"
            . "          <variable name=\"bypass_media\" value=\"" . $bypass . "\"/>\n";
        if ($dtmf !== '') {
            $vars .= "          <variable name=\"dtmf_type\" value=\"" . $this->x($dtmf) . "\"/>\n";
        }
        if (strtolower((string) ($row['dtmf_pass'] ?? 'yes')) === 'no') {
            $vars .= "          <variable name=\"drop_dtmf\" value=\"true\"/>\n";
        }
        // Only when codecs are set — omit keeps Sofia profile defaults (no REG impact).
        $codecStr = SipCodecs::toAbsoluteCodecString(
            isset($row['codecs']) ? (string) $row['codecs'] : null
        );
        if ($codecStr !== '') {
            $vars .= "          <variable name=\"absolute_codec_string\" value=\"" . $this->x($codecStr) . "\"/>\n";
        }
        // Domain-level dial-string is enough; avoid duplicate user dial-string.
        return "      <user id=\"" . $this->x($user) . "\">\n"
            . "        <params>\n" . $params
            . "        </params>\n"
            . "        <variables>\n" . $vars
            . "        </variables>\n"
            . "      </user>\n";
    }

    private function sipSourceIp(Request $r): string
    {
        foreach (['ip', 'network_ip', 'sip_network_ip', 'variable_sip_network_ip', 'Caller-Network-Addr', 'network-ip'] as $key) {
            $ip = trim((string) $r->input($key, ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        return '';
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
