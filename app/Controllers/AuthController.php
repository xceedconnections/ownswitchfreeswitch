<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\LoginCaptcha;
use Ownswitch\Support\Timezone;

final class AuthController extends BaseController
{
    public function loginForm(Request $r, array $p = []): Response
    {
        App::session()->start();
        if (App::session()->userId() !== null) {
            return $this->redirect($r, '/');
        }
        return $this->view('auth/login', [
            'title' => 'Sign in',
            'error' => null,
            'email' => '',
            'login' => '',
            'captcha' => LoginCaptcha::issue(),
        ]);
    }

    public function login(Request $r, array $p = []): Response
    {
        $login = is_string($r->post['login'] ?? null) ? (string) $r->post['login'] : '';
        if ($login === '' && is_string($r->post['email'] ?? null)) {
            $login = (string) $r->post['email']; // backwards compatible
        }
        $pass = is_string($r->post['password'] ?? null) ? (string) $r->post['password'] : '';
        $captchaOk = LoginCaptcha::verify(
            is_string($r->post['captcha'] ?? null) ? (string) $r->post['captcha'] : null,
            is_string($r->post['captcha_token'] ?? null) ? (string) $r->post['captcha_token'] : null,
        );
        if (!$captchaOk) {
            App::auth()->recordPortalFail($r->ip(), $login !== '' ? $login : 'captcha');
            return $this->view('auth/login', [
                'title' => 'Sign in',
                'error' => 'Incorrect security check. Please try again.',
                'email' => $login,
                'login' => $login,
                'captcha' => LoginCaptcha::issue(),
            ], 401);
        }
        $res = App::auth()->attempt($login, $pass, $r->ip());
        if (!$res['ok']) {
            return $this->view('auth/login', [
                'title' => 'Sign in',
                'error' => $res['error'],
                'email' => $login,
                'login' => $login,
                'captcha' => LoginCaptcha::issue(),
            ], 401);
        }
        $r->setAttr('user', $res['user']);
        $this->audit($r, 'login', 'user', (int) $res['user']['id']);
        return $this->redirect($r, '/');
    }

    public function logout(Request $r, array $p = []): Response
    {
        $this->audit($r, 'logout', 'user', (int) ($this->user($r)['id'] ?? 0) ?: null);
        App::auth()->logout();
        return Response::redirect($r->basePath . '/login');
    }

    public function passwordForm(Request $r, array $p = []): Response
    {
        return $this->view('auth/password', ['title' => 'Change password', 'errors' => []]);
    }

    public function passwordChange(Request $r, array $p = []): Response
    {
        $cur = is_string($r->post['current'] ?? null) ? (string) $r->post['current'] : '';
        $new = is_string($r->post['new'] ?? null) ? (string) $r->post['new'] : '';
        $confirm = is_string($r->post['confirm'] ?? null) ? (string) $r->post['confirm'] : '';
        if ($new !== $confirm) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => ['New password and confirmation do not match.']], 422);
        }
        $res = App::auth()->changePassword((int) $this->user($r)['id'], $cur, $new);
        if (!$res['ok']) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => $res['errors']], 422);
        }
        $this->audit($r, 'password_change', 'user', (int) $this->user($r)['id']);
        return $this->redirect($r, '/', 'Password changed.');
    }

    public function profileForm(Request $r, array $p = []): Response
    {
        $u = $this->user($r);
        return $this->view('auth/profile', [
            'title' => 'My profile',
            'user' => $u,
            'timezone' => Timezone::stored($u),
            'effective' => Timezone::fromUser($u),
            'options' => Timezone::OPTIONS,
            'errors' => [],
        ]);
    }

    public function profileSave(Request $r, array $p = []): Response
    {
        $u = $this->user($r);
        $raw = trim(is_string($r->post['timezone'] ?? null) ? (string) $r->post['timezone'] : '');
        if ($raw === '' || strcasecmp($raw, Timezone::FOLLOW_SYSTEM) === 0) {
            $tz = Timezone::FOLLOW_SYSTEM;
        } else {
            try {
                new \DateTimeZone($raw);
                $tz = $raw;
            } catch (\Throwable) {
                return $this->view('auth/profile', [
                    'title' => 'My profile',
                    'user' => $u,
                    'timezone' => Timezone::stored($u),
                    'effective' => Timezone::fromUser($u),
                    'options' => Timezone::OPTIONS,
                    'errors' => ['Choose a timezone such as New York, London, or Karachi.'],
                ], 422);
            }
        }
        $this->db()->prepare('UPDATE users SET timezone = ? WHERE id = ?')->execute([$tz, (int) $u['id']]);
        $this->audit($r, 'profile_timezone', 'user', (int) $u['id'], ['timezone' => $u['timezone'] ?? null], ['timezone' => $tz]);
        $shown = $tz === Timezone::FOLLOW_SYSTEM
            ? Timezone::label(Timezone::systemDefault()) . ' (system default)'
            : Timezone::label($tz);
        return $this->redirect($r, '/account/profile', 'Timezone saved. Stats and reports now use ' . $shown . '.');
    }
}
