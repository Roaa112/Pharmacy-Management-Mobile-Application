<?php

namespace App\Modules\DataTransfer\Entities;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Orders: export only (orders are created by checkout, never by a file). Financial and personal
 * data: orders.export, audited. Receipts and payment provider data are not included.
 */
class OrderEntity extends DataEntity
{
    public function key(): string { return 'orders'; }

    public function label(): string { return 'Orders'; }

    public function description(): string { return 'One row per order: status, customer, amounts, discounts, points and refunds.'; }

    public function exportPermission(): ?string { return 'orders.export'; }

    public function sensitive(): bool { return true; }

    public function columns(): array
    {
        return [
            'id' => Column::exportOnly('Order'),
            'created_at' => Column::exportOnly('Placed at'),
            'status' => Column::exportOnly('Status'),
            'customer_id' => Column::exportOnly('Customer id'),
            'customer_name' => Column::exportOnly('Customer'),
            'customer_email' => Column::exportOnly('Customer email', 'Personal data', false),
            'city' => Column::exportOnly('City'),
            'payment_method' => Column::exportOnly('Payment method'),
            'payment_status' => Column::exportOnly('Payment status'),
            'subtotal' => Column::exportOnly('Subtotal'),
            'delivery_fee' => Column::exportOnly('Delivery fee'),
            'discount_amount' => Column::exportOnly('Order discounts'),
            'coupon_code' => Column::exportOnly('Coupon'),
            'points_redeemed' => Column::exportOnly('Points used'),
            'points_discount_amount' => Column::exportOnly('Paid with points'),
            'total_price' => Column::exportOnly('Total'),
            'points_earned' => Column::exportOnly('Points earned'),
            'refund_status' => Column::exportOnly('Refund status'),
            'refunded_amount' => Column::exportOnly('Refunded'),
            'items_count' => Column::exportOnly('Lines'),
            'completed_at' => Column::exportOnly('Completed at'),
            'canceled_at' => Column::exportOnly('Canceled at'),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => ['Status', 'select', array_combine(OrderStatus::values(), OrderStatus::values())],
            'payment_status' => ['Payment status', 'select', array_combine($s = ['unpaid', 'pending', 'awaiting_verification', 'paid', 'partially_refunded', 'refunded', 'failed'], $s)],
            'created_from' => ['Placed from', 'date'],
            'created_to' => ['Placed to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        return self::dated(Order::query()->with('user:id,name,email')->withCount('items'), $filters)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $q->where('payment_status', $s));
    }

    public function exportRow(Model $order): array
    {
        return [
            'id' => $order->id, 'created_at' => $order->created_at, 'status' => $order->status, 'customer_id' => $order->user_id,
            'customer_name' => $order->user?->name, 'customer_email' => $order->user?->email,
            'city' => $order->shipping_address['city'] ?? $order->shipping_address['governorate'] ?? null,
            'payment_method' => $order->payment_method, 'payment_status' => $order->payment_status,
            'subtotal' => $order->subtotal, 'delivery_fee' => $order->delivery_fee, 'discount_amount' => $order->discount_amount,
            'coupon_code' => $order->coupon_code, 'points_redeemed' => $order->points_redeemed, 'points_discount_amount' => $order->points_discount_amount,
            'total_price' => $order->total_price, 'points_earned' => (int) $order->points_earned, 'refund_status' => $order->refund_status,
            'refunded_amount' => $order->refunded_amount, 'items_count' => $order->items_count, 'completed_at' => $order->completed_at, 'canceled_at' => $order->canceled_at,
        ];
    }

    public static function dated(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        return $query
            ->when($filters['created_from'] ?? null, fn ($q, $d) => $q->where($column, '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['created_to'] ?? null, fn ($q, $d) => $q->where($column, '<=', Carbon::parse($d)->endOfDay()));
    }
}
