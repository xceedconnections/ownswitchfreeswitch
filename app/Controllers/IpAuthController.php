<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\FreeSWITCH\FreeswitchProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\IpEndpointRepository;

final class IpAuthController extends CrudController
{
    protected string $base = '/ip-endpoints';
    protected string $singular = 'IP endpoint';
    protected string $plural = 'IP Endpoints';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    protected string $emptyHint = 'No IP endpoints. Add the public IP (or CIDR) of a user PBX to the allowlist so it can send calls without SIP REGISTER.';

    protected function repo(): BaseRepository
    {
        return new IpEndpointRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $f = [];
        if (!$this->scope($r)->isCustomerRole()) {
            $f[] = ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'select', 'required' => true, 'options' => $this->customerOptions($r), 'readonly_on_edit' => true, 'list' => true];
        }
        array_push($f,
            ['name' => 'label', 'label' => 'Label', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'ip_address', 'label' => 'IP address', 'type' => 'ip', 'required' => true, 'list' => true],
            ['name' => 'mask', 'label' => 'Mask (CIDR bits)', 'type' => 'int', 'min' => 0, 'max' => 128, 'required' => true, 'default' => '32', 'list' => true, 'help' => '32 = single IPv4 host'],
            ['name' => 'port', 'label' => 'Port (0 = any)', 'type' => 'int', 'min' => 0, 'max' => 65535, 'required' => true, 'default' => '0'],
            ['name' => 'proto', 'label' => 'Protocol', 'type' => 'select', 'required' => true, 'options' => ['any' => 'Any', 'udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS']],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
        );
        return $f;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $ip = (string) $data['ip_address'];
        $max = str_contains($ip, ':') ? 128 : 32;
        if ((int) $data['mask'] > $max) {
            throw new \DomainException("Mask must be 0-{$max} for this address family.");
        }
        $this->guardPlatformRanges($ip);
        return $data;
    }

    /** Refuse loopback / unspecified addresses as customer trunks. */
    private function guardPlatformRanges(string $ip): void
    {
        if (in_array($ip, ['0.0.0.0', '::', '127.0.0.1', '::1'], true)) {
            throw new \DomainException('Loopback/unspecified addresses cannot be used for IP authentication.');
        }
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $prov = FreeswitchProvisioner::fromConfig();
        $row = $this->repo()->findInternal($id);
        if ($row && $prov->enabled()) {
            try {
                // OpenSIPS permissions group 1 = all customer IP peers (see opensips.cfg.tpl).
                if ($existing) {
                    $prov->removeAddress(FreeswitchProvisioner::GRP_CUSTOMER, (string) $existing['ip_address'], (int) $existing['mask'], (int) $existing['port']);
                }
                if ($row['status'] === 'active') {
                    $prov->upsertAddress(
                        FreeswitchProvisioner::GRP_CUSTOMER,
                        (string) $row['ip_address'],
                        (int) $row['mask'],
                        (int) $row['port'],
                        (string) $row['proto'],
                        'customer:' . (int) $row['customer_id']
                    );
                }
                $prov->reloadAddresses();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Saved, but FreeSWITCH provisioning failed.');
            }
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        $prov = FreeswitchProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeAddress(FreeswitchProvisioner::GRP_CUSTOMER, (string) $row['ip_address'], (int) $row['mask'], (int) $row['port']);
                $prov->reloadAddresses();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but FreeSWITCH deprovisioning failed.');
            }
        }
    }
}
