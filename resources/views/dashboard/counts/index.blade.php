@extends('admin.layout')

@section('title', 'Stock counts')
@section('pretitle', 'Inventory')
@section('subtitle', 'Physical counts. On completion, the difference between the count and the system quantity is recorded as a correction.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#countNew"><i class="ti ti-plus me-1"></i>Start a count</button>
@endsection

@section('content')
<div class="card">
    @if ($counts->isEmpty())
        <x-admin.empty icon="clipboard-list" title="No counts yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reference</th><th>Warehouse</th><th class="text-end">SKUs counted</th><th>Status</th><th>Completed</th></tr></thead>
                @foreach ($counts as $c)
                    <tr><td><a href="{{ route('dashboard.counts.show', $c) }}" class="fw-medium">{{ $c->reference }}</a></td><td>{{ $c->warehouse->code }}</td><td class="text-end">{{ $c->items_count }}</td><td><x-admin.status :status="$c->status" /></td><td class="small text-secondary">{{ $c->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$counts" />
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="countNew" title="Start a count" :action="route('dashboard.counts.store')" submit="Start">
        <div class="mb-3"><label class="form-label required">Warehouse</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
        <x-admin.input name="notes" type="textarea" label="Notes" />
    </x-admin.modal>
@endpush
