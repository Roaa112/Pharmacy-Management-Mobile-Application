@extends('adminlte::page')

@section('title', 'Coupons')

@section('content_header')
    <h1>Coupons Management</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Coupons</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createCouponModal">Add Coupon</button>
    </div>
    @if ($errors->any())
    <div class="alert alert-danger">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

    <div class="card-body">
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>Code</th>
                    <th>Discount</th>
                    <th>Active</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Usage Limit</th>
                    <th>Used Count</th>
                    <th>Once Per User</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($coupons as $coupon)
                    <tr>
                        <td>{{ $coupon->code }}</td>
                        <td>{{ $coupon->discount_value }}%</td>
                        <td>
                            @if($coupon->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </td>
                        <td>{{ $coupon->start_at ? $coupon->start_at->format('Y-m-d') : '-' }}</td>
                        <td>{{ $coupon->end_at ? $coupon->end_at->format('Y-m-d') : '-' }}</td>
                        <td>{{ $coupon->usage_limit }}</td>
                        <td>{{ $coupon->used_count }}</td>
                        <td>{{ $coupon->once_per_user ? 'Yes' : 'No' }}</td>
                        <td>
                        <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editCouponModal{{ $coupon->id }}">Edit</button>


                            <form action="{{ route('dashboard.coupons.destroy', $coupon) }}" method="POST"
                                style="display:inline;">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>

                    @include('dashboard.Coupons.modals.edit', ['coupon' => $coupon])
                @empty
                    <tr>
                        <td colspan="9" class="text-center">No coupons found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('dashboard.Coupons.modals.create')
@stop

@section('css')
@stop

@section('js')
<script>
    console.log('Coupons page loaded!');
</script>
@stop
