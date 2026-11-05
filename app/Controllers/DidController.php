<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\DidRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RatingService;

/**
 * DID Providers / DID inventory / Assignments (inbound → SIP user via FreeSWITCH dialplan).
 * kind: providers | dids | assignments
 */
final class DidController extends CrudController
{
    public function __construct(private string $kind = 'dids')
    {
        $this->base = match ($kind) {
            'providers' => '/did-providers',
            'assignments' => '/did-assignments',
            default => '/dids',
        };
        $this->singular = match ($kind) {
            'providers' => 'DID Provider',
            'assignments' => 'DID Assignment',
            default => 'DID',
        };
        $this->plural = match ($kind) {
            'providers' => 'DID Providers',
            'assignments' => 'DID Assignments',
            default => 'DIDs',
        };
        $this->emptyHint = match ($kind) {
            'providers' => 'No DID providers. Link the vendor you buy numbers from.',
            'assignments' => 'No assignments. Assign a DID to a User + SIP User so inbound calls ring them.',
            default => 'No DIDs. Add numbers, set setup/monthly rates, then assign to users.',
        };
        if ($kind === 'assignments') {
            $this->writeRoles = ['super_admin', 'admin', 'reseller'];
        }
    }

    protected function repo(): BaseRepository
    {
        return match ($this->kind) {
            'providers' => DidRepository::providers($this->db()),
            'assignments' => DidRepository::assignments($this->db()),
            default => DidRepository::dids($this->db()),
        };
    }

    protected function filterColumns(): array
    {
        return match ($this->kind) {
            'providers' => ['status'],
            'assignments' => ['customer_id', 'status'],
            default => ['did_provider_id', 'status'],
        };
    }

