<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Services\Fraud\SignalingFraudService;

final class FraudController extends BaseController
{
    private function events(): BaseRepository
    {
        return new BaseRepository($this->db(), 'fraud_events', ['status', 'customer_id', 'rule_code', 'severity', 'acknowledged_by'], 'owned', ['summary', 'rule_code'], ['id', 'created_at', 'severity']);
    }

    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $filters = is_string($r->query('status')) && $r->query('status') !== '' ? ['status' => $r->query('status')] : [];
        $res = $this->events()->paginate($scope, $filters, (int) $r->query('page', 1), 25);
        $rules = $scope->isStaff() ? $this->db()->query('SELECT * FROM fraud_rules ORDER BY id')->fetchAll() : [];
        return $this->view('fraud/index', ['title' => 'Fraud Alerts', 'result' => $res, 'rules' => $rules, 'canManage' => $scope->isStaff(), 'filters' => $filters]);
    }

    public function scan(Request $r, array $p = []): Response
    {
        $n = (new SignalingFraudService($this->db()))->scan();
        $this->audit($r, 'fraud_scan', 'fraud_events', null, null, ['new_events' => $n]);
        return $this->redirect($r, '/fraud', $n > 0 ? "{$n} new fraud event(s) detected." : 'Scan complete: nothing new.');
    }

    public function acknowledge(Request $r, array $p): Response
    {
        $scope = $this->scope($r);
        $ok = $this->events()->update((int) $p['id'], ['status' => 'acknowledged', 'acknowledged_by' => (int) $this->user($r)['id']], $scope);
        if ($ok) {
            $this->audit($r, 'fraud_ack', 'fraud_events', (int) $p['id']);
        }
        return $this->redirect($r, '/fraud', $ok ? 'Event acknowledged.' : 'Event not found.', $ok ? 'success' : 'danger');
    }

    /** Update an active fraud rule (threshold / window / action / status). */
    public function updateRule(Request $r, array $p): Response
    {
        if (!in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin'], true)) {
            return $this->forbidden();
        }
        $id = (int) ($p['id'] ?? 0);
        $st = $this->db()->prepare('SELECT * FROM fraud_rules WHERE id = ?');
        $st->execute([$id]);
        $before = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$before) {
            return $this->redirect($r, '/fraud', 'Rule not found.', 'danger');
        }
        $threshold = trim((string) ($r->post['threshold'] ?? ''));
        $window = trim((string) ($r->post['window_seconds'] ?? ''));
        $action = (string) ($r->post['action'] ?? '');
        $status = (string) ($r->post['status'] ?? '');
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $threshold)) {
            return $this->redirect($r, '/fraud', 'Threshold must be a non-negative number.', 'danger');
        }
        if (!preg_match('/^\d{1,7}$/', $window) || (int) $window < 30) {
            return $this->redirect($r, '/fraud', 'Window must be at least 30 seconds.', 'danger');
        }
        if (!in_array($action, ['alert', 'suspend'], true) || !in_array($status, ['active', 'disabled'], true)) {
            return $this->redirect($r, '/fraud', 'Invalid action or status.', 'danger');
        }
        $this->db()->prepare(
            'UPDATE fraud_rules SET threshold = ?, window_seconds = ?, action = ?, status = ? WHERE id = ?'
        )->execute([$threshold, (int) $window, $action, $status, $id]);
        $this->audit($r, 'fraud_rule_update', 'fraud_rules', $id, $before, [
            'threshold' => $threshold, 'window_seconds' => (int) $window, 'action' => $action, 'status' => $status,
        ]);
        return $this->redirect($r, '/fraud', 'Fraud rule updated.');
    }
}
