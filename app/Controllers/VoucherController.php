<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\VoucherRepository;

/** Billing → Vouchers: staff issue prepaid codes; customers redeem them (VoucherRedeemController). */
final class VoucherController extends CrudController
{
    protected string $base = '/vouchers';
    protected string $singular = 'Voucher';
    protected string $plural = 'Vouchers';
    protected array $writeRoles = ['super_admin', 'admin'];
    protected string $emptyHint = 'No vouchers yet. Create a voucher; customers redeem the code under Billing → Redeem voucher to add credit.';

    /** No 0/O/1/I/L so codes are easy to read over the phone. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function repo(): BaseRepository
    {
        return new VoucherRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status'];
    }

    protected function fields(Request $r): array
    {
        return [
            [
                'name' => 'code',
                'label' => 'Voucher code',
                'max' => 32,
                'pattern' => '/^[A-Za-z0-9-]{6,32}$/',
                'readonly_on_edit' => true,
                'list' => true,
                'placeholder' => 'XXXX-XXXX-XXXX',
                'help' => 'Leave blank to generate a readable code. Cannot be changed after creation.',
            ],
            [
                'name' => 'amount',
                'label' => 'Amount',
                'type' => 'number',
                'required' => true,
                'min' => '0.000001',
                'pattern' => '/^\d+(\.\d{1,6})?$/',
                'readonly_on_edit' => true,
                'list' => true,
                'help' => 'Credit added to the customer balance on redemption (up to 6 decimals). Locked after creation.',
            ],
            [
                'name' => 'currency',
                'label' => 'Currency',
                'required' => true,
                'max' => 3,
                'pattern' => '/^[A-Za-z]{3}$/',
                'default' => 'USD',
                'readonly_on_edit' => true,
                'list' => true,
                'help' => 'Must match the redeeming customer’s billing currency.',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['unused' => 'Unused', 'used' => 'Used', 'disabled' => 'Disabled'],
                'default' => 'unused',
                'list' => true,
                'help' => 'Set Disabled to block redemption. “Used” is set automatically when a customer redeems.',
            ],
            [
                'name' => 'expires_at',
                'label' => 'Expires at',
                'type' => 'datetime',
                'list' => true,
                'placeholder' => 'YYYY-MM-DD HH:MM:SS',
                'help' => 'Optional. Leave blank for no expiry (server time).',
            ],
            ['name' => 'used_by_customer_id', 'label' => 'Used by (customer ID)', 'list' => true, 'list_only' => true],
            ['name' => 'used_at', 'label' => 'Used at', 'list' => true, 'list_only' => true],
            [
                'name' => 'notes',
                'label' => 'Notes',
                'max' => 255,
                'wide' => true,
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($existing !== null) {
            if ((string) $existing['status'] === 'used') {
                throw new \DomainException('A redeemed voucher can no longer be edited.');
            }
            if (($data['status'] ?? '') === 'used') {
                throw new \DomainException('Status “Used” is set automatically when a customer redeems the voucher.');
            }
            // code / amount / currency are readonly_on_edit and never part of $data on update.
            unset($data['code'], $data['amount'], $data['currency'], $data['created_by']);
            return $data;
        }

        if (($data['status'] ?? 'unused') === 'used') {
            throw new \DomainException('New vouchers must be Unused or Disabled.');
        }
        $amount = (string) ($data['amount'] ?? '0');
        if (bccomp($amount, '0', 6) <= 0) {
            throw new \DomainException('Amount must be greater than zero.');
        }
        $data['amount'] = bcadd($amount, '0', 6);
        $data['currency'] = strtoupper((string) ($data['currency'] ?? 'USD'));

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            $code = $this->generateUniqueCode();
        } elseif ($this->repoVoucher()->codeExists($code)) {
            throw new \DomainException('A voucher with this code already exists.');
        }
        $data['code'] = $code;
        $data['created_by'] = (int) ($this->user($r)['id'] ?? 0) ?: null;
        return $data;
    }

    protected function beforeDelete(Request $r, array $row): ?string
    {
        if ((string) $row['status'] === 'used') {
            return 'A redeemed voucher cannot be deleted (it is part of the billing history). Disable it instead if needed.';
        }
        return null;
    }

    private function repoVoucher(): VoucherRepository
    {
        return new VoucherRepository($this->db());
    }

    /** XXXX-XXXX-XXXX */
    private function generateUniqueCode(): string
    {
        $alpha = self::CODE_ALPHABET;
        $max = strlen($alpha) - 1;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $groups = [];
            for ($g = 0; $g < 3; $g++) {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) {
                    $chunk .= $alpha[random_int(0, $max)];
                }
                $groups[] = $chunk;
            }
            $code = implode('-', $groups);
            if (!$this->repoVoucher()->codeExists($code)) {
                return $code;
            }
        }
        throw new \DomainException('Could not generate a unique voucher code; try again.');
    }
}
