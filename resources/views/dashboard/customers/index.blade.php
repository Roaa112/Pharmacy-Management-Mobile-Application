@extends('admin.layout')

@section('title', 'Customers')
@section('pretitle', 'Sales')
@section('subtitle', 'Registered app users and their purchase history.')

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-4"><x-admin.stat label="Customers" icon="users" tone="azure" :value="number_format($total)" /></div>
    <div class="col-sm-4"><x-admin.stat label="New this month" icon="user-plus" tone="teal" :value="number_format($newThisMonth)" /></div>
    <div class="col-sm-4"><x-admin.stat label="Have ordered" icon="shopping-bag" :value="number_format($withOrders)"
                                        :hint="$total ? round($withOrders / $total * 100).'% of customers' : null" /></div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 w-100" style="max-width: 30rem">
            <div class="input-icon flex-fill">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" name="search" class="form-control" placeholder="Name, email or phone" value="{{ $search }}">
            </div>
            <button class="btn btn-primary">Search</button>
        </form>
    </div>
    @if ($customers->isEmpty())
        <x-admin.empty icon="users" title="No customers found" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Customer</th><th>Phone</th><th class="text-end">Orders</th><th class="text-end">Spent</th><th>Last order</th><th>Joined</th></tr></thead>
                <tbody>
                @foreach ($customers as $customer)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-sm bg-azure-lt">{{ strtoupper(mb_substr($customer->name ?: '?', 0, 1)) }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route('dashboard.customers.show', $customer) }}" class="fw-medium text-reset">{{ $customer->name }}</a>
                                    <div class="small text-secondary text-truncate" style="max-width: 16rem">{{ $customer->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="small">{{ $customer->phone ?? '—' }}</td>
                        <td class="text-end">{{ $customer->orders_count }}</td>
                        <td class="text-end"><x-admin.money :amount="$customer->spent ?? 0" /></td>
                        <td class="small">{{ $customer->last_order_at ? \Carbon\Carbon::parse($customer->last_order_at)->diffForHumans() : '—' }}</td>
                        <td class="small text-secondary">{{ $customer->created_at?->format('M j, Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$customers" />
    @endif
</div>
@endsection
