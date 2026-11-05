<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;

/** Read-only management audit trail (rates, balances, routes, permissions). */
final class AuditLogController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $q = is_string($r->query('q')) ? trim((string) $r->query('q')) : '';
        $action = is_string($r->query('action')) ? trim((string) $r->query('action')) : '';
        $entity = is_string($r->query('entity_type')) ? trim((string) $r->query('entity_type')) : '';
        $page = max(1, (int) $r->query('page', 1));
        $per = 50;
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR CAST(a.entity_id AS CHAR) LIKE ? OR a.ip LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($action !== '' && preg_match('/^[a-z0-9_]{1,64}$/i', $action)) {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($entity !== '' && preg_match('/^[a-z0-9_]{1,64}$/i', $entity)) {
            $where[] = 'a.entity_type = ?';
            $params[] = $entity;
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $stc = $this->db()->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$sqlWhere}");
            $stc->execute($params);
            $total = (int) $stc->fetchColumn();
            $st = $this->db()->prepare(
                "SELECT a.*, u.email AS actor_email
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.actor_user_id
                 WHERE {$sqlWhere}
                 ORDER BY a.id DESC
                 LIMIT {$per} OFFSET " . (($page - 1) * $per)
            );
            $st->execute($params);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] audit log: ' . $e->getMessage());
            $total = 0;
            $rows = [];
        }
        return $this->view('audit/index', [
            'title' => 'Audit Log',
            'result' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per))],
            'q' => $q,
            'action' => $action,
            'entity_type' => $entity,
            'query' => $r->query,
        ]);
    }
}
