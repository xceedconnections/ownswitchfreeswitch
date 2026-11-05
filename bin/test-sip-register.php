<?php

declare(strict_types=1);

/**
 * Local REGISTER probe against OpenSIPS (run ON the server).
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/test-sip-register.php USER PASS
 *
 * Unauthenticated 200 OK is a FAILURE (OpenSIPS must challenge with 401).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$user = $argv[1] ?? '';
$pass = $argv[2] ?? '';
if ($user === '' || $pass === '') {
    fwrite(STDERR, "Usage: php bin/test-sip-register.php <username> <password>\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;

$domain = (string) App::config('app.integrations.opensips.sip_domain', 'localhost');
$publicIp = trim((string) App::config('app.integrations.opensips.public_ip', ''));
$port = 5060;
$dst = '127.0.0.1';

$tryRegister = static function (string $toHost) use ($user, $pass, $domain, $dst, $port): bool {
    $callId = bin2hex(random_bytes(8)) . '@127.0.0.1';
    $branch = 'z9hG4bK' . bin2hex(random_bytes(4));
    $tag = bin2hex(random_bytes(4));

    $sock = @stream_socket_client("udp://{$dst}:{$port}", $errno, $errstr, 2);
    if (!$sock) {
        fwrite(STDERR, "Cannot open UDP to {$dst}:{$port}: {$errstr}\n");
        return false;
    }
    stream_set_timeout($sock, 3);

    $req1 = "REGISTER sip:{$toHost} SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:5090;branch={$branch};rport\r\n"
        . "Max-Forwards: 70\r\n"
        . "From: <sip:{$user}@{$toHost}>;tag={$tag}\r\n"
        . "To: <sip:{$user}@{$toHost}>\r\n"
        . "Call-ID: {$callId}\r\n"
        . "CSeq: 1 REGISTER\r\n"
        . "Contact: <sip:{$user}@127.0.0.1:5090>\r\n"
        . "Expires: 60\r\n"
        . "Content-Length: 0\r\n\r\n";

    echo ">>> REGISTER (no auth) To-host={$toHost} user={$user}\n";
    fwrite($sock, $req1);
    $r1 = fread($sock, 8192);
    $r1 = is_string($r1) ? $r1 : '';
    echo $r1 !== '' ? $r1 : "(no response — OpenSIPS not listening on UDP {$port}?)\n";

    if ($r1 === '' || !preg_match('/^SIP\/2\.0\s+(\d+)/', $r1, $m)) {
        fclose($sock);
        return false;
    }
    $code = (int) $m[1];
    if ($code === 200) {
        fwrite(STDERR, "\n**** FAIL: OpenSIPS accepts REGISTER with NO password (200). ****\n");
        fwrite(STDERR, "**** Run AS ROOT (you are often already root — drop sudo -u ownswitch): ****\n\n");
        fwrite(STDERR, "  cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \\\n");
        fwrite(STDERR, "    && bash deploy/rebuild-sip-register-from-scratch.sh\n\n");
        fclose($sock);
        return false;
    }
    if ($code !== 401 && $code !== 407) {
        fwrite(STDERR, "Unexpected status {$code}\n");
        fclose($sock);
        return false;
    }
    if (!preg_match('/realm="([^"]+)"/i', $r1, $rm) || !preg_match('/nonce="([^"]+)"/i', $r1, $nm)) {
        fwrite(STDERR, "Challenge missing realm/nonce\n");
        fclose($sock);
        return false;
    }
    $realm = $rm[1];
    $nonce = $nm[1];
    $uri = "sip:{$toHost}";
    $ha1 = md5("{$user}:{$realm}:{$pass}");
    $ha2 = md5("REGISTER:{$uri}");
    $response = md5("{$ha1}:{$nonce}:{$ha2}");
    echo "    challenge realm={$realm} (plaintext password auth; portal domain={$domain})\n";

    $branch2 = 'z9hG4bK' . bin2hex(random_bytes(4));
    $req2 = "REGISTER sip:{$toHost} SIP/2.0\r\n"
        . "Via: SIP/2.0/UDP 127.0.0.1:5090;branch={$branch2};rport\r\n"
        . "Max-Forwards: 70\r\n"
        . "From: <sip:{$user}@{$toHost}>;tag={$tag}\r\n"
        . "To: <sip:{$user}@{$toHost}>\r\n"
        . "Call-ID: {$callId}\r\n"
        . "CSeq: 2 REGISTER\r\n"
        . "Contact: <sip:{$user}@127.0.0.1:5090>\r\n"
        . "Authorization: Digest username=\"{$user}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"{$uri}\", response=\"{$response}\", algorithm=MD5\r\n"
        . "Expires: 60\r\n"
        . "Content-Length: 0\r\n\r\n";

    echo ">>> REGISTER (digest) realm={$realm}\n";
    fwrite($sock, $req2);
    $r2 = fread($sock, 8192);
    $r2 = is_string($r2) ? $r2 : '';
    echo $r2 !== '' ? $r2 : "(no response)\n";
    fclose($sock);

    if (preg_match('/^SIP\/2\.0\s+200/', $r2)) {
        echo "SUCCESS for To-host={$toHost} (realm={$realm})\n";
        return true;
    }
    echo "FAILED for To-host={$toHost} (wrong password or empty subscriber.password)\n";
    return false;
};

$hosts = [];
if ($publicIp !== '') {
    $hosts[] = $publicIp;
}
if ($domain !== '' && $domain !== $publicIp) {
    $hosts[] = $domain;
}
if ($hosts === []) {
    $hosts[] = '127.0.0.1';
}

$any = false;
foreach ($hosts as $h) {
    echo "\n==== Probe Domain/To = {$h} ====\n";
    if ($tryRegister($h)) {
        $any = true;
    }
}

if ($any) {
    $hint = $publicIp !== '' ? $publicIp : $domain;
    echo "\nOpenSIPS digest auth OK. Softphone: Domain={$hint}, Proxy=BLANK, Port=5060 UDP.\n";
    exit(0);
}

echo "\nFAILED all probes.\n";
exit(1);
