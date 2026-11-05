<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Support\EntityCode;
use Ownswitch\Support\Validator;

/**
 * Generic, spec-driven list/create/edit/delete. Subclasses declare fields; tenancy is enforced by the
 * repository (row scope) plus `ownership()` for tenant-owned rows. Authorisation by role is done in routes.
 */
abstract class CrudController extends BaseController
{
    protected string $base = '';
    protected string $singular = 'Item';
    protected string $plural = 'Items';
    /** @var list<string> roles allowed to create/edit/delete (read-only for others) */
    protected array $writeRoles = ['super_admin', 'admin'];
    protected string $defaultSort = 'id';
    protected string $emptyHint = '';

    abstract protected function repo(): BaseRepository;

    /** @return list<array<string,mixed>> */
    abstract protected function fields(Request $r): array;

    /** @return list<string> extra query-string filter columns (whitelisted again by the repository) */
    protected function filterColumns(): array
    {
        return [];
    }

    /** Hook: adjust validated data before save; throw \DomainException for a user-visible form error. */
    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        return null;
    }

    /** Hook: return an error string to block deletion. */
    protected function beforeDelete(Request $r, array $row): ?string
    {
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
    }

    protected function canWrite(Request $r): bool
    {
        return in_array($this->user($r)['role'] ?? '', $this->writeRoles, true);
    }

    /** Extra variables for list/form views. */
    protected function extra(Request $r): array
    {
        return [];
    }

    public function index(Request $r, array $p = []): Response
    {
        $filters = [];
        foreach ($this->filterColumns() as $c) {
            $v = $r->query($c);
            if (is_string($v) && $v !== '') {
                $filters[$c] = $v;
            }
        }
        $q = is_string($r->query('q')) ? trim((string) $r->query('q')) : '';
        try {
            $fields = $this->fields($r);
            $res = $this->repo()->paginate(
                $this->scope($r), $filters, (int) $r->query('page', 1), 25,
                is_string($r->query('sort')) ? (string) $r->query('sort') : $this->defaultSort,
                is_string($r->query('dir')) ? (string) $r->query('dir') : 'desc',
                $q
            );
            $res['rows'] = $this->enrichRows($r, $res['rows']);
        } catch (\PDOException $e) {
            $hint = (stripos($e->getMessage(), "doesn't exist") !== false || stripos($e->getMessage(), 'Base table') !== false)
                ? 'Database tables missing. On the server run: sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/migrate.php'
                : 'Database error. Check logs / run migrate.php.';
            return $this->view('error', ['title' => 'Database', 'message' => $hint, 'code' => 500], 500);
        }
        return $this->view('crud/list', [
            'title' => $this->plural, 'base' => $this->base, 'singular' => $this->singular, 'plural' => $this->plural,
            'fields' => $fields, 'result' => $res, 'q' => $q, 'filters' => $filters, 'canWrite' => $this->canWrite($r),
            'emptyHint' => $this->emptyHint, 'query' => $r->query,
        ] + $this->extra($r));
    }

    /**
     * Hook: decorate list rows (e.g. live latency). Must not throw.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    protected function enrichRows(Request $r, array $rows): array
    {
        return $rows;
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        return $this->form($r, null, [], []);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        return $this->save($r, null);
    }

    public function edit(Request $r, array $p): Response
    {
        $row = $this->repo()->find((int) $p['id'], $this->scope($r));
        if ($row === null) {
            return $this->notFound();
        }
        return $this->form($r, $row, $row, []);
    }

    public function update(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $row = $this->repo()->find((int) $p['id'], $this->scope($r));
        if ($row === null) {
            return $this->notFound();
        }
        return $this->save($r, $row);
    }

    public function destroy(Request $r, array $p): Response
    {
        if (!$this->canWrite($r)) {
            return $this->forbidden();
        }
        $row = $this->repo()->find((int) $p['id'], $this->scope($r));
        if ($row === null) {
            return $this->notFound();
        }
        if (($err = $this->beforeDelete($r, $row)) !== null) {
            return $this->redirect($r, $this->base, $err, 'danger');
        }
        try {
            $this->repo()->delete((int) $row['id'], $this->scope($r));
        } catch (\PDOException $e) {
            return $this->redirect($r, $this->base, "Cannot delete {$this->singular}: it is referenced by other records.", 'danger');
        }
        $this->audit($r, 'delete', $this->repo()->table(), (int) $row['id'], $row, null);
        $this->afterDelete($r, $row);
        return $this->redirect($r, $this->base, "{$this->singular} deleted.");
    }

    /** @param array<string,mixed>|null $existing */
    private function save(Request $r, ?array $existing): Response
    {
        $fields = $this->fields($r);
        [$data, $errors] = Validator::validate($fields, $r->post, $existing !== null);
        if ($errors) {
            return $this->form($r, $existing, $r->post, $errors, 422);
        }
        try {
            $data = $this->ownership($r, $data, $existing);
            $data = $this->prepare($r, $data, $existing);
            $msg = null;
            if ($existing === null) {
                $id = $this->repo()->insert($data);
            } else {
                $id = (int) $existing['id'];
                $this->repo()->update($id, $data, $this->scope($r));
            }
            $msg = $this->afterSave($r, $id, $data, $existing);
        } catch (\DomainException $e) {
            return $this->form($r, $existing, $r->post, ['_form' => $e->getMessage()], 422);
        } catch (\PDOException $e) {
            $dup = ($e->errorInfo[1] ?? 0) === 1062;
            $msg = $dup ? 'A record with the same unique value already exists.' : 'Database error; the change was not saved.';
            $sqlMsg = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (!$dup && $sqlMsg !== '') {
                if (stripos($sqlMsg, 'Unknown column') !== false) {
                    $msg = 'Database schema is outdated (missing column). On the server run: '
                        . 'sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/migrate.php';
                } else {
                    // Surface a short SQL hint (no credentials); helps ops without enabling full debug.
                    $hint = preg_replace('/\s+/', ' ', $sqlMsg) ?? $sqlMsg;
                    $msg .= ' (' . mb_substr($hint, 0, 180) . ')';
                }
            }
            error_log('[ownswitch] CRUD PDO ' . $this->base . ': ' . $sqlMsg);
            return $this->form($r, $existing, $r->post, ['_form' => $msg], 422);
        }
        $this->audit($r, $existing ? 'update' : 'create', $this->repo()->table(), $id, $existing, $data);
        return $this->redirect($r, $this->base, ($msg ?? "{$this->singular} saved."));
    }

    /**
     * Tenant-owned rows (customer_id + reseller_id): the customer must be visible to the actor and the
     * reseller is DERIVED from it. Client-supplied reseller_id is ignored.
     */
    protected function ownership(Request $r, array $data, ?array $existing): array
    {
        if ($this->repo()->tenantMode() !== 'owned') {
            return $data;
        }
        $scope = $this->scope($r);
        if ($existing !== null) {
            $data['customer_id'] = $existing['customer_id'];
            $data['reseller_id'] = $existing['reseller_id'];
            return $data;
        }
        $cid = $scope->isCustomerRole() ? $scope->customerId : (isset($data['customer_id']) ? (int) $data['customer_id'] : null);
        $cust = $cid ? (new CustomerRepository($this->db()))->find($cid, $scope) : null;
        if ($cust === null) {
            throw new \DomainException('Select a valid customer.');
        }
        $data['customer_id'] = (int) $cust['id'];
        $data['reseller_id'] = $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null;
        return $data;
    }

    /** @return array<int|string,string> id => label of customers visible to the actor */
    protected function customerOptions(Request $r): array
    {
        $out = [];
        foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
            $out[$c['id']] = (string) $c['name'];
        }
        return $out;
    }

    /**
     * Auto-fill an internal unique `code` from the friendly name so operators never type codes.
     * Keeps existing code on edit.
     */
    protected function autoCode(array $data, ?array $existing, string $table): array
    {
        if ($existing !== null) {
            $data['code'] = $existing['code'];
            return $data;
        }
        $base = EntityCode::fromName((string) ($data['name'] ?? 'ITEM'));
        $data['code'] = EntityCode::unique($this->db(), $table, $base);
        return $data;
    }

    /** @param array<string,mixed>|null $existing @param array<string,mixed> $values @param array<string,string> $errors */
    protected function form(Request $r, ?array $existing, array $values, array $errors, int $status = 200): Response
    {
        $values = $this->formValues($r, $existing, $values);
        $canWrite = $this->canWrite($r);
        $title = $existing
            ? (($canWrite ? 'Edit ' : 'View ') . $this->singular)
            : ('New ' . $this->singular);
        return $this->view('crud/form', [
            'title' => $title,
            'base' => $this->base,
            'singular' => $this->singular,
            'fields' => $this->fields($r),
            'values' => $values,
            'errors' => $errors,
            'existing' => $existing,
            'canWrite' => $canWrite,
        ] + $this->extra($r), $status);
    }

    /**
     * Hook: normalise values before the form renders (aliases, defaults, etc.).
     *
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        return $values;
    }
}
