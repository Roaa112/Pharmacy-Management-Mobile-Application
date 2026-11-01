@extends('admin.layout')

@section('title', 'Transfer '.$transfer->reference)
@section('pretitle', 'Inventory · '.$transfer->source->code.' → '.$transfer->destination->code)

@section('actions')
    <a href="{{ route('dashboard.transfers.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Transfers</a>
    @if ($transfer->status === 'requested')
        <form method="POST" action="{{ route('dashboard.transfers.approve', $transfer) }}">@csrf<button class="btn btn-primary">Approve</button></form>
    @endif
    @if ($transfer->status === 'approved')
        <form method="POST" action="{{ route('dashboard.transfers.dispatch', $transfer) }}">@csrf<button class="btn btn-primary">Dispatch</button></form>
    @endif
    @if (in_array($transfer->status, ['requested', 'approved'], true))
        <form method="POST" action="{{ route('dashboard.transfers.cancel', $transfer) }}">@csrf<button class="btn btn-outline-danger">Cancel</button></form>
    @endif
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Lines</h3><div class="card-actions"><x-admin.status :status="$transfer->status" /></div></div>
            <form method="POST" action="{{ route('dashboard.transfers.receive', $transfer) }}">
                @csrf
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead><tr><th>Product</th><th class="text-end">Requested</th><th class="text-end">Dispatched</th><th class="text-end">Received</th><th class="text-end">Lost</th><th class="text-end">In transit</th>@if (in_array($transfer->status, ['in_transit', 'partially_received'], true))<th class="w-1">Receive now</th>@endif</tr></thead>
                        @foreach ($transfer->items as $item)
                            <tr>
                                <td>{{ $item->size?->product?->name_en ?? '—' }} — {{ $item->size?->size }}</td>
                                <td class="text-end">{{ $item->quantity_requested }}</td>
                                <td class="text-end">{{ $item->quantity_dispatched }}</td>
                                <td class="text-end">{{ $item->quantity_received }}</td>
                                <td class="text-end {{ $item->quantity_lost ? 'text-red' : '' }}">{{ $item->quantity_lost }}</td>
                                <td class="text-end fw-bold">{{ $item->in_transit }}</td>
                                @if (in_array($transfer->status, ['in_transit', 'partially_received'], true))
                                    <td><input type="number" min="0" max="{{ $item->in_transit }}" name="quantities[{{ $item->id }}]" class="form-control form-control-sm" style="width: 6rem" value="{{ $item->in_transit }}" aria-label="Units received"></td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                </div>
                @if (in_array($transfer->status, ['in_transit', 'partially_received'], true))
                    <div class="card-footer d-flex gap-2">
                        <input name="notes" class="form-control" placeholder="Receiving notes" aria-label="Notes">
                        <button class="btn btn-primary text-nowrap">Record received</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        @if (in_array($transfer->status, ['in_transit', 'partially_received', 'received'], true))
            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('dashboard.transfers.close', $transfer) }}">
                        @csrf
                        <x-admin.input name="reason" label="Discrepancy reason" hint="Required if units are still in transit; they are recorded as lost." />
                        <button class="btn btn-outline-danger w-100">Close transfer</button>
                    </form>
                </div>
            </div>
        @endif
        <div class="card">
            <div class="card-header"><h3 class="card-title">History</h3></div>
            <div class="card-body small">
                <div>Requested {{ $transfer->created_at->format('Y-m-d H:i') }}</div>
                @if ($transfer->approved_at)<div>Approved {{ $transfer->approved_at->format('Y-m-d H:i') }}</div>@endif
                @if ($transfer->dispatched_at)<div>Dispatched {{ $transfer->dispatched_at->format('Y-m-d H:i') }}</div>@endif
                @foreach ($transfer->receipts as $r)<div>Received {{ array_sum($r->quantities) }} units {{ $r->created_at->format('Y-m-d H:i') }} by {{ $r->receiver?->name ?? '—' }}</div>@endforeach
                @if ($transfer->closed_at)<div>Closed {{ $transfer->closed_at->format('Y-m-d H:i') }}</div>@endif
                @if ($transfer->notes)<div class="mt-2 text-secondary">{{ $transfer->notes }}</div>@endif
            </div>
        </div>
    </div>
</div>
@endsection
