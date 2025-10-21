@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>DeliveryPrices</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="mb-0">DeliveryPrices</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createDeliveryPriceModal">Add DeliveryPrice</button>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>governorate</th>
                    <th>price</th>

                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($deliveryPrice as $delivery)
                <tr>
                    <td>{{ $delivery->governorate }}</td>

                    <td>{{ $delivery->price }}</td>

                    <td>
                        <button class="btn btn-sm btn-info" data-toggle="modal"
                            data-target="#editDeliveryPriceModal-{{ $delivery->id }}">
                            Edit
                        </button>

                        <form action="{{ route('dashboard.delivery_prices.destroy', $delivery) }}" method="POST"
                            class="d-inline"
                            onsubmit="return confirm('Are you sure you want to delete this DeliveryPrice?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                {{-- Include Edit Modal --}}
                @include('dashboard.DeliveryPrice.modals.edit', ['flashSale' => $delivery])
                @empty
                <tr>
                    <td colspan="5" class="text-center">No DeliveryPrices found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Include Create Modal --}}
@include('dashboard.DeliveryPrice.modals.create')
@stop

@section('css')
{{-- Custom CSS if needed --}}
@stop

@section('js')
<script>
console.log("DeliveryPrice management loaded!");
</script>
@stop
