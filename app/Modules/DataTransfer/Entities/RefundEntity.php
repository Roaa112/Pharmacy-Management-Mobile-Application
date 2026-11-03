<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Refund;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Refunds: export only (financial, refunds.export, audited). Provider refund ids are not exported. */
class RefundEntity extends DataEntity
{
    public function key(): string { return 'refunds'; }

    public function label(): string { return 'Refunds'; }

    public function description(): string { return 'Refunds: amount, method, status and when they were processed.'; }

    public function exportPermission(): ?string { return 'refunds.export'; }

    public function sensitive(): bool { return true; }

    public function columns(): array
    {
        return [
            'reference' => Column::exportOnly('Reference'),
            'order_id' => Column::exportOnly('Order'),
            'payment_id' => Column::exportOnly('Payment id'),
            'return_request_id' => Column::exportOnly('Return'),
            'amount' => Column::exportOnly('Amount'),
            'currency' => Column::exportOnly('Currency'),
            'method' => Column::exportOnly('Method'),
            'status' => Column::exportOnly('Status'),
            'reason' => Column::exportOnly('Reason'),
            'processed_at' => Column::exportOnly('Processed at'),
            'created_at' => Column::exportOnly('Created at'),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => ['Status', 'select', array_combine($s = ['pending', 'processing', 'succeeded', 'failed', 'canceled'], $s)],
            'created_from' => ['Created from', 'date'],
            'created_to' => ['Created to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        return OrderEntity::dated(Refund::query(), $filters)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    public function exportRow(Model $refund): array
    {
        return [
            'reference' => $refund->reference, 'order_id' => $refund->order_id, 'payment_id' => $refund->payment_id,
            'return_request_id' => $refund->return_request_id, 'amount' => $refund->amount, 'currency' => $refund->currency,
            'method' => $refund->method, 'status' => $refund->status instanceof \BackedEnum ? $refund->status->value : $refund->status,
            'reason' => $refund->reason, 'processed_at' => $refund->processed_at, 'created_at' => $refund->created_at,
        ];
    }
}
