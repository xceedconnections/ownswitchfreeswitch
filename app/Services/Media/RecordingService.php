<?php

declare(strict_types=1);

namespace Ownswitch\Services\Media;

use Ownswitch\App;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Support\TenantScope;
use PDO;

/** Secure recording access: tenant-scoped lookup, path confinement, access audit. */
final class RecordingService
{
    private BaseRepository $repo;

    public function __construct(private PDO $pdo, private ?string $baseDir = null)
    {
        $this->baseDir ??= (string) App::config('app.recordings_dir', '');
        $this->repo = new BaseRepository($pdo, 'recordings',
            ['cdr_id', 'reseller_id', 'customer_id', 'file_path', 'sha256', 'duration_sec', 'size_bytes', 'status', 'retention_until'],
            'owned', ['file_path'], ['id', 'created_at', 'duration_sec']);
    }

    public function repo(): BaseRepository
    {
        return $this->repo;
    }

    /** @return array<string,mixed>|null */
    public function findAuthorized(int $id, TenantScope $scope): ?array
    {
        return $this->repo->find($id, $scope);
    }

    /**
     * Resolve a DB-relative path to an absolute file strictly inside the recordings directory.
     * Rejects traversal, symlink escapes and absolute paths. Returns null when unavailable.
     */
    public function resolvePath(string $relative): ?string
    {
        if ($this->baseDir === null || $this->baseDir === '' || !is_dir($this->baseDir)) {
            return null;
        }
        if ($relative === '' || str_contains($relative, "\0") || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $relative) || preg_match('#^([a-zA-Z]:|[\\\\/])#', $relative)) {
            return null;
        }
        $base = realpath($this->baseDir);
        $real = realpath($base . DIRECTORY_SEPARATOR . $relative);
        if ($base === false || $real === false || !is_file($real)) {
            return null;
        }
        $baseWithSep = rtrim($base, '\\/') . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $baseWithSep) ? $real : null;
    }

    public function logAccess(int $recordingId, int $userId, string $action, string $ip): void
    {
        $this->pdo->prepare('INSERT INTO recording_access_logs (recording_id, user_id, action, ip) VALUES (?,?,?,?)')
            ->execute([$recordingId, $userId, $action, $ip]);
    }

    /** Register a finished recording file (called by the media worker). */
    public function register(int $customerId, ?int $resellerId, ?int $cdrId, string $relativePath, int $durationSec, int $retentionDays): int
    {
        $abs = $this->resolvePath($relativePath);
        $hash = $abs ? hash_file('sha256', $abs) : null;
        $size = $abs ? (int) filesize($abs) : 0;
        return $this->repo->insert([
            'customer_id' => $customerId, 'reseller_id' => $resellerId, 'cdr_id' => $cdrId, 'file_path' => $relativePath,
            'sha256' => $hash ?: null, 'size_bytes' => $size, 'duration_sec' => $durationSec,
            'status' => $abs ? 'available' : 'missing', 'retention_until' => gmdate('Y-m-d', time() + $retentionDays * 86400),
        ]);
    }
}
