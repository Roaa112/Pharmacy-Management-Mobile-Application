@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard - Sales Overview</h1>
@stop

@section('content')
<div class="row">
    <div class="col-md-3">
        <div class="small-box bg-info">
            <div class="inner">
                <h3>{{ $totalOrders }}</h3>
                <p>Total Orders</p>
            </div>
            <div class="icon"><i class="fas fa-shopping-cart"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-success">
            <div class="inner">
                <h3>${{ number_format($totalRevenue, 2) }}</h3>
                <p>Total Revenue</p>
            </div>
            <div class="icon"><i class="fas fa-dollar-sign"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-primary">
            <div class="inner">
                <h3>{{ $confirmedOrders }}</h3>
                <p>Confirmed Orders</p>
            </div>
            <div class="icon"><i class="fas fa-check"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3>{{ $canceledOrders }}</h3>
                <p>Canceled Orders</p>
            </div>
            <div class="icon"><i class="fas fa-times"></i></div>
        </div>
    </div>
</div>

@if($bestSellingProducts->isNotEmpty())
<div class="card mt-4">
    <div class="card-header">
        <h3 class="card-title">Top 5 Best-Selling Products</h3>
    </div>
    <div class="card-body">
        <div class="row">
            @foreach($bestSellingProducts as $item)
                @php $product = $item->product; @endphp
                <div class="col-md-4 col-lg-3 mb-4">
                    <div class="card h-100">
                        <img src="{{ $product->image ?? '/images/no-image.png' }}" class="card-img-top" alt="{{ $product->name }}" style="height:180px; object-fit:cover;">
                        <div class="card-body">
                            <h5 class="card-title">{{ $product->name ?? 'Unknown Product' }}</h5>
                            <p class="card-text mb-1"><strong>Sold:</strong> {{ $item->total_sold }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif
@stop

@section('css')
    <style>
        .card img {
            border-bottom: 1px solid #ddd;
        }
    </style>
@stop

@section('js')
    <script> console.log("Dashboard analytics loaded successfully."); </script>
@stop
