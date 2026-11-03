@extends('admin.layout')

@section('title', 'Suppliers')
@section('pretitle', 'Purchasing')
@section('subtitle', 'Balance = invoices − payments − credit from goods returned.')

@section('actions')
    <x-admin.data-transfer :entities="['suppliers']" />
    <a href="{{ route('dashboard.purchase_orders.index') }}" class="btn btn-outline-primary"><i class="ti ti-file-invoice me-1"></i>Purchase orders</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierNew"><i class="ti ti-plus me-1"></i>New supplier</button>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2" style="max-width: 28rem"><input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Name, code or phone" aria-label="Search"><button class="btn btn-primary">Search</button></form>
    </div>
    @if ($suppliers->isEmpty())
        <x-admin.empty icon="truck-loading" title="No suppliers" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Supplier</th><th>Contact</th><th class="text-end">Open POs</th><th class="text-end">Invoiced</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                @foreach ($suppliers as $s)
                    @php $balance = (float) $s->invoices_sum_amount - (float) $s->payments_sum_amount - (float) $s->returns_credit; @endphp
                    <tr>
                        <td><a href="{{ route('dashboard.suppliers.show', $s) }}" class="fw-medium">{{ $s->name }}</a><div class="small text-secondary">{{ $s->code }}</div></td>
                        <td class="small">{{ $s->contact_name }}<div class="text-secondary">{{ $s->phone }}</div></td>
                        <td class="text-end">{{ $s->open_orders_count }}</td>
                        <td class="text-end"><x-admin.money :amount="$s->invoices_sum_amount" /></td>
                        <td class="text-end"><x-admin.money :amount="$s->payments_sum_amount" /></td>
                        <td class="text-end fw-bold {{ $balance > 0 ? 'text-orange' : '' }}"><x-admin.money :amount="$balance" /></td>
                        <td><x-admin.status :status="$s->is_active ? 'active' : 'inactive'" /></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$suppliers" />
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="supplierNew" title="New supplier" :action="route('dashboard.suppliers.store')" size="lg">
        @include('dashboard.suppliers.form', ['supplier' => null])
    </x-admin.modal>
@endpush
