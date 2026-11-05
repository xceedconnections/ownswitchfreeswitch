<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Current install vs GitHub main (xceedconnections/ownswitchfreeswitch).
 */
final class VersionService
{
    private const HELPER = '/usr/local/sbin/ownswitch-update';
    private const REPO = 'xceedconnections/ownswitchfreeswitch';
    private const SRC = '/opt/ownswitch-src';
    private const CURRENT = '/opt/ownswitch/current';
    private const REFRESH = '/opt/ownswitch-src/deploy/refresh-ui.sh';

    /** @return array{ok:bool,current_sha:string,current_short:string,current_date:string,remote_sha:string,remote_short:string,remote_date:string,remote_message:string,update_available:bool,detail:string,helper:bool,can_update:bool} */
    public function status(): array
    {
        $cur = $this->localHead();
        $remote = $this->githubHead();
        $update = false;
        if ($cur['sha'] !== '' && $remote['sha'] !== '' && !$this->sameCommit($cur['sha'], $remote['sha'])) {
            $update = true;
        }
        $can = $this->canUpdate();
        return [
            'ok' => $cur['sha'] !== '' || $remote['sha'] !== '',
            'current_sha' => $cur['sha'],
            'current_short' => $cur['short'],
            'current_date' => $cur['date'],
            'remote_sha' => $remote['sha'],
            'remote_short' => $remote['short'],
            'remote_date' => $remote['date'],
            'remote_message' => $remote['message'],
            'update_available' => $update,
            'detail' => $remote['error'] !== '' ? $remote['error'] : ($cur['sha'] === '' ? 'Local git SHA not found' : 'ok'),
            'helper' => $can,
            'can_update' => $can,
            'repo' => self::REPO,
            'branch' => 'main',
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function updateNow(): array
    {
        $cmd = $this->updateCommand();
        if ($cmd === null) {
            return [
                'ok' => false,
                'detail' => 'Update helper not installed. As root run once: '
                    . 'sudo bash /opt/ownswitch-src/deploy/install-update-helper.sh '
                    . 'then click Update now again.',
            ];
        }
        $full = 'sudo -n ' . $cmd . ' 2>&1';
        $out = [];
        $code = 0;
        exec($full, $out, $code);
        $text = trim(implode("\n", $out));
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, -4000);
        }
        return ['ok' => $code === 0, 'detail' => $text !== '' ? $text : ($code === 0 ? 'Updated.' : 'Update failed')];
    }

    public function canUpdate(): bool
    {
        return $this->updateCommand() !== null;
    }

    /** Shell fragment after `sudo -n` (already escaped), or null. */
    private function updateCommand(): ?string
    {
        if (is_file(self::HELPER) && is_executable(self::HELPER)) {
            return escapeshellarg(self::HELPER);
        }
        if (is_file(self::REFRESH) && is_readable(self::REFRESH)) {
            $bash = is_file('/usr/bin/bash') ? '/usr/bin/bash' : '/bin/bash';
            return escapeshellarg($bash) . ' ' . escapeshellarg(self::REFRESH);
        }
        return null;
    }

    private function sameCommit(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }
        if (hash_equals($a, $b)) {
            return true;
        }
        $len = min(strlen($a), strlen($b));
        if ($len < 7) {
            return false;
        }
        return hash_equals(substr($a, 0, $len), substr($b, 0, $len));
    }

    /** @return array{sha:string,short:string,date:string} */
    private function localHead(): array
    {
        $sha = '';
        $date = '';
        // Prefer full git SHA from source checkout
        if (is_dir(self::SRC . '/.git')) {
            $out = [];
            $code = 0;
            exec('git -C ' . escapeshellarg(self::SRC) . ' rev-parse HEAD 2>/dev/null', $out, $code);
            if ($code === 0 && isset($out[0]) && preg_match('/^[0-9a-f]{7,40}$/i', trim($out[0]))) {
                $sha = strtolower(trim($out[0]));
                $dout = [];
                exec('git -C ' . escapeshellarg(self::SRC) . ' show -s --format=%ci HEAD 2>/dev/null', $dout);
                $date = trim($dout[0] ?? '');
            }
        }
        if ($sha === '') {
            $link = @readlink(self::CURRENT);
            if (is_string($link) && preg_match('/-([0-9a-f]{7,40})$/i', basename($link), $m)) {
                $sha = strtolower($m[1]);
            }
        }
        return [
            'sha' => $sha,
            'short' => $sha !== '' ? substr($sha, 0, 7) : '',
            'date' => $date,
        ];
    }

    /** @return array{sha:string,short:string,date:string,message:string,error:string} */
    private function githubHead(): array
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/commits/main';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/vnd.github+json\r\nUser-Agent: Ownswitch-Portal\r\n",
                'timeout' => 8,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return ['sha' => '', 'short' => '', 'date' => '', 'message' => '', 'error' => 'Could not reach GitHub API'];
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['sha'])) {
            return ['sha' => '', 'short' => '', 'date' => '', 'message' => '', 'error' => 'Unexpected GitHub response'];
        }
        $sha = strtolower((string) $j['sha']);
        $msg = trim((string) ($j['commit']['message'] ?? ''));
        if (($nl = strpos($msg, "\n")) !== false) {
            $msg = substr($msg, 0, $nl);
        }
        return [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'date' => (string) ($j['commit']['committer']['date'] ?? ''),
            'message' => mb_substr($msg, 0, 160),
            'error' => '',
        ];
    }
}
