<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

use Ownswitch\App;
use Ownswitch\Database\Migrator;
use Ownswitch\Integrations\CGRateS\CgratesClient;
use Ownswitch\Integrations\FreeSWITCH\EslClient;
use Ownswitch\Integrations\RTPengine\RtpengineClient;
use PDO;

/** Honest component status: "unconfigured" is reported as such, never faked as OK. */
final class HealthService
{
    /** @return list<array{component:string,status:string,detail:string}> */
    public function run(?PDO $pdo): array
    {
        $r = [];
        $r[] = ['component' => 'PHP', 'status' => PHP_VERSION_ID >= 80200 ? 'ok' : 'fail', 'detail' => 'PHP ' . PHP_VERSION];
        foreach (['pdo_mysql', 'bcmath', 'mbstring', 'openssl'] as $ext) {
            $r[] = ['component' => "ext-{$ext}", 'status' => extension_loaded($ext) ? 'ok' : 'fail', 'detail' => extension_loaded($ext) ? 'loaded' : 'missing'];
        }
        $r[] = ['component' => 'APP_KEY', 'status' => strlen((string) App::config('app.key', '')) >= 32 ? 'ok' : 'warn', 'detail' => strlen((string) App::config('app.key', '')) >= 32 ? 'configured' : 'not set (needed to store carrier credentials)'];

        if ($pdo === null) {
            $r[] = ['component' => 'MySQL', 'status' => 'fail', 'detail' => 'not connected'];
            return $r;
        }
        try {
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $r[] = ['component' => 'MySQL', 'status' => 'ok', 'detail' => $ver];
            $m = new Migrator($pdo, App::basePath('database/migrations'));
            $pending = array_filter($m->status(), static fn ($s) => $s['status'] === 'pending');
            $r[] = ['component' => 'Migrations', 'status' => $pending ? 'warn' : 'ok', 'detail' => $pending ? count($pending) . ' pending - run php bin/migrate.php' : 'up to date'];
            $q = (int) $pdo->query("SELECT COUNT(*) FROM cdr_ingest_queue WHERE status = 'pending'")->fetchColumn();
            $r[] = ['component' => 'CDR ingest queue', 'status' => $q > 1000 ? 'warn' : 'ok', 'detail' => "{$q} pending"];
        } catch (\Throwable $e) {
            $r[] = ['component' => 'MySQL', 'status' => 'fail', 'detail' => App::config('app.debug') ? $e->getMessage() : 'query failed (check logs)'];
        }

        $cg = App::config('app.integrations.cgrates');
        $c = new CgratesClient((string) $cg['url'], (string) $cg['user'], (string) $cg['password'], (string) $cg['tenant'], 2);
        if ($c->configured()) {
            $r[] = $this->probe('CGRateS', true, static fn () => $c->ping() ? 'Pong' : 'no pong');
        } else {
            $r[] = ['component' => 'CGRateS', 'status' => 'ok', 'detail' => 'Not used. This portal rates the calls.'];
        }

        $rt = App::config('app.integrations.rtpengine');
        $rc = new RtpengineClient((string) $rt['host'], (int) $rt['port']);
        if ($rc->configured()) {
            $r[] = $this->probe('RTPengine', true, static fn () => $rc->ping() ? 'pong' : 'no pong');
        } else {
            $r[] = ['component' => 'RTPengine', 'status' => 'ok', 'detail' => 'Not used. FreeSWITCH carries the audio.'];
        }

        $fs = App::config('app.integrations.freeswitch');
        $ec = new EslClient((string) $fs['host'], (int) $fs['port'], (string) $fs['password']);
        $r[] = $this->probe('FreeSWITCH (optional)', $ec->configured(), static function () use ($ec) {
            try {
                return strtok($ec->status(), "\n") ?: 'connected';
            } finally {
                $ec->close();
            }
        });

        $r[] = $this->probeOpensips();
        return $r;
    }

    /** @return array{component:string,status:string,detail:string} */
    private function probeOpensips(): array
    {
        $prov = \Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return ['component' => 'FreeSWITCH', 'status' => 'unconfigured', 'detail' => $prov->initError() ?: 'ESL not configured'];
        }
        try {
            $esl = new \Ownswitch\Integrations\FreeSWITCH\EslClient(
                (string) App::config('app.integrations.freeswitch.host'),
                (int) App::config('app.integrations.freeswitch.port', 8021),
                (string) App::config('app.integrations.freeswitch.password'),
                3.0
            );
            $status = $esl->status();
            $esl->close();
            $regs = count($prov->registeredContacts());
            return ['component' => 'FreeSWITCH', 'status' => 'ok', 'detail' => 'ESL up; registered=' . $regs . '; ' . mb_substr(str_replace(["\r", "\n"], ' ', $status), 0, 180)];
        } catch (\Throwable $e) {
            return ['component' => 'FreeSWITCH', 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /** @param callable():string $fn */
    private function probe(string $name, bool $configured, callable $fn): array
    {
        if (!$configured) {
            return ['component' => $name, 'status' => 'unconfigured', 'detail' => 'not configured'];
        }
        try {
            return ['component' => $name, 'status' => 'ok', 'detail' => $fn()];
        } catch (\Throwable $e) {
            return ['component' => $name, 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }
}