    protected function fields(Request $r): array
    {
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'];
        if ($this->kind === 'providers') {
            $carriers = ['' => '— optional —'];
            foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
                $carriers[(string) $c['id']] = $c['name'];
            }
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'carrier_id', 'label' => 'Linked trunk provider', 'type' => 'select', 'options' => $carriers, 'list' => true],
                ['name' => 'notes', 'label' => 'Notes', 'max' => 255],
                $status,
            ];
        }
        if ($this->kind === 'dids') {
            $provs = ['' => '— optional —'];
            foreach ($this->db()->query('SELECT id, name FROM did_providers ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $provs[(string) $p['id']] = $p['name'];
            }
            return [
                ['name' => 'did_provider_id', 'label' => 'DID Provider', 'type' => 'select', 'options' => $provs, 'list' => true],
                ['name' => 'number_e164', 'label' => 'Number', 'required' => true, 'max' => 32, 'list' => true, 'help' => 'E.164 or digits (e.g. 442071234567)'],
                ['name' => 'country', 'label' => 'Country', 'max' => 64, 'list' => true],
                ['name' => 'setup_fee', 'label' => 'Setup fee', 'type' => 'number', 'required' => true, 'default' => '0'],
                ['name' => 'monthly_fee', 'label' => 'Monthly fee', 'type' => 'number', 'required' => true, 'default' => '0', 'list' => true],
                ['name' => 'per_minute', 'label' => 'Inbound per minute', 'type' => 'number', 'required' => true, 'default' => '0', 'help' => 'Charged on inbound answered CDRs when set'],
                ['name' => 'currency', 'label' => 'Currency', 'max' => 3, 'required' => true, 'default' => 'USD'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'available', 'options' => [
                    'available' => 'Available',
                    'assigned' => 'Assigned',
                    'reserved' => 'Reserved',
                    'disabled' => 'Disabled',
                ]],
                ['name' => 'notes', 'label' => 'Notes', 'max' => 255],
            ];
        }
        // assignments
        $dids = [];
        foreach ($this->db()->query("SELECT id, number_e164, status FROM dids WHERE status IN ('available','assigned','reserved') ORDER BY number_norm LIMIT 1000")->fetchAll() as $d) {
            $dids[(string) $d['id']] = $d['number_e164'] . ' (' . $d['status'] . ')';
        }
        $customers = $this->customerOptions($r);
        $sips = ['' => '— select SIP user —'];
        foreach ($this->db()->query('SELECT id, username, customer_id FROM sip_accounts WHERE status = \'active\' ORDER BY username LIMIT 2000')->fetchAll() as $s) {
            $sips[(string) $s['id']] = $s['username'] . ' (user #' . $s['customer_id'] . ')';
        }
        return [
            ['name' => 'did_id', 'label' => 'DID', 'type' => 'select', 'required' => true, 'options' => $dids, 'list' => true, 'readonly_on_edit' => true],
            ['name' => 'customer_id', 'label' => 'User', 'type' => 'select', 'required' => true, 'options' => $customers, 'list' => true],
            ['name' => 'sip_account_id', 'label' => 'Route to SIP user', 'type' => 'select', 'required' => true, 'options' => $sips, 'list' => true, 'help' => 'Inbound INVITE bridges to this registered FreeSWITCH user'],
            ['name' => 'dest_type', 'label' => 'Call flow', 'type' => 'select', 'required' => true, 'default' => 'sip_user', 'options' => [
                'sip_user' => 'SIP User (registered endpoint)',
            ], 'list' => true],
            $status,
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'providers') {
            if (($data['carrier_id'] ?? '') === '') {
                $data['carrier_id'] = null;
            }
        }
        if ($this->kind === 'dids') {
            if (($data['did_provider_id'] ?? '') === '') {
                $data['did_provider_id'] = null;
            }
            $num = preg_replace('/[^\d+]/', '', (string) ($data['number_e164'] ?? '')) ?? '';
            $norm = preg_replace('/\D+/', '', $num) ?? '';
            if ($norm === '') {
                throw new \DomainException('DID number must contain digits.');
            }
            $data['number_e164'] = $num;
            $data['number_norm'] = $norm;
            foreach (['setup_fee', 'monthly_fee', 'per_minute'] as $k) {
                RatingService::assertDecimal((string) ($data[$k] ?? '0'), $k);
            }
        }
        if ($this->kind === 'assignments') {
            $sipId = (int) ($data['sip_account_id'] ?? 0);
            $custId = (int) ($data['customer_id'] ?? 0);
            $st = $this->db()->prepare('SELECT id, username, customer_id FROM sip_accounts WHERE id = ?');
            $st->execute([$sipId]);
            $sip = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$sip || (int) $sip['customer_id'] !== $custId) {
                throw new \DomainException('SIP user must belong to the selected User.');
            }
            $data['destination'] = (string) $sip['username'];
            $data['dest_type'] = 'sip_user';
            if ($existing === null) {
                $data['assigned_at'] = gmdate('Y-m-d H:i:s');
                $data['next_billing_at'] = gmdate('Y-m-d', strtotime('+1 month'));
            }
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'assignments') {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                $this->db()->prepare("UPDATE dids SET status = 'assigned' WHERE id = ?")->execute([(int) $row['did_id']]);
                if ($existing === null) {
                    $this->chargeSetup((int) $row['did_id'], (int) $row['customer_id'], (int) $this->user($r)['id']);
                }
                $this->pushAlias((int) $row['did_id']);
            }
            return 'DID assigned. Inbound to this number rings the SIP user.';
        }
        if ($this->kind === 'dids' && $existing !== null) {
            // re-push alias if assigned
            $this->pushAlias($id);
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'assignments') {
            $this->db()->prepare("UPDATE dids SET status = 'available' WHERE id = ?")->execute([(int) $row['did_id']]);
            $this->removeAlias((int) $row['did_id']);
        }
        if ($this->kind === 'dids') {
            $this->removeAlias((int) $row['id']);
        }
    }

    private function chargeSetup(int $didId, int $customerId, int $actorId): void
    {
        $did = $this->db()->prepare('SELECT * FROM dids WHERE id = ?');
        $did->execute([$didId]);
        $d = $did->fetch(\PDO::FETCH_ASSOC);
        $cust = $this->db()->prepare('SELECT * FROM customers WHERE id = ?');
        $cust->execute([$customerId]);
        $c = $cust->fetch(\PDO::FETCH_ASSOC);
        if (!$d || !$c) {
            return;
        }
        $fee = (string) $d['setup_fee'];
        if (bccomp($fee, '0', 6) <= 0) {
            return;
        }
        try {
            (new LedgerService($this->db()))->post([
                'customer_id' => $customerId,
                'reseller_id' => $c['reseller_id'] !== null ? (int) $c['reseller_id'] : null,
                'entry_type' => 'adjustment',
                'amount' => RatingService::round(bcmul($fee, '-1', 6), 6),
                'currency' => (string) ($d['currency'] ?: $c['currency']),
                'idempotency_key' => 'did-setup:' . $didId . ':' . $customerId,
                'description' => 'DID setup fee ' . $d['number_e164'],
                'created_by' => $actorId,
            ]);
        } catch (\Throwable $e) {
            App::session()->flash('warning', 'DID assigned but setup fee ledger failed: ' . $e->getMessage());
        }
    }

    private function pushAlias(int $didId): void
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return;
        }
        $st = $this->db()->prepare(
            "SELECT d.number_norm, a.destination, a.status AS astatus, s.username, s.domain
             FROM dids d
             JOIN did_assignments a ON a.did_id = d.id AND a.status = 'active'
             LEFT JOIN sip_accounts s ON s.id = a.sip_account_id
             WHERE d.id = ?"
        );
        $st->execute([$didId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->removeAlias($didId);
            return;
        }
        $user = (string) ($row['username'] ?: $row['destination']);
        $domain = (string) ($row['domain'] ?: App::config('app.integrations.opensips.sip_domain', 'localhost'));
        if ($user === '') {
            return;
        }
        try {
            $prov->syncDidAlias((string) $row['number_norm'], $user, $domain);
        } catch (\Throwable $e) {
            App::session()->flash('warning', 'DID saved but FreeSWITCH sync failed: ' . $e->getMessage());
        }
    }

    private function removeAlias(int $didId): void
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return;
        }
        $st = $this->db()->prepare('SELECT number_norm FROM dids WHERE id = ?');
        $st->execute([$didId]);
        $norm = (string) ($st->fetchColumn() ?: '');
        if ($norm === '') {
            return;
        }
        try {
            $prov->removeDidAlias($norm);
        } catch (\Throwable) {
        }
    }
}
