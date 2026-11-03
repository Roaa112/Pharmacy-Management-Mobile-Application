@extends('admin.layout')

@section('title', 'Point transactions')
@section('pretitle', 'Loyalty')
@section('subtitle', 'Every points movement, never edited or deleted.')

@section('actions')
    <x-admin.data-transfer :entities="['loyalty_transactions']" />
@endsection

@section('content')
@include('dashboard.loyalty._tabs')

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-3"><input type="search" name="customer" class="form-control" placeholder="Customer" value="{{ request('customer') }}"></div>
            <div class="col-md-2">
                <select name="type" class="form-select" aria-label="Type">
                    <option value="">All types</option>
                    @foreach ($types as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2"><input type="date" name="from" class="form-control" value="{{ request('from') }}" aria-label="From"></div>
            <div class="col-md-2"><input type="date" name="to" class="form-control" value="{{ request('to') }}" aria-label="To"></div>
            <div class="col-md-1"><input type="text" name="order_id" class="form-control" placeholder="Order" value="{{ request('order_id') }}" inputmode="numeric"></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary"><i class="ti ti-filter me-1"></i>Filter</button>
                @if (request()->hasAny(['customer', 'type', 'from', 'to', 'order_id']))<a href="{{ route('dashboard.loyalty.transactions') }}" class="btn btn-outline-secondary">Clear</a>@endif</div>
        </form>
    </div>
    @include('dashboard.loyalty._entries', ['entries' => $entries, 'showCustomer' => true])
    <x-admin.pagination :paginator="$entries" />
</div>
@endsection
