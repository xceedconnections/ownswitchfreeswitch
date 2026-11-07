<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/** Run a fixed root helper. Arguments are shell-escaped. */
final class SudoHelper
{
    /** @param list<string> $args @return array{ok:bool,detail:string,fields:array<string,string>} */
    public static function run(string $bin, array $args): array
    {
        if (!is_file($bin)) {
            return [
                'ok' => false,
                'detail' => 'Helper is not installed yet. Open Settings → Version and click Update now once, then try again.',
                'fields' => [],
            ];
        }
        $cmd = 'sudo -n ' . escapeshellarg($bin);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $text = trim(implode("\n", $out));
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, -4000);
        }
        $fields = [];
        foreach ($out as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $fields[trim($k)] = trim($v);
        }
        $detail = $fields['message'] ?? ($text !== '' ? $text : ($code === 0 ? 'OK' : 'Command failed'));
        return ['ok' => $code === 0, 'detail' => $detail, 'fields' => $fields];
    }
}
