<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Support\SpreadsheetReader;
use RuntimeException;

/** Persist uploaded spreadsheet for multi-step map → preview → commit. */
final class ImportUploadStore
{
    public static function dir(): string
    {
        $dir = \Ownswitch\App::basePath('storage/imports');
        if ($dir === '/storage/imports' || $dir === 'storage/imports') {
            $dir = sys_get_temp_dir() . '/ownswitch-imports';
        }
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create import storage.');
        }
        return $dir;
    }

    /** @return array{token:string,headers:list<string>,sample:list<list<string>>,row_count:int} */
    public static function storeUpload(array $file): array
    {
        $parsed = SpreadsheetReader::fromUpload($file);
        if ($parsed['error']) {
            throw new RuntimeException($parsed['error']);
        }
        $token = bin2hex(random_bytes(16));
        $path = self::dir() . '/' . $token . '.json';
        $payload = [
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'created' => time(),
        ];
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        return [
            'token' => $token,
            'headers' => $parsed['headers'],
            'sample' => array_slice($parsed['rows'], 0, 5),
            'row_count' => count($parsed['rows']),
        ];
    }

    /** @return array{headers:list<string>,rows:list<list<string>>} */
    public static function load(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException('Invalid import token.');
        }
        $path = self::dir() . '/' . $token . '.json';
        if (!is_file($path)) {
            throw new RuntimeException('Import expired — upload the file again.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['headers'], $data['rows'])) {
            throw new RuntimeException('Corrupt import payload.');
        }
        return ['headers' => $data['headers'], 'rows' => $data['rows']];
    }

    public static function forget(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink(self::dir() . '/' . $token . '.json');
        }
    }
}
