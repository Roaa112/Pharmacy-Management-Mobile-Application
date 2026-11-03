<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\LoyaltyLedgerEntry;
use App\Models\User;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Customers: export only. Contact details are personal data (sensitive, customers.export, audited).
 * Never exported: passwords, OTP codes, social login ids, tokens, health records. Not importable:
 * accounts are created by customers themselves (verification and credentials).
 */
class CustomerEntity extends DataEntity
{
    public function key(): string { return 'customers'; }

    public function label(): string { return 'Customers'; }

    public function description(): string { return 'Customer contact details and order totals. Never passwords, codes or health data.'; }

    public function exportPermission(): ?string { return 'customers.export'; }

    public function sensitive(): bool { return true; }

    public function columns(): array
    {
        return [
            'id' => Column::exportOnly('Id'),
            'name' => Column::exportOnly('Name'),
            'email' => Column::exportOnly('Email'),
            'phone' => Column::exportOnly('Phone'),
            'is_verified' => Column::exportOnly('Verified'),
            'is_active' => Column::exportOnly('Active'),
            'orders_count' => Column::exportOnly('Orders (not canceled)'),
            'completed_spend' => Column::exportOnly('Completed spend'),
            'points_balance' => Column::exportOnly('Valid points'),
            'created_at' => Column::exportOnly('Registered at'),
        ];
    }

    public function filters(): array
    {
        return [
            'is_active' => ['Status', 'select', ['1' => 'Active', '0' => 'Deactivated']],
            'created_from' => ['Registered from', 'date'],
            'created_to' => ['Registered to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        return User::query()->select('id', 'name', 'email', 'phone', 'is_verified', 'is_active', 'created_at')
            ->withCount(['orders' => fn ($q) => $q->where('status', '!=', 'canceled')])
            ->withSum(['orders as completed_spend' => fn ($q) => $q->where('status', 'completed')], 'total_price')
            ->selectSub(LoyaltyLedgerEntry::usable()->whereColumn('loyalty_ledger.user_id', 'users.id')->selectRaw('coalesce(sum(remaining_points), 0)'), 'points_balance')
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['created_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['created_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()));
    }

    public function exportRow(Model $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'is_verified' => $user->is_verified ? 1 : 0, 'is_active' => $user->is_active === false ? 0 : 1,
            'orders_count' => $user->orders_count, 'completed_spend' => $user->completed_spend ?? 0,
            'points_balance' => (int) $user->points_balance, 'created_at' => $user->created_at,
        ];
    }
}
