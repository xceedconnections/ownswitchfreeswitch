<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Settings\SettingsService;

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
            'prepaid_hard_cap_seconds' => '7200',
        ];
        $values = [];
        foreach (SettingsService::SCHEMA as $k => $_) {
            $values[$k] = $s->get($k, $defaults[$k] ?? '');
            if (($values[$k] === null || $values[$k] === '') && isset($defaults[$k])) {
                $values[$k] = $defaults[$k];
            }
        }
        return $this->view('settings/edit', [
            'title' => 'Settings',
            'schema' => SettingsService::SCHEMA,
            'values' => $values,
            'errors' => [],
            'logoUrl' => $this->logoPublicUrl($values['switch_logo'] ?? ''),
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
            [$v, $err] = SettingsService::validate($k, $r->post[$k] ?? null);
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
                'logoUrl' => $this->logoPublicUrl($values['switch_logo'] ?? ''),
            ], 422);
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

    /** @param array<string,mixed> $file */
    private function storeLogo(array $file): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return [null, 'Logo upload failed (error ' . $err . ').'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, 'Invalid logo upload.'];
        }
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return [null, 'Logo must be under 2 MB.'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        if (!isset($map[$mime])) {
            return [null, 'Logo must be PNG, JPG, WEBP or SVG.'];
        }
        $dir = App::basePath('public/uploads/branding');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [null, 'Cannot create branding upload folder.'];
        }
        // Remove previous logo files
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        $rel = 'uploads/branding/logo.' . $map[$mime];
        $dest = App::basePath('public/' . $rel);
        if (!move_uploaded_file($tmp, $dest)) {
            return [null, 'Could not store logo file.'];
        }
        @chmod($dest, 0644);
        return [$rel, null];
    }

    private function logoPublicUrl(?string $rel): ?string
    {
        if ($rel === null || $rel === '') {
            return null;
        }
        $abs = App::basePath('public/' . ltrim($rel, '/'));
        if (!is_file($abs)) {
            return null;
        }
        return base_url() . '/' . ltrim($rel, '/');
    }
}
