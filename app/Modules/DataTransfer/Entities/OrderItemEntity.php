<?php

namespace App\Modules\DataTransfer\Entities;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Order lines with their purchase snapshot (name and size as sold): export only. */
class OrderItemEntity extends DataEntity
{
    public function key(): string { return 'order_items'; }

    public function label(): string { return 'Order lines'; }

    public function description(): string { return 'One row per order line (gift lines included), as sold.'; }

    public function exportPermission(): ?string { return 'orders.export'; }

    public function columns(): array
    {
        return [
            'order_id' => Column::exportOnly('Order'),
            'order_created_at' => Column::exportOnly('Placed at'),
            'order_status' => Column::exportOnly('Order status'),
            'product_code' => Column::exportOnly('Product code'),
            'variant_sku' => Column::exportOnly('SKU'),
            'product_name' => Column::exportOnly('Product (as sold)'),
            'size' => Column::exportOnly('Size (as sold)'),
            'quantity' => Column::exportOnly('Quantity'),
            'unit_price' => Column::exportOnly('Unit price'),
            'original_price' => Column::exportOnly('Original price'),
            'discount_amount' => Column::exportOnly('Discount'),
            'total_price' => Column::exportOnly('Line total'),
            'is_gift' => Column::exportOnly('Gift'),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => ['Order status', 'select', array_combine(OrderStatus::values(), OrderStatus::values())],
            'created_from' => ['Placed from', 'date'],
            'created_to' => ['Placed to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        return OrderItem::query()
            ->with(['order:id,created_at,status', 'size:id,sku', 'product' => fn ($q) => $q->withoutGlobalScope('listing_aggregates')->withTrashed()->without(['sizes', 'saleable'])->select('id', 'code')])
            ->whereHas('order', fn ($o) => OrderEntity::dated($o, $filters)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s)));
    }

    public function exportRow(Model $item): array
    {
        return [
            'order_id' => $item->order_id, 'order_created_at' => $item->order?->created_at, 'order_status' => $item->order?->status,
            'product_code' => $item->product?->code, 'variant_sku' => $item->size?->sku,
            'product_name' => $item->product_name_en ?? $item->product_name_ar, 'size' => $item->size_label,
            'quantity' => $item->quantity, 'unit_price' => $item->price_at_time, 'original_price' => $item->original_price,
            'discount_amount' => $item->discount_amount, 'total_price' => $item->total_price, 'is_gift' => $item->is_gift ? 1 : 0,
        ];
    }
}
