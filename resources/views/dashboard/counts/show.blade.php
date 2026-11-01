@extends('admin.layout')

@section('title', 'Count '.$count->reference)
@section('pretitle', 'Inventory · '.$count->warehouse->code)
@section('subtitle', 'Enter the units physically on the shelf (sellable condition). Leave a line empty to skip it.')

@section('actions')
    <a href="{{ route('dashboard.counts.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Counts</a>
    @if ($count->status === 'draft')
        <form method="POST" action="{{ route('dashboard.counts.complete', $count) }}" onsubmit="return confirm('Complete the count? Differences become stock corrections.')">@csrf<button class="btn btn-primary">Complete count</button></form>
        <form method="POST" action="{{ route('dashboard.counts.cancel', $count) }}">@csrf<button class="btn btn-outline-danger">Cancel</button></form>
    @endif
@endsection

@section('content')
<form method="POST" action="{{ route('dashboard.counts.record', $count) }}" class="card">
    @csrf
    <div class="card-header"><h3 class="card-title">Lines</h3><div class="card-actions"><x-admin.status :status="$count->status" /></div></div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead><tr><th>Product</th><th>SKU</th><th class="text-end">System on hand</th><th class="text-end">Reserved</th><th class="w-1">Counted</th><th class="text-end">Difference</th></tr></thead>
            @foreach ($sizes as $size)
                @php $item = $items->get($size->id); $balance = $balances->get($size->id); @endphp
                <tr>
                    <td>{{ $size->product?->name_en ?? '—' }} — {{ $size->size }}</td>
                    <td class="small">{{ $size->sku ?? '—' }}</td>
                    <td class="text-end">{{ $item?->expected_quantity ?? ($balance?->on_hand ?? 0) }}</td>
                    <td class="text-end text-secondary">{{ $balance?->reserved ?? 0 }}</td>
                    <td>
                        @if ($count->status === 'draft')
                            <input type="number" min="0" name="counts[{{ $size->id }}]" value="{{ $item?->counted_quantity }}" class="form-control form-control-sm" style="width: 6rem" aria-label="Counted units">
                        @else {{ $item?->counted_quantity ?? '—' }} @endif
                    </td>
                    <td class="text-end fw-bold {{ ($item?->difference ?? 0) < 0 ? 'text-red' : (($item?->difference ?? 0) > 0 ? 'text-green' : '') }}">{{ $item?->difference ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    @if ($count->status === 'draft')
        <div class="card-footer text-end"><button class="btn btn-outline-primary">Save counted quantities</button></div>
    @endif
</form>
@endsection
