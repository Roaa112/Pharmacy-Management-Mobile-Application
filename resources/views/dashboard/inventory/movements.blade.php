@extends('admin.layout')

@section('title', 'Stock movements')
@section('pretitle', 'Inventory')
@section('subtitle', $size ? 'History of '.($size->product->name_en ?? 'product').' — '.$size->size.'.' : 'The stock ledger: every change of every bucket, with before and after. Rows can never be edited or deleted.')

@section('actions')
    <a href="{{ route('dashboard.inventory.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Inventory</a>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            @if ($size)<input type="hidden" name="size" value="{{ $size->id }}">@endif
            <div class="col-md-3"><input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Product, SKU or barcode" aria-label="Search"></div>
            <div class="col-md-2"><select name="type" class="form-select" aria-label="Movement type"><option value="">All types</option>@foreach ($types as $t)<option value="{{ $t }}" @selected($type === $t)>{{ str_replace('_', ' ', $t) }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="warehouse" class="form-select" aria-label="Warehouse"><option value="">All warehouses</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->code }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" aria-label="From"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control" aria-label="To"></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
        </form>
    </div>
    @if ($movements->isEmpty())
        <x-admin.empty icon="arrows-exchange" title="No movements" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead><tr><th>When</th><th>Product</th><th>Warehouse</th><th>Type</th><th>Bucket</th><th class="text-end">Before</th><th class="text-end">Change</th><th class="text-end">After</th><th>Reference</th><th>By / reason</th></tr></thead>
                <tbody>
                @foreach ($movements as $m)
                    <tr>
                        <td class="small text-nowrap">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="small">@if ($m->size)<a href="{{ route('dashboard.inventory.show', $m->product_size_id) }}">{{ $m->size->product->name_en ?? 'Product' }} — {{ $m->size->size }}</a>@else — @endif</td>
                        <td class="small">{{ $m->warehouse?->code ?? 'legacy' }}</td>
                        <td><span class="badge bg-azure-lt">{{ str_replace('_', ' ', $m->type) }}</span></td>
                        <td class="small text-secondary">{{ str_replace('_', ' ', $m->bucket) }}</td>
                        <td class="text-end">{{ $m->quantity_before }}</td>
                        <td class="text-end fw-bold {{ $m->quantity_change < 0 ? 'text-red' : 'text-green' }}">{{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}</td>
                        <td class="text-end">{{ $m->quantity_after }}</td>
                        <td class="small text-secondary">{{ $m->reference_type ? class_basename($m->reference_type).' #'.$m->reference_id : '—' }}</td>
                        <td class="small">{{ $m->admin?->name ?? ($m->user_id ? 'Customer' : 'System') }}<div class="text-secondary">{{ \Illuminate\Support\Str::limit($m->reason, 60) }}</div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$movements" />
    @endif
</div>
@endsection
