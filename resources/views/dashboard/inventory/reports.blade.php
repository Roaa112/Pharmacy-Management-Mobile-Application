@extends('admin.layout')

@section('title', 'Inventory reports')
@section('pretitle', 'Inventory')
@section('subtitle', 'Valuation, replenishment, movement summary and the ledger integrity check.')

@section('actions')
    <a href="{{ route('dashboard.inventory.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Inventory</a>
@endsection

@section('content')
<div class="card mb-3 {{ $problems ? 'border-red' : '' }}">
    <div class="card-body">
        @if ($problems)
            <div class="text-red fw-medium mb-2"><i class="ti ti-alert-triangle me-1"></i>{{ count($problems) }} integrity problem(s): balances, ledger, reservations or catalogue stock disagree.</div>
            <ul class="small mb-0">@foreach (array_slice($problems, 0, 20) as $p)<li>{{ $p }}</li>@endforeach</ul>
        @else
            <div class="text-green fw-medium"><i class="ti ti-circle-check me-1"></i>Integrity check passed: every balance equals its ledger, reservations match and catalogue stock is consistent.</div>
        @endif
    </div>
</div>

<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Valuation by warehouse</h3></div>
            <table class="table card-table table-sm">
                <thead><tr><th>Warehouse</th><th class="text-end">Units</th><th class="text-end">At cost</th><th class="text-end">At retail</th></tr></thead>
                @foreach ($valuationByWarehouse as $row)
                    <tr><td>{{ $row->name }}@if ($row->without_cost)<div class="small text-orange">{{ $row->without_cost }} SKUs without cost</div>@endif</td><td class="text-end">{{ number_format($row->units) }}</td><td class="text-end"><x-admin.money :amount="$row->cost_value" /></td><td class="text-end"><x-admin.money :amount="$row->retail_value" /></td></tr>
                @endforeach
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Valuation by category (cost)</h3></div>
            <table class="table card-table table-sm">
                @foreach ($valuationByCategory as $row)
                    <tr><td>{{ $row->category }}</td><td class="text-end">{{ number_format($row->units) }}</td><td class="text-end"><x-admin.money :amount="$row->cost_value" /></td></tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">To reorder</h3></div>
            <table class="table card-table table-sm">
                <thead><tr><th>SKU</th><th class="text-end">Available</th><th class="text-end">Reorder at</th><th class="text-end">Suggested</th></tr></thead>
                @forelse ($reorder as $s)
                    <tr><td><a href="{{ route('dashboard.inventory.show', $s) }}">{{ $s->product->name_en ?? '—' }} — {{ $s->size }}</a></td><td class="text-end">{{ $s->stock }}</td><td class="text-end">{{ $s->reorder_point ?? $threshold }}</td>
                        <td class="text-end">{{ $s->reorder_quantity ?? max(0, ($s->max_stock ?? ($s->reorder_point ?? $threshold) * 2) - $s->stock) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-secondary">Nothing below its reorder point.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Movements {{ $from->format('M j') }} – {{ $to->format('M j') }}</h3>
                <div class="card-actions"><form method="GET" class="d-flex gap-1"><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm" aria-label="To"><button class="btn btn-sm btn-outline-primary">Go</button></form></div>
            </div>
            <table class="table card-table table-sm">
                <thead><tr><th>Type</th><th>Bucket</th><th class="text-end">Rows</th><th class="text-end">Units</th></tr></thead>
                @foreach ($movementSummary as $row)
                    <tr><td>{{ str_replace('_', ' ', $row->type) }}</td><td class="text-secondary">{{ str_replace('_', ' ', $row->bucket) }}</td><td class="text-end">{{ $row->count }}</td><td class="text-end {{ $row->units < 0 ? 'text-red' : '' }}">{{ number_format($row->units) }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>
</div>
@endsection
