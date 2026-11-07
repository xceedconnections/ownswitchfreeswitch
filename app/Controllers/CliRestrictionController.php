<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;

/** Admin lists of caller IDs that a SIP user is not allowed to present. */
final class CliRestrictionController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $groups = [];
        try {
            $groups = $this->db()->query(
                'SELECT g.id, g.name, g.status, g.notes,
                        (SELECT COUNT(*) FROM cli_restriction_numbers n WHERE n.cli_restriction_id = g.id) AS number_count
                 FROM cli_restrictions g
                 ORDER BY g.name
                 LIMIT 500'
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] cli restriction index: ' . $e->getMessage());
            return $this->view('error', [
                'title' => 'CLI Restriction',
                'message' => 'CLI restriction tables are missing. On the server run: '
                    . 'sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/migrate.php',
                'code' => 500,
            ], 500);
        }
        return $this->view('cli_restrictions/index', [
            'title' => 'CLI Restriction',
            'groups' => $groups,
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        return $this->view('cli_restrictions/form', [
            'title' => 'New CLI restriction',
            'group' => null,
            'errors' => [],
            'data' => ['name' => '', 'status' => 'active', 'notes' => ''],
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $name = trim((string) $r->input('name', ''));
        $status = (string) $r->input('status', 'active') === 'disabled' ? 'disabled' : 'active';
        $notes = trim((string) $r->input('notes', ''));
        if ($name === '') {
            return $this->view('cli_restrictions/form', [
                'title' => 'New CLI restriction',
                'group' => null,
                'errors' => ['_form' => 'Name is required.'],
                'data' => $r->post,
            ], 422);
        }
        try {
            $st = $this->db()->prepare('INSERT INTO cli_restrictions (name, status, notes) VALUES (?,?,?)');
            $st->execute([$name, $status, $notes !== '' ? mb_substr($notes, 0, 255) : null]);
            $id = (int) $this->db()->lastInsertId();
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062) ? 'A list with that name already exists.' : 'Could not save the list.';
            return $this->view('cli_restrictions/form', [
                'title' => 'New CLI restriction',
                'group' => null,
                'errors' => ['_form' => $msg],
                'data' => $r->post,
            ], 422);
        }
        $this->audit($r, 'create', 'cli_restrictions', $id, null, ['name' => $name]);
        return $this->redirect($r, '/cli-restrictions/' . $id, 'CLI restriction list created.');
    }

    public function numbers(Request $r, array $p): Response
    {
        $g = $this->group((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $q = trim((string) $r->query('q', ''));
        $sql = 'SELECT id, cli_digits, note FROM cli_restriction_numbers WHERE cli_restriction_id = ?';
        $params = [(int) $g['id']];
        if ($q !== '') {
            $sql .= ' AND cli_digits LIKE ?';
            $params[] = '%' . addcslashes(preg_replace('/\D+/', '', $q) ?? '', '%_\\') . '%';
        }
        $sql .= ' ORDER BY cli_digits LIMIT 2000';
        $st = $this->db()->prepare($sql);
        $st->execute($params);
        return $this->view('cli_restrictions/numbers', [
            'title' => (string) $g['name'],
            'group' => $g,
            'numbers' => $st->fetchAll() ?: [],
            'q' => $q,
            'canWrite' => $this->canWrite($r),
        ]);
    }

    public function update(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->group((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $name = trim((string) $r->input('name', ''));
        $status = (string) $r->input('status', 'active') === 'disabled' ? 'disabled' : 'active';
        $notes = trim((string) $r->input('notes', ''));
        if ($name === '') {
            return $this->redirect($r, '/cli-restrictions/' . (int) $g['id'], 'Name is required.', 'danger');
        }
        try {
            $this->db()->prepare('UPDATE cli_restrictions SET name = ?, status = ?, notes = ? WHERE id = ?')
                ->execute([$name, $status, $notes !== '' ? mb_substr($notes, 0, 255) : null, (int) $g['id']]);
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062) ? 'A list with that name already exists.' : 'Could not save the list.';
            return $this->redirect($r, '/cli-restrictions/' . (int) $g['id'], $msg, 'danger');
        }
        return $this->redirect($r, '/cli-restrictions/' . (int) $g['id'], 'CLI restriction list saved.');
    }

    public function destroy(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $id = (int) $p['id'];
        $this->db()->prepare('DELETE FROM cli_restrictions WHERE id = ?')->execute([$id]);
        $this->audit($r, 'delete', 'cli_restrictions', $id, null, null);
        return $this->redirect($r, '/cli-restrictions', 'CLI restriction list deleted.');
    }

    public function addNumbers(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $g = $this->group((int) $p['id']);
        if ($g === null) {
            return $this->notFound();
        }
        $raw = (string) $r->input('numbers', '');
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $ins = $this->db()->prepare(
            'INSERT IGNORE INTO cli_restriction_numbers (cli_restriction_id, cli_digits) VALUES (?,?)'
        );
        $n = 0;
        foreach ($parts as $part) {
            $digits = preg_replace('/\D+/', '', (string) $part) ?? '';
            if ($digits === '' || strlen($digits) > 32) {
                continue;
            }
            $ins->execute([(int) $g['id'], $digits]);
            $n += $ins->rowCount() > 0 ? 1 : 0;
        }
        return $this->redirect(
            $r,
            '/cli-restrictions/' . (int) $g['id'],
            $n > 0 ? "Added {$n} caller ID" . ($n === 1 ? '' : 's') . '.' : 'No new caller IDs were added.',
            $n > 0 ? 'success' : 'warning'
        );
    }

    public function deleteNumber(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $gid = (int) $p['id'];
        $nid = (int) $p['numberId'];
        $this->db()->prepare('DELETE FROM cli_restriction_numbers WHERE id = ? AND cli_restriction_id = ?')
            ->execute([$nid, $gid]);
        return $this->redirect($r, '/cli-restrictions/' . $gid, 'Caller ID removed.');
    }

    /** @return array<string,mixed>|null */
    private function group(int $id): ?array
    {
        $st = $this->db()->prepare('SELECT * FROM cli_restrictions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function canWrite(Request $r): bool
    {
        return in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin'], true);
    }
}
