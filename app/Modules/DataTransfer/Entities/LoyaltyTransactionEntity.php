<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\LoyaltyLedgerEntry;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The points ledger: export only. Importing would write history by hand; manual changes go
 * through adjustments, which are audited one by one.
 */
class LoyaltyTransactionEntity extends DataEntity
{
    public function key(): string { return 'loyalty_transactions'; }

    public function label(): string { return 'Point transactions'; }

    public function description(): string { return 'Every points movement (earned, redeemed, expired, reversed, adjusted…).'; }

    public function exportPermission(): ?string { return 'loyalty_transactions.export'; }

    public function columns(): array
    {
        return [
            'id' => Column::exportOnly('Id'),
            'occurred_at' => Column::exportOnly('Date'),
            'customer_id' => Column::exportOnly('Customer id'),
            'customer_name' => Column::exportOnly('Customer'),
            'type' => Column::exportOnly('Type'),
            'points' => Column::exportOnly('Points'),
            'remaining_points' => Column::exportOnly('Left in lot'),
            'status' => Column::exportOnly('Lot status'),
            'order_id' => Column::exportOnly('Order'),
            'description' => Column::exportOnly('Description'),
            'expires_at' => Column::exportOnly('Expires at'),
            'admin' => Column::exportOnly('By admin'),
            'shortfall' => Column::exportOnly('Owed (could not be taken back)'),
        ];
    }

    public function filters(): array
    {
        return [
            'type' => ['Type', 'select', array_combine(LoyaltyLedgerEntry::TYPES, LoyaltyLedgerEntry::TYPES)],
            'from' => ['From', 'date'],
            'to' => ['To', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        $type = in_array($filters['type'] ?? null, LoyaltyLedgerEntry::TYPES, true) ? $filters['type'] : null;

        return LoyaltyLedgerEntry::query()->with(['user:id,name', 'admin:id,name'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', Carbon::parse($d)->endOfDay()));
    }

    public function exportRow(Model $entry): array
    {
        return [
            'id' => $entry->id, 'occurred_at' => $entry->occurred_at, 'customer_id' => $entry->user_id, 'customer_name' => $entry->user?->name,
            'type' => $entry->type, 'points' => $entry->points, 'remaining_points' => $entry->remaining_points,
            'status' => $entry->remaining_points !== null ? $entry->status : null, 'order_id' => $entry->order_id,
            'description' => $entry->description, 'expires_at' => $entry->expires_at, 'admin' => $entry->admin?->name,
            'shortfall' => $entry->metadata['shortfall'] ?? null,
        ];
    }
}
