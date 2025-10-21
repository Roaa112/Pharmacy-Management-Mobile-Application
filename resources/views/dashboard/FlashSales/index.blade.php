@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>FlashSales</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="mb-0">FlashSales</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createFlashSaleModal">Add FlashSale</button>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Discount Value</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($flashSales as $flashSale)
                <tr>
                    <td>{{ $flashSale->discount_value }}%</td>
                    <td>
                        @if($flashSale->is_active)
                        <span class="badge badge-success">Active</span>
                        @else
                        <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </td>
                    <td>{{ $flashSale->date }}</td>
                    <td>{{ $flashSale->time }}</td>
                    <td>
                        <button class="btn btn-sm btn-info" data-toggle="modal"
                            data-target="#editFlashSaleModal-{{ $flashSale->id }}">
                            Edit
                        </button>

                        <form action="{{ route('dashboard.flash_sales.destroy', $flashSale) }}" method="POST"
                            class="d-inline"
                            onsubmit="return confirm('Are you sure you want to delete this FlashSale?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                {{-- Include Edit Modal --}}
                @include('dashboard.FlashSales.modals.edit', ['flashSale' => $flashSale])
                @empty
                <tr>
                    <td colspan="5" class="text-center">No FlashSales found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Include Create Modal --}}
@include('dashboard.FlashSales.modals.create')
@stop

@section('css')
{{-- Custom CSS if needed --}}
@stop

@section('js')
<script>
console.log("FlashSale management loaded!");
</script>
@stop
