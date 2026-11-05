<?php

declare(strict_types=1);

namespace Ownswitch\Support;

use Ownswitch\App;

final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        $session = App::session();
        $user = current_user();
        $role = is_array($user) ? (string) ($user['role'] ?? '') : '';
        $killSwitch = false;
        try {
            $killSwitch = (new \Ownswitch\Services\Settings\SettingsService(App::db()))->bool('kill_switch');
        } catch (\Throwable) {
            $killSwitch = false;
        }
        return self::capture($layout, $data + [
            'content' => $content,
            'flashes' => $session->pullFlash(),
            'menu'    => Menu::for($role),
            'killSwitch' => $killSwitch,
        ] + ['title' => 'Ownswitch']);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $template)) {
            throw new \InvalidArgumentException('Bad template name');
        }
        $file = App::basePath("app/Views/{$template}.php");
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
    }
}
