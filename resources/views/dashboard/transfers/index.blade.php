@extends('admin.layout')

@section('title', 'Stock transfers')
@section('pretitle', 'Inventory')
@section('subtitle', 'Requested → approved → dispatched (in transit) → received. Units are never duplicated or lost: what does not arrive is written off as a discrepancy when the transfer is closed.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transferNew"><i class="ti ti-plus me-1"></i>New transfer</button>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select w-auto" aria-label="Status"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($transfers->isEmpty())
        <x-admin.empty icon="arrows-right-left" title="No transfers" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reference</th><th>From</th><th>To</th><th class="text-end">Lines</th><th>Status</th><th>Created</th></tr></thead>
                @foreach ($transfers as $t)
                    <tr>
                        <td><a href="{{ route('dashboard.transfers.show', $t) }}" class="fw-medium">{{ $t->reference }}</a></td>
                        <td>{{ $t->source->code }}</td><td>{{ $t->destination->code }}</td>
                        <td class="text-end">{{ $t->items_count }}</td>
                        <td><x-admin.status :status="$t->status" /></td>
                        <td class="small text-secondary">{{ $t->created_at->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$transfers" />
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="transferNew" title="New transfer" :action="route('dashboard.transfers.store')" size="lg" submit="Request transfer">
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label required">From</label><select name="source_warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label required">To</label><select name="destination_warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($loop->index === 1)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
        </div>
        @include('dashboard.partials.line-items', ['id' => 'transfer', 'sizes' => $sizes])
        <x-admin.input name="notes" type="textarea" label="Notes" />
    </x-admin.modal>
@endpush
