<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\ResellerRepository;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\SipCodecs;
use Ownswitch\Support\TenantScope;

/** Magnus Clients → Users — core fields + portal login (email/password). */
final class CustomerController extends CrudController
{
    protected string $base = '/users';
    protected string $singular = 'User';
    protected string $plural = 'Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No users yet. Create User → Email + Portal password → Plan → credit. A matching Dynamic SIP user is created automatically (same username/password); edit under SIP Users anytime.';

    protected function repo(): BaseRepository
    {
        return new CustomerRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status', 'billing_mode'];
    }

    protected function fields(Request $r): array
    {
        $staff = $this->scope($r)->isStaff();
        $f = [
            ['name' => 'name', 'label' => 'Username', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'Portal login username (also accepted at /login)', 'section' => 'Account'],
            ['name' => 'contact_email', 'label' => 'Email (portal login)', 'type' => 'email', 'required' => true, 'max' => 190, 'list' => true, 'help' => 'Email or Username both work at /login', 'section' => 'Account'],
            [
                'name' => 'portal_password',
                'label' => 'Portal password',
                'type' => 'password',
                'max' => 128,
                'help' => 'Required on create. On edit, leave blank to keep current. Any length and characters.',
                'section' => 'Account',
            ],
            ['name' => 'full_name', 'label' => 'Full name', 'max' => 190, 'section' => 'Personal information'],
            ['name' => 'company_name', 'label' => 'Company name', 'max' => 190, 'list' => true, 'section' => 'Personal information'],
            ['name' => 'phone', 'label' => 'Phone number', 'max' => 32, 'section' => 'Personal information'],
            ['name' => 'teams_id', 'label' => 'Microsoft Teams ID', 'max' => 190, 'help' => 'Teams user / UPN or object ID', 'section' => 'Personal information'],
            ['name' => 'robocall_id', 'label' => 'Robocall ID', 'max' => 64, 'section' => 'Personal information'],
            ['name' => 'frn_id', 'label' => 'FRN ID', 'max' => 64, 'help' => 'FCC Registration Number', 'section' => 'Personal information'],
            ['name' => 'filler_499_id', 'label' => '499 filler ID', 'max' => 64, 'help' => 'FCC Form 499 / filler identifier', 'section' => 'Personal information'],
        ];
        if ($staff) {
            $res = ['' => '— none —'];
            foreach ((new ResellerRepository($this->db()))->all(TenantScope::global(), [], 500, 'name') as $x) {
                $res[$x['id']] = $x['name'];
            }
            $f[] = ['name' => 'reseller_id', 'label' => 'Group (reseller)', 'type' => 'select', 'options' => $res, 'list' => true, 'section' => 'Billing'];
            $plans = ['' => '— select —'];
            foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $plans[$p['id']] = $p['name'];
            }
            array_push($f,
                ['name' => 'rate_plan_id', 'label' => 'Plan', 'type' => 'select', 'options' => $plans, 'list' => true, 'section' => 'Billing'],
            );
        }
        array_push($f,
            ['name' => 'billing_mode', 'label' => 'Type paid', 'type' => 'select', 'required' => true, 'options' => ['prepaid' => 'Prepaid', 'postpaid' => 'Postpaid'], 'list' => true, 'default' => 'prepaid', 'section' => 'Billing'],
            ['name' => 'status', 'label' => 'Active', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'suspended' => 'Inactive', 'closed' => 'Closed'], 'list' => true, 'default' => 'active', 'section' => 'Billing'],
            ['name' => 'balance', 'label' => 'Balance', 'type' => 'number', 'list' => true, 'list_only' => true, 'section' => 'Billing'],
            ['name' => 'credit_limit', 'label' => 'Credit limit', 'type' => 'number', 'default' => '0', 'help' => 'Postpaid', 'section' => 'Billing'],
            ['name' => 'max_concurrent_calls', 'label' => 'Call limit', 'type' => 'int', 'min' => -1, 'max' => 100000, 'required' => true, 'default' => '10', 'list' => true, 'help' => '-1 = unlimited concurrent calls', 'section' => 'Billing'],
        );
        return $f;
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_map(static fn ($row) => (int) $row['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $bal = [];
        try {
            $st = $this->db()->prepare("SELECT customer_id, balance FROM balances WHERE customer_id IN ({$ph})");
            $st->execute($ids);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $b) {
                $bal[(int) $b['customer_id']] = (string) $b['balance'];
            }
        } catch (\Throwable) {
        }
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['balance'] = $bal[$id] ?? '0.000000';
        }
        unset($row);
        return $rows;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $scope = $this->scope($r);
        if ($scope->role === 'reseller') {
            $data['reseller_id'] = $scope->resellerId;
        } elseif (!$scope->isStaff()) {
            throw new \DomainException('Not permitted.');
        }
        if (isset($data['reseller_id']) && $data['reseller_id'] !== null && $scope->isStaff()) {
            $x = (new ResellerRepository($this->db()))->find((int) $data['reseller_id'], $scope);
            if ($x === null) {
                throw new \DomainException('Reseller not found.');
            }
        }

        $pw = isset($data['portal_password']) ? (string) $data['portal_password'] : '';
        unset($data['portal_password']);
        $r->setAttr('_portal_password', $pw);

        $email = trim((string) ($data['contact_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Email is required for portal login.');
        }
        if ($existing === null && $pw === '') {
            throw new \DomainException('Portal password is required when creating a user.');
        }

        $data = $this->autoCode($data, $existing, 'customers');
        if ($existing === null) {
            $data['currency'] = $data['currency'] ?? 'USD';
            $data['timezone'] = $data['timezone'] ?? 'UTC';
            $data['media_policy'] = null;
            $data['recording_enabled'] = $data['recording_enabled'] ?? 0;
        }
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        // Trunk groups belong on SIP users (override tariff), not on the account.
        if ($existing === null) {
            $data['routing_plan_id'] = null;
        } else {
            unset($data['routing_plan_id']);
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $this->db()->prepare('INSERT IGNORE INTO balances (customer_id, balance, reserved) VALUES (?,0,0)')->execute([$id]);

        $row = $this->repo()->findInternal($id);
        if ($row === null) {
            return null;
        }
        $pw = $r->attr('_portal_password');
        $plain = is_string($pw) && $pw !== '' ? $pw : null;
        $users = new UserRepository($this->db());
        $res = $users->upsertCustomerPortal(
            $id,
            (string) ($row['contact_email'] ?? ''),
            (string) ($row['name'] ?? ''),
            $plain,
            isset($row['reseller_id']) && $row['reseller_id'] !== null ? (int) $row['reseller_id'] : null,
            (string) ($row['status'] ?? 'active'),
        );
        if (!$res['ok']) {
            App::session()->flash('warning', 'User saved, but portal login failed: ' . $res['detail']);
            return null;
        }
        // Refresh OpenSIPS allow_calls when prepaid balance / status changes
        FreeswitchProvisioner::fromConfig()->refreshAllowCallsForCustomer($id);

        $msg = $res['detail'] . ' Sign in with username or email + portal password.';
        // On create only: Dynamic SIP peer with same username + portal password (admin can edit later).
        if ($existing === null && is_string($plain) && $plain !== '') {
            $sipMsg = $this->createDefaultSipAccount(
                $id,
                (string) ($row['name'] ?? ''),
                $plain,
                (string) ($row['status'] ?? 'active')
            );
            if ($sipMsg !== null) {
                $msg .= ' ' . $sipMsg;
            }
        }
        return $msg;
    }

    /**
     * Create one Dynamic sip_accounts row matching portal username/password.
     * Skips (with flash) if username is already taken — does not touch existing REGED peers.
     */
    private function createDefaultSipAccount(int $customerId, string $name, string $plainPassword, string $customerStatus): ?string
    {
        $username = $this->sipUsernameFromPortalName($name, $customerId);
        if ($username === '') {
            App::session()->flash('warning', 'User saved, but SIP user was not auto-created (invalid username). Add one under SIP Users.');
            return null;
        }
        $domain = (string) App::config('app.integrations.opensips.sip_domain', 'sip.example.test');
        try {
            $st = $this->db()->prepare('SELECT id FROM sip_accounts WHERE username = ? AND domain = ? LIMIT 1');
            $st->execute([$username, $domain]);
            if ($st->fetchColumn()) {
                App::session()->flash(
                    'warning',
                    "User saved. SIP username “{$username}” already exists — open SIP Users to add another peer manually."
                );
                return null;
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] auto SIP lookup: ' . $e->getMessage());
            return null;
        }

        try {
            [$ha1, $ha1b] = FreeswitchProvisioner::ha1($username, $domain, $plainPassword);
            $enc = Crypto::encrypt($plainPassword);
        } catch (\Throwable $e) {
            App::session()->flash('warning', 'User saved, but SIP password could not be stored: ' . mb_substr($e->getMessage(), 0, 160));
            return null;
        }

        $sipStatus = in_array($customerStatus, ['active'], true) ? 'active' : 'disabled';
        $codecs = SipCodecs::defaultStorage($this->db());
        try {
            $this->db()->prepare(
                "INSERT INTO sip_accounts
                    (customer_id, username, domain, ha1, ha1b, password_enc, password_changed_at,
                     sip_host, connection_mode, sip_port, codecs, qualify, dtmfmode, dtmf_pass,
                     max_channels, max_cps, max_call_duration, status)
                 VALUES
                    (?,?,?,?,?,?,UTC_TIMESTAMP(),'dynamic','dynamic',5060,?,'no','RFC2833','yes',2,1,0,?)"
            )->execute([$customerId, $username, $domain, $ha1, $ha1b, $enc, $codecs, $sipStatus]);
            $sipId = (int) $this->db()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[ownswitch] auto SIP insert: ' . $e->getMessage());
            App::session()->flash('warning', 'User saved, but SIP user insert failed. Add one under SIP Users.');
            return null;
        }

        $prov = FreeswitchProvisioner::fromConfig();
        if ($prov->enabled() && $sipStatus === 'active') {
            try {
                $row = [
                    'username' => $username,
                    'domain' => $domain,
                    'ha1' => $ha1,
                    'ha1b' => $ha1b,
                    'status' => 'active',
                    'connection_mode' => 'dynamic',
                    'sip_host' => 'dynamic',
                    'max_channels' => 2,
                    'codecs' => $codecs,
                    '_plain_password' => $plainPassword,
                    '_effective_media' => 'relay',
                ];
                $prov->upsertSubscriber($username, $domain, $ha1, $ha1b, $row);
                $prov->syncSipPeerProfile($username, 'relay', 2, $row);
                $prov->reloadAddresses();
            } catch (\Throwable $e) {
                error_log('[ownswitch] auto SIP provision: ' . $e->getMessage());
                App::session()->flash('warning', 'SIP user saved in portal; FreeSWITCH provision warning: ' . mb_substr($e->getMessage(), 0, 160));
            }
        }

        return "Dynamic SIP user “{$username}” created (id {$sipId}) with the same password — edit under SIP Users anytime.";
    }

    /** Map portal Username → SIP id (A-Za-z0-9._- , 3–64). */
    private function sipUsernameFromPortalName(string $name, int $customerId): string
    {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9._-]+/', '_', $base) ?? '';
        $base = trim($base, '._-');
        if (strlen($base) < 3) {
            $base = 'user' . $customerId;
        }
        if (strlen($base) > 64) {
            $base = substr($base, 0, 64);
            $base = rtrim($base, '._-');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $base)) {
            $base = 'user' . $customerId;
        }
        return $base;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        $id = (int) $row['id'];
        $pdo = $this->db();

        // Keep billing history: refuse hard-delete when ledger/invoices/payments exist.
        foreach (
            [
                'ledger_entries' => 'ledger entries',
                'invoices' => 'invoices',
                'payments' => 'payments',
            ] as $table => $label
        ) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE customer_id = ?");
                $st->execute([$id]);
                if ((int) $st->fetchColumn() > 0) {
                    return "Cannot delete User: {$label} exist. Set Active → Closed instead (keeps CDR/billing history).";
                }
            } catch (\PDOException) {
                // table may not exist yet
            }
        }

        $sipUsers = [];
        try {
            $st = $pdo->prepare('SELECT username, domain FROM sip_accounts WHERE customer_id = ?');
            $st->execute([$id]);
            $sipUsers = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
        }

        $pdo->beginTransaction();
        try {
            // Detach CDRs (nullable FK) so history remains without blocking delete.
            try {
                $pdo->prepare('UPDATE cdrs SET customer_id = NULL WHERE customer_id = ?')->execute([$id]);
            } catch (\PDOException) {
            }
            foreach (
                [
                    'did_assignments',
                    'customer_rate_overrides',
                    'destination_policies',
                    'recordings',
                    'call_reservations',
                    'balances',
                    'ip_endpoints',
                    'sip_accounts',
                    'users',
                ] as $table
            ) {
                try {
                    $pdo->prepare("DELETE FROM `{$table}` WHERE customer_id = ?")->execute([$id]);
                } catch (\PDOException) {
                    // optional tables / naming differences
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 'Cannot delete User: ' . mb_substr($e->getMessage(), 0, 200);
        }

        // Best-effort OpenSIPS cleanup for removed SIP peers
        try {
            $prov = \Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner::fromConfig();
            if ($prov->enabled()) {
                foreach ($sipUsers as $s) {
                    $prov->removeSubscriber((string) $s['username'], (string) $s['domain']);
                    $prov->removeSipPeerProfile((string) $s['username']);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
