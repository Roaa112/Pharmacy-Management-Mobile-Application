@extends('admin.layout')

@section('title', 'Loyalty customers')
@section('pretitle', 'Loyalty')

@section('content')
@include('dashboard.loyalty._tabs')

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-6"><input type="search" name="search" class="form-control" placeholder="Name, email or phone" value="{{ $search }}"></div>
            <div class="col-md-3"><label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="all" value="1" @checked(request()->boolean('all'))><span class="form-check-label">Include customers without points</span></label></div>
            <div class="col-md-3"><button class="btn btn-primary"><i class="ti ti-search me-1"></i>Search</button></div>
        </form>
    </div>
    @if ($customers->isEmpty())
        <x-admin.empty icon="users" title="No customers" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Customer</th><th>Phone</th><th class="text-end">Valid points</th><th class="text-end">Pending</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($customers as $customer)
                    <tr>
                        <td><a href="{{ route('dashboard.customers.show', $customer) }}" class="fw-medium">{{ $customer->name }}</a><div class="small text-secondary">{{ $customer->email }}</div></td>
                        <td>{{ $customer->phone ?: '—' }}</td>
                        <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($customer->points_balance) }}</span></td>
                        <td class="text-end text-secondary">{{ number_format($customer->points_pending) }}</td>
                        <td><a href="{{ route('dashboard.customers.show', $customer) }}#loyalty" class="btn btn-sm btn-outline-primary text-nowrap">Points history</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$customers" />
    @endif
</div>
@endsection
