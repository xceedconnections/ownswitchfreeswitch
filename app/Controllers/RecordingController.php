<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Media\RecordingService;

final class RecordingController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $svc = new RecordingService($this->db());
        try {
            $svc->syncPending();
        } catch (\Throwable $e) {
            error_log('[ownswitch] recordings sync: ' . $e->getMessage());
        }
        $res = $svc->repo()->paginate($this->scope($r), [], (int) $r->query('page', 1), 25);
        return $this->view('recordings/index', ['title' => 'Recordings', 'result' => $res]);
    }

    /** GET /recordings/{id}/play?download=1 : tenant check, path confinement, access log, then stream. */
    public function play(Request $r, array $p): Response
    {
        $svc = new RecordingService($this->db());
        $uid = (int) $this->user($r)['id'];
        $rec = $svc->findAuthorized((int) $p['id'], $this->scope($r));
        if ($rec === null) {
            return $this->notFound('Recording not found');   // same answer for "not yours" and "does not exist"
        }
        $abs = $rec['status'] === 'available' ? $svc->resolvePath((string) $rec['file_path']) : null;
        if ($abs === null) {
            return $this->notFound('Recording file is unavailable');
        }
        $download = $r->query('download') === '1';
        $svc->logAccess((int) $rec['id'], $uid, $download ? 'download' : 'play', $r->ip());
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg'][$ext] ?? 'application/octet-stream';
        $size = (int) filesize($abs);
        return Response::stream(static function () use ($abs): void {
            $fh = fopen($abs, 'rb');
            if ($fh) {
                while (!feof($fh)) {
                    echo fread($fh, 65536);
                }
                fclose($fh);
            }
        }, $mime, [
            'Content-Length' => (string) $size,
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="recording-' . (int) $rec['id'] . '.' . ($ext ?: 'bin') . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
