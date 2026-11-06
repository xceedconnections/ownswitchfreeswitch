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
        $res = $svc->listFor($this->scope($r), (int) $r->query('page', 1), 25);
        return $this->view('recordings/index', [
            'title' => 'Recordings',
            'result' => $res,
            'canDelete' => $this->scope($r)->isStaff(),
            'query' => $r->query,
        ]);
    }

    /** Admin: delete the recording row and the wav file. */
    public function delete(Request $r, array $p): Response
    {
        if (!$this->scope($r)->isStaff()) {
            return $this->forbidden();
        }
        $id = (int) ($p['id'] ?? 0);
        $svc = new RecordingService($this->db());
        $ok = $svc->delete($id, $this->scope($r));
        if ($ok) {
            $this->audit($r, 'recording_delete', 'recordings', $id, null, null);
        }
        return $this->redirect(
            $r,
            '/recordings',
            $ok ? 'Recording deleted.' : 'Recording was not deleted.',
            $ok ? 'success' : 'danger'
        );
    }

    /** Admin: delete the checked recordings and their wav files. */
    public function deleteSelected(Request $r, array $p = []): Response
    {
        if (!$this->scope($r)->isStaff()) {
            return $this->forbidden();
        }
        $raw = $r->input('ids', []);
        $ids = [];
        if (is_array($raw)) {
            $ids = array_map('intval', $raw);
        }
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if (count($ids) > 200) {
            $ids = array_slice($ids, 0, 200);
        }
        $page = max(1, (int) $r->input('page', 1));
        $back = '/recordings' . ($page > 1 ? '?page=' . $page : '');
        if ($ids === []) {
            return $this->redirect($r, $back, 'Select at least one recording.', 'danger');
        }
        $n = (new RecordingService($this->db()))->deleteMany($ids, $this->scope($r));
        $this->audit($r, 'recording_delete', 'recordings', null, null, ['count' => $n, 'ids' => $ids]);
        return $this->redirect(
            $r,
            $back,
            $n > 0 ? 'Deleted ' . $n . ' recording' . ($n === 1 ? '' : 's') . '.' : 'No recordings were deleted.',
            $n > 0 ? 'success' : 'danger'
        );
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
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = $r->header('range');
        if (!$download && is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $suffix = (int) $m[2];
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = (int) $m[2];
                }
            }
            if ($start >= $size || $start > $end) {
                return new Response('', 416, [
                    'Content-Range' => 'bytes */' . $size,
                    'Content-Type' => $mime,
                ]);
            }
            $end = min($end, $size - 1);
            $status = 206;
        }
        $length = $size === 0 ? 0 : ($end - $start + 1);
        $headers = [
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="recording-' . (int) $rec['id'] . '.' . ($ext ?: 'bin') . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $resp = Response::stream(static function () use ($abs, $start, $length): void {
            $fh = fopen($abs, 'rb');
            if (!$fh) {
                return;
            }
            if ($start > 0) {
                fseek($fh, $start);
            }
            $left = $length;
            while ($left > 0 && !feof($fh)) {
                $chunk = fread($fh, min(65536, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $left -= strlen($chunk);
            }
            fclose($fh);
        }, $mime, $headers);
        $resp->status = $status;
        return $resp;
    }
}
