<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Payment;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments: export only (financial, payments.export, audited). Provider ids, raw provider
 * payloads (metadata), receipts and anything credential-like are never exported.
 */
class PaymentEntity extends DataEntity
{
    public function key(): string { return 'payments'; }

    public function label(): string { return 'Payments'; }

    public function description(): string { return 'Payments per order: provider, status, amounts collected and refunded.'; }

    public function exportPermission(): ?string { return 'payments.export'; }

    public function sensitive(): bool { return true; }

    public function columns(): array
    {
        return [
            'internal_reference' => Column::exportOnly('Reference'),
            'order_id' => Column::exportOnly('Order'),
            'provider' => Column::exportOnly('Provider'),
            'status' => Column::exportOnly('Status'),
            'amount' => Column::exportOnly('Amount'),
            'currency' => Column::exportOnly('Currency'),
            'collection_status' => Column::exportOnly('Collection'),
            'collected_amount' => Column::exportOnly('Collected'),
            'refunded_amount' => Column::exportOnly('Refunded'),
            'paid_at' => Column::exportOnly('Paid at'),
            'created_at' => Column::exportOnly('Created at'),
        ];
    }

    public function filters(): array
    {
        return [
            'provider' => ['Provider', 'select', ['cod' => 'Cash on delivery', 'paymob' => 'Paymob', 'manual_transfer' => 'Manual transfer']],
            'status' => ['Status', 'select', array_combine($s = ['pending', 'awaiting_verification', 'paid', 'partially_paid', 'failed', 'partially_refunded', 'refunded', 'expired', 'canceled'], $s)],
            'created_from' => ['Created from', 'date'],
            'created_to' => ['Created to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        return OrderEntity::dated(Payment::query(), $filters)
            ->when($filters['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    public function exportRow(Model $payment): array
    {
        return [
            'internal_reference' => $payment->internal_reference, 'order_id' => $payment->order_id, 'provider' => $payment->provider,
            'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
            'amount' => $payment->amount, 'currency' => $payment->currency, 'collection_status' => $payment->collection_status,
            'collected_amount' => $payment->collected_amount, 'refunded_amount' => $payment->refunded_amount,
            'paid_at' => $payment->paid_at, 'created_at' => $payment->created_at,
        ];
    }
}
