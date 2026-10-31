@extends('admin.layout')

@php
    $types = ['' => 'All', 'sale' => 'Sales', 'cancellation' => 'Cancellations', 'adjustment' => 'Adjustments', 'initial' => 'Opening stock', 'return_quarantine' => 'Returns (quarantine)'];
    $tones = ['sale' => 'blue', 'cancellation' => 'orange', 'adjustment' => 'purple', 'initial' => 'teal', 'return_quarantine' => 'yellow'];
@endphp

@section('title', 'Stock movements')
@section('pretitle')
    <a href="{{ route('dashboard.inventory.index') }}" class="text-reset">Inventory</a>
@endsection
@section('subtitle')
    @if ($size)
        History of {{ $size->product->name_en ?? 'product' }} · {{ $size->size }} (now {{ $size->stock }} in stock).
        <a href="{{ route('dashboard.inventory.movements', array_filter(['type' => $type])) }}">Show all sizes</a>
    @else
        Every change to stock, newest first. Records cannot be edited or deleted.
    @endif
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            @foreach ($types as $value => $label)
                <li class="nav-item">
                    <a href="{{ route('dashboard.inventory.movements', array_filter(['type' => $value, 'size' => $size?->id])) }}" class="nav-link {{ (string) $type === $value ? 'active' : '' }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($movements->isEmpty())
        <x-admin.empty icon="arrows-exchange" title="No stock movements yet" text="Movements are recorded from now on, for sales, cancellations and admin stock changes." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>When</th><th>Product / size</th><th>Type</th><th class="text-end">Change</th><th class="text-end">Stock after</th><th>By</th><th>Reference</th></tr></thead>
                <tbody>
                @foreach ($movements as $movement)
                    <tr>
                        <td class="small text-nowrap">{{ $movement->created_at?->format('M j, Y · H:i') }}</td>
                        <td>
                            <a href="{{ route('dashboard.inventory.movements', array_filter(['size' => $movement->product_size_id, 'type' => $type])) }}" class="text-reset">
                                <span class="fw-medium">{{ $movement->size?->product?->name_en ?? 'Removed product' }}</span>
                            </a>
                            <div class="small text-secondary">{{ $movement->size->size ?? '—' }}</div>
                        </td>
                        <td><span class="badge bg-{{ $tones[$movement->type] ?? 'secondary' }}-lt">{{ $types[$movement->type] ?? $movement->type }}</span></td>
                        <td class="text-end fw-bold {{ $movement->quantity_change < 0 ? 'text-danger' : 'text-success' }}">{{ $movement->quantity_change > 0 ? '+' : '' }}{{ $movement->quantity_change }}</td>
                        <td class="text-end">{{ $movement->quantity_before }} → <strong>{{ $movement->quantity_after }}</strong>@if ($movement->bucket === 'quarantine')<div class="small text-orange">in quarantine</div>@endif</td>
                        <td class="small">{{ $movement->admin_id ? 'Admin' : ($movement->user_id ? 'Customer' : 'System') }}</td>
                        <td class="small">
                            @if ($movement->reference_type === \App\Models\OrderItem::class && ($orderId = $orderIds[$movement->reference_id] ?? null))
                                <a href="{{ route('dashboard.orders.show', $orderId) }}">Order #{{ $orderId }}</a>
                            @endif
                            @if ($movement->reference_type === \App\Models\ReturnRequestItem::class && ($returnId = $returnIds[$movement->reference_id] ?? null))
                                <a href="{{ route('dashboard.returns.show', $returnId) }}">Return #{{ $returnId }}</a>
                            @endif
                            @if ($movement->reason)
                                <div class="text-secondary">{{ $movement->reason }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$movements" />
    @endif
</div>
@endsection
