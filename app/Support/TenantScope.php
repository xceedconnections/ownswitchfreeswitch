<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Row-level tenancy. Fails CLOSED: a non-global role without the required tenant id
 * gets "1 = 0". Repositories and services must build SQL via this class.
 */
final class TenantScope
{
    public const STAFF_ROLES = ['super_admin', 'admin'];
    public const ROLES = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech', 'reporter'];

    public function __construct(
        public readonly string $role,
        public readonly ?int $userId = null,
        public readonly ?int $resellerId = null,
        public readonly ?int $customerId = null,
    ) {
    }

    /** @param array<string,mixed> $user row with role, id, reseller_id, customer_id */
    public static function fromUser(array $user): self
    {
        return new self(
            (string) ($user['role'] ?? ''),
            isset($user['id']) ? (int) $user['id'] : null,
            isset($user['reseller_id']) && $user['reseller_id'] !== null ? (int) $user['reseller_id'] : null,
            isset($user['customer_id']) && $user['customer_id'] !== null ? (int) $user['customer_id'] : null,
        );
    }

    public static function global(): self
    {
        return new self('admin');
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    public function isCustomerRole(): bool
    {
        return in_array($this->role, ['customer_owner', 'customer_tech'], true);
    }

    /** Staff, or a reporter that has no tenant binding. */
    public function isGlobal(): bool
    {
        if ($this->isStaff()) {
            return true;
        }
        return $this->role === 'reporter' && $this->resellerId === null && $this->customerId === null;
    }

    /**
     * SQL fragment for a table by tenancy mode.
     *  none     admin-only data (rate plans, carriers...)
     *  customer customers table (id / reseller_id)
     *  reseller resellers table (id)
     *  owned    tables with customer_id and reseller_id columns
     *
     * @return array{0:string,1:array<int,mixed>} [sql, positional params]
     */
    public function where(string $mode, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        if ($this->isGlobal()) {
            return ['1 = 1', []];
        }
        $deny = ['1 = 0', []];
        if (!in_array($this->role, self::ROLES, true)) {
            return $deny;
        }
        switch ($mode) {
            case 'customer':
                if ($this->customerId !== null && ($this->isCustomerRole() || $this->role === 'reporter')) {
                    return ["{$p}id = ?", [$this->customerId]];
                }
                if ($this->resellerId !== null && in_array($this->role, ['reseller', 'reporter'], true)) {
                    return ["{$p}reseller_id = ?", [$this->resellerId]];
                }
                return $deny;
            case 'reseller':
                if ($this->resellerId !== null && in_array($this->role, ['reseller', 'reporter'], true)) {
                    return ["{$p}id = ?", [$this->resellerId]];
                }
                return $deny;
            case 'owned':
                if ($this->customerId !== null && ($this->isCustomerRole() || $this->role === 'reporter')) {
                    return ["{$p}customer_id = ?", [$this->customerId]];
                }
                if ($this->resellerId !== null && in_array($this->role, ['reseller', 'reporter'], true)) {
                    return ["{$p}reseller_id = ?", [$this->resellerId]];
                }
                return $deny;
            default: // 'none' => global only
                return $deny;
        }
    }

    /** May this actor see data of this customer (with owning reseller)? */
    public function canAccessCustomer(int $customerId, ?int $customerResellerId): bool
    {
        if ($this->isGlobal()) {
            return true;
        }
        if ($this->customerId !== null && ($this->isCustomerRole() || $this->role === 'reporter')) {
            return $this->customerId === $customerId;
        }
        if ($this->resellerId !== null && in_array($this->role, ['reseller', 'reporter'], true)) {
            return $customerResellerId !== null && $this->resellerId === $customerResellerId;
        }
        return false;
    }
}
