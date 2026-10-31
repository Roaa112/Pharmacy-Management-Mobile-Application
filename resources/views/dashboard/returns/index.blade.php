@extends('admin.layout')

@php $tabs = ['' => 'All', 'pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'canceled' => 'Canceled by customer']; @endphp

@section('title', 'Returns')
@section('pretitle', 'Sales')
@section('subtitle', 'Customer return and refund requests. Accepting records the decision only: no money is moved and stock is not restored.')

@section('content')
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            @foreach ($tabs as $value => $label)
                <li class="nav-item">
                    <a href="{{ route('dashboard.returns.index', array_filter(['status' => $value])) }}" class="nav-link {{ (string) request('status') === $value ? 'active' : '' }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($refunds->isEmpty())
        <x-admin.empty icon="receipt-off" title="No return requests" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Request</th><th>Order</th><th>Customer</th><th>Reason</th><th class="text-end">Requested refund</th><th>Status</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($refunds as $refund)
                    <tr>
                        <td><a href="{{ route('dashboard.returns.show', $refund->id) }}" class="fw-medium">#{{ $refund->id }}</a>
                            <div class="small text-secondary">{{ $refund->created_at->format('M j, Y') }}</div></td>
                        <td>
                            @if ($refund->order)
                                <a href="{{ route('dashboard.orders.show', $refund->order) }}">#{{ $refund->order_id }}</a>
                                <div class="small text-secondary"><x-admin.money :amount="$refund->order->total_price" /></div>
                            @else
                                #{{ $refund->order_id }}
                            @endif
                        </td>
                        <td>{{ $refund->user->name ?? '—' }}</td>
                        <td class="text-truncate" style="max-width: 14rem" title="{{ $refund->reason }}">{{ $refund->reason }}</td>
                        <td class="text-end">
                            @if ($refund->refund_amount !== null)
                                <x-admin.money :amount="$refund->refund_amount" />
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td><x-admin.status :status="$refund->status" /></td>
                        <td><a href="{{ route('dashboard.returns.show', $refund->id) }}" class="btn btn-sm {{ $refund->status === 'pending' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $refund->status === 'pending' ? 'Review' : 'View' }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$refunds->withQueryString()" />
    @endif
</div>
@endsection
