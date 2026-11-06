<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Settings\SettingsService;
use Ownswitch\Support\SipCodecs;

final class SettingsController extends BaseController
{
    public function edit(Request $r, array $p = []): Response
    {
        $s = new SettingsService($this->db());
        $defaults = [
            'tax_rate_percent' => '0',
            'system_max_concurrent' => '0',
            'system_max_cps' => '0',
            'failover_max_retries' => '3',
            'failover_sip_codes' => '408,480,486,503',
            'kill_switch' => '0',
            'switch_name' => '',
            'system_timezone' => 'UTC',
            'prepaid_hard_cap_seconds' => '7200',
            'default_codecs' => SipCodecs::defaultStorage($this->db()),
        ];
        $values = [];
        foreach (SettingsService::SCHEMA as $k => $_) {
            $values[$k] = $s->get($k, $defaults[$k] ?? '');
            if (($values[$k] === null || $values[$k] === '') && isset($defaults[$k])) {
                $values[$k] = $defaults[$k];
            }
        }
        if (($values['default_codecs'] ?? '') === '') {
            $values['default_codecs'] = SipCodecs::defaultStorage($this->db());
        }
        return $this->view('settings/edit', [
            'title' => 'Settings',
            'schema' => SettingsService::SCHEMA,
            'values' => $values,
            'errors' => [],
            'logoUrl' => switch_logo_url(),
            'codecOptions' => SipCodecs::formOptions(),
        ]);
    }

    public function update(Request $r, array $p = []): Response
    {
        $s = new SettingsService($this->db());
        $errors = [];
        $clean = [];
        foreach (SettingsService::SCHEMA as $k => [$label, $type]) {
            if ($type === 'logo') {
                continue; // handled via upload
            }
            $raw = $r->post[$k] ?? null;
            if ($type === 'codecs' && is_array($raw)) {
                $raw = implode(',', array_map('strval', $raw));
            }
            [$v, $err] = SettingsService::validate($k, $raw);
            if ($err !== null) {
                $errors[$k] = $err;
            } else {
                $clean[$k] = $v;
            }
        }

        $logoPath = null;
        $file = $r->files['switch_logo'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$logoPath, $logoErr] = $this->storeLogo($file);
            if ($logoErr !== null) {
                $errors['switch_logo'] = $logoErr;
            } elseif ($logoPath !== null) {
                $clean['switch_logo'] = $logoPath;
            }
        }
        if (!empty($r->post['switch_logo_clear'])) {
            $clean['switch_logo'] = '';
        }

        if ($errors) {
            $values = array_filter($r->post, 'is_string');
            $values['switch_logo'] = $s->get('switch_logo', '');
            return $this->view('settings/edit', [
                'title' => 'Settings',
                'schema' => SettingsService::SCHEMA,
                'values' => $values,
                'errors' => $errors,
                'logoUrl' => switch_logo_url(),
                'codecOptions' => SipCodecs::formOptions(),
            ], 422);
        }

        if (($clean['switch_logo'] ?? null) === '') {
            $dir = $this->brandingDir();
            if ($dir !== '') {
                foreach (glob($dir . '/logo.*') ?: [] as $old) {
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }
            }
        }

        $uid = (int) $this->user($r)['id'];
        $before = $s->all();
        foreach ($clean as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                // SCHEMA must allow set — switch_logo is in SCHEMA
                if (!isset(SettingsService::SCHEMA[$k])) {
                    continue;
                }
                $s->set($k, (string) $v, $uid);
            }
        }
        $this->audit($r, 'settings_update', 'system_settings', null, array_intersect_key($before, $clean), $clean);
        $msg = 'Settings saved.';
        if (array_key_exists('failover_sip_codes', $clean) || array_key_exists('failover_max_retries', $clean)
            || array_key_exists('prepaid_hard_cap_seconds', $clean) || array_key_exists('kill_switch', $clean)) {
            $msg .= ' FreeSWITCH dialplan reads these on the next call (no restart needed).';
        }
        return $this->redirect($r, '/settings', $msg);
    }

    /** GET /branding/logo — the stored logo, for the signed-in header. */
    public function logo(Request $r, array $p = []): Response
    {
        $rel = (new SettingsService($this->db()))->get('switch_logo', '');
        $abs = $this->logoAbsolute(is_string($rel) ? $rel : '');
        if ($abs === null) {
            return $this->notFound('Logo not found');
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ][$ext] ?? 'application/octet-stream';
        $body = (string) file_get_contents($abs);
        return new Response($body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($body),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** @param array<string,mixed> $file @return array{0:?string,1:?string} */
    private function storeLogo(array $file): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'Logo must be under 2 MB.'];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return [null, 'Logo upload failed. Choose a PNG, JPG, WEBP or SVG under 2 MB.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, 'Invalid logo upload.'];
        }
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return [null, 'Logo must be under 2 MB.'];
        }
        $ext = $this->logoExtension($tmp);
        if ($ext === null) {
            return [null, 'Logo must be PNG, JPG, WEBP or SVG.'];
        }
        $dir = $this->brandingDir();
        if ($dir === '') {
            return [null, 'Cannot create branding upload folder.'];
        }
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $rel = 'uploads/branding/logo.' . $ext;
        $dest = $dir . '/logo.' . $ext;
        if (!move_uploaded_file($tmp, $dest)) {
            return [null, 'Could not store logo file.'];
        }
        @chmod($dest, 0644);
        return [$rel, null];
    }

    private function brandingDir(): string
    {
        $dir = App::basePath('storage/uploads/branding');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return '';
        }
        return $dir;
    }

    private function logoExtension(string $tmp): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $map = [
            'image/png' => 'png',
            'image/x-png' => 'png',
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/svg' => 'svg',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }
        if (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'text/html'], true)) {
            $head = (string) file_get_contents($tmp, false, null, 0, 512);
            if (preg_match('/<svg[\s>]/i', $head) && !preg_match('/<\?(?:php|=)/i', $head)) {
                return 'svg';
            }
        }
        return null;
    }

    private function logoAbsolute(string $rel): ?string
    {
        $name = basename(str_replace('\\', '/', $rel));
        if (!preg_match('/^logo\.(png|jpe?g|webp|svg)$/', $name)) {
            return null;
        }
        $dir = $this->brandingDir();
        if ($dir === '') {
            return null;
        }
        $abs = $dir . '/' . $name;
        return is_file($abs) ? $abs : null;
    }
}
