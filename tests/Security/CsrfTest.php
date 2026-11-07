<?php

declare(strict_types=1);

namespace Ownswitch\Tests\Security;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Middleware\CsrfMiddleware;
use Ownswitch\Services\Auth\AuthService;
use Ownswitch\Services\Auth\SessionService;
use Ownswitch\Tests\TestCase;

final class CsrfTest extends TestCase
{
    private function ok(): callable
    {
        return static fn (Request $r): Response => new Response('OK');
    }

    public function testTokenIsStableWithinSessionAndLongRandom(): void
    {
        $s = new SessionService([]);
        $t = $s->csrfToken();
        $this->assertSame(64, strlen($t));
        $this->assertSame($t, $s->csrfToken());
        $this->assertNotSame($t, (new SessionService([]))->csrfToken(), 'tokens differ across sessions');
    }

    public function testValidateRejectsMissingEmptyWrongAndWrongType(): void
    {
        $s = new SessionService([]);
        $t = $s->csrfToken();
        $this->assertTrue($s->validateCsrf($t));
        $this->assertFalse($s->validateCsrf(null));
        $this->assertFalse($s->validateCsrf(''));
        $this->assertFalse($s->validateCsrf($t . 'x'));
        $this->assertFalse($s->validateCsrf(substr($t, 1)));
        $this->assertFalse((new SessionService([]))->validateCsrf($t), 'token from another session');
    }

    public function testPostWithoutTokenIsRejectedWith419(): void
    {
        $s = new SessionService([]);
        $s->csrfToken();
        $mw = new CsrfMiddleware($s);
        $this->assertSame(419, $mw->handle(new Request('POST', '/customers'), $this->ok())->status);
        $this->assertSame(419, $mw->handle(new Request('POST', '/customers', [], ['_csrf' => 'bad']), $this->ok())->status);
        $this->assertSame(419, $mw->handle(new Request('DELETE', '/customers/1'), $this->ok())->status);
        $this->assertSame(419, $mw->handle(new Request('POST', '/customers', [], ['_csrf' => ['x']]), $this->ok())->status, 'array token');
    }

    public function testPostWithValidFieldOrHeaderPasses(): void
    {
        $s = new SessionService([]);
        $t = $s->csrfToken();
        $mw = new CsrfMiddleware($s);
        $this->assertSame(200, $mw->handle(new Request('POST', '/x', [], ['_csrf' => $t]), $this->ok())->status);
        $hdr = new Request('POST', '/x', [], [], [], ['x-csrf-token' => $t]);
        $this->assertSame(200, $mw->handle($hdr, $this->ok())->status);
    }

    public function testSafeMethodsSkipTheCheck(): void
    {
        $mw = new CsrfMiddleware(new SessionService([]));
        foreach (['GET', 'HEAD', 'OPTIONS'] as $m) {
            $this->assertSame(200, $mw->handle(new Request($m, '/x'), $this->ok())->status, $m);
        }
    }

    public function testTokenRotatesOnLogin(): void
    {
        $s = new SessionService([]);
        $before = $s->csrfToken();
        $s->login(5);
        $this->assertNotSame($before, $s->csrfToken());
        $this->assertSame(5, $s->userId());
        $this->assertFalse($s->validateCsrf($before), 'pre-login token is dead');
    }

    public function testDestroyClearsSession(): void
    {
        $s = new SessionService([]);
        $s->login(5);
        $s->destroy();
        $this->assertNull($s->userId());
    }

    public function testOutputEscapingHelper(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
        $this->assertSame('&quot;&gt; &lt;img src=x onerror=1&gt; &#039;', e('"> <img src=x onerror=1> \''));
        $this->assertSame('', e(null));
        $this->assertSame('5', e(5));
    }

    public function testPasswordsAreHashedAndPolicyEnforced(): void
    {
        $this->assertSame(['Password is required.'], AuthService::passwordProblems(''));
        $this->assertSame([], AuthService::passwordProblems('short'));
        $this->assertSame([], AuthService::passwordProblems('Correct-Horse-Battery-9'));
        $h = password_hash('Correct-Horse-Battery-9', PASSWORD_DEFAULT);
        $this->assertTrue(password_verify('Correct-Horse-Battery-9', $h));
        $this->assertFalse(password_verify('Correct-Horse-Battery-8', $h));
        $this->assertTrue(str_starts_with($h, '$2y$') || str_starts_with($h, '$argon2'), 'modern hash');
    }
}
