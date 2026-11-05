<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\TenantScope;
use Ownswitch\Support\View;
use PDO;

abstract class BaseController
{
    protected function db(): PDO
    {
        return App::db();
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $data), $status);
    }

    protected function redirect(Request $r, string $path, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            App::session()->flash($type, $flash);
        }
        return Response::redirect($r->basePath . '/' . ltrim($path, '/'));
    }

    protected function scope(Request $r): TenantScope
    {
        $s = $r->attr('tenant');
        if (!$s instanceof TenantScope) {
            // Fail closed: a controller reached without TenantMiddleware gets a deny-all scope.
            return new TenantScope('none');
        }
        return $s;
    }

    /** @return array<string,mixed> */
    protected function user(Request $r): array
    {
        return (array) $r->attr('user', []);
    }

    protected function forbidden(string $msg = '403 Forbidden'): Response
    {
        return new Response($this->errorPage(403, $msg), 403);
    }

    protected function notFound(string $msg = 'Not found'): Response
    {
        return new Response($this->errorPage(404, $msg), 404);
    }

    private function errorPage(int $code, string $msg): string
    {
        try {
            return View::render('error', ['code' => $code, 'message' => $msg, 'title' => (string) $code]);
        } catch (\Throwable) {
            return htmlspecialchars("{$code} {$msg}", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    protected function audit(Request $r, string $action, string $entity, ?int $id, ?array $before = null, ?array $after = null): void
    {
        $strip = static function (?array $a): ?string {
            if ($a === null) {
                return null;
            }
            foreach (['password', 'password_hash', 'ha1', 'ha1b', 'auth_secret_enc'] as $k) {
                unset($a[$k]);   // never write secrets to the audit trail
            }
            return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
        };
        $this->db()->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip) VALUES (?,?,?,?,?,?,?)')
            ->execute([$this->user($r)['id'] ?? null, $action, $entity, $id, $strip($before), $strip($after), $r->ip()]);
    }

    /** Calendar dates in the current user's timezone (Y-m-d). */
    protected function dateRange(Request $r, int $defaultDays = 7): array
    {
        $ok = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v;
        $toRaw = $r->query('to');
        if (!$ok($toRaw)) {
            $toRaw = $r->input('to');
        }
        $fromRaw = $r->query('from');
        if (!$ok($fromRaw)) {
            $fromRaw = $r->input('from');
        }
        $tz = \Ownswitch\Support\Timezone::fromUser($this->user($r));
        $to = $ok($toRaw) ? (string) $toRaw : \Ownswitch\Support\Timezone::today($tz);
        $from = $ok($fromRaw) ? (string) $fromRaw : \Ownswitch\Support\Timezone::daysAgo($tz, $defaultDays);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // cap range to 366 days to protect the DB
        if (strtotime($to) - strtotime($from) > 366 * 86400) {
            $from = (new \DateTimeImmutable($to))->modify('-366 days')->format('Y-m-d');
        }
        return [$from, $to];
    }

    /**
     * Local Y-m-d range plus UTC SQL bounds for CDR/report queries.
     *
     * @return array{0:string,1:string,2:string,3:string} [fromYmd, toYmd, fromUtc, toUtcExclusive]
     */
    protected function dateRangeUtc(Request $r, int $defaultDays = 7): array
    {
        [$from, $to] = $this->dateRange($r, $defaultDays);
        $tz = \Ownswitch\Support\Timezone::fromUser($this->user($r));
        [$fromUtc, $toUtc] = \Ownswitch\Support\Timezone::localDayRangeToUtc($from, $to, $tz);
        return [$from, $to, $fromUtc, $toUtc];
    }

    protected function userTimezone(Request $r): string
    {
        return \Ownswitch\Support\Timezone::fromUser($this->user($r));
    }
}
