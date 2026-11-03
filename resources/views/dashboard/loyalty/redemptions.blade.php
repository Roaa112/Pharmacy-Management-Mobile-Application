@extends('admin.layout')

@section('title', 'Redemptions')
@section('pretitle', 'Loyalty')
@section('subtitle', 'Rewards and checkout payments made with points. Cancelling before hand-over returns the stock and the points.')

@section('content')
@include('dashboard.loyalty._tabs')

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-4"><input type="search" name="search" class="form-control" placeholder="Number or customer" value="{{ $search }}"></div>
            <div class="col-md-3">
                <select name="status" class="form-select" aria-label="Status"><option value="">Any status</option>
                    @foreach (\App\Models\LoyaltyRedemption::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }} ({{ $counts[$status] ?? 0 }})</option>@endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="source" class="form-select" aria-label="Source"><option value="">Rewards and checkout</option>
                    <option value="reward" @selected(request('source') === 'reward')>Rewards</option>
                    <option value="checkout" @selected(request('source') === 'checkout')>Paid at checkout</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary"><i class="ti ti-filter me-1"></i>Filter</button></div>
        </form>
    </div>
    @if ($redemptions->isEmpty())
        <x-admin.empty icon="ticket-off" title="No redemptions" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Number</th><th>Customer</th><th>Reward</th><th class="text-end">Points</th><th>Status</th><th>History</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($redemptions as $redemption)
                    <tr>
                        <td class="fw-medium text-nowrap">{{ $redemption->number }}<div class="small text-secondary">{{ $redemption->created_at->format('M j, Y H:i') }}</div></td>
                        <td>@if ($redemption->user)<a href="{{ route('dashboard.customers.show', $redemption->user_id) }}">{{ $redemption->user->name }}</a>@endif</td>
                        <td>{{ $redemption->title() }}@if ($redemption->quantity > 1) × {{ $redemption->quantity }}@endif
                            @if ($redemption->order_id)<div class="small"><a href="{{ route('dashboard.orders.show', $redemption->order_id) }}">order #{{ $redemption->order_id }}</a>@if ($redemption->value) · {{ number_format($redemption->value, 2) }} paid @endif</div>@endif</td>
                        <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($redemption->points) }}</span></td>
                        <td><x-admin.status :status="$redemption->status" /></td>
                        <td class="small text-secondary">
                            @if ($redemption->creator)By {{ $redemption->creator->name }}<br>@endif
                            @if ($redemption->fulfilled_at)Fulfilled {{ $redemption->fulfilled_at->format('M j') }}@if ($redemption->fulfiller) by {{ $redemption->fulfiller->name }}@endif<br>@endif
                            @if ($redemption->cancelled_at){{ ucfirst($redemption->status) }} {{ $redemption->cancelled_at->format('M j') }}@if ($redemption->canceller) by {{ $redemption->canceller->name }}@endif: {{ $redemption->cancellation_reason }}<br>@endif
                            @if ($redemption->isOpen() && $redemption->expires_at)Collect by {{ $redemption->expires_at->format('M j, Y') }}@endif
                        </td>
                        <td class="text-nowrap">
                            @if ($redemption->isOpen())
                                @can('loyalty.manage')
                                    @if ($redemption->status === 'pending')
                                        <form method="POST" action="{{ route('dashboard.loyalty.redemptions.approve', $redemption) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">Approve</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('dashboard.loyalty.redemptions.fulfil', $redemption) }}" class="d-inline" data-confirm="Hand over {{ $redemption->title() }}?">@csrf<button class="btn btn-sm btn-outline-success">Fulfil</button></form>
                                    <form method="POST" action="{{ route('dashboard.loyalty.redemptions.cancel', $redemption) }}" class="d-inline-flex gap-1 mt-1">@csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason" required minlength="3" maxlength="250" style="width: 9rem" aria-label="Cancellation reason">
                                        <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$redemptions" />
    @endif
</div>
@endsection
