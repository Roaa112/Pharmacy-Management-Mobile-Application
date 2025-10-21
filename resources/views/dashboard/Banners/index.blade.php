{{-- resources/views/dashboard/banners/index.blade.php --}}

@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Banners</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Banners</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createBannerModal">Add Banner</button>
    </div>

    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>title_ar</th>
                    <th>title_en</th>
                    <th>Image</th>
                    <th>Link</th>
                    <th>Status</th>
                    <th>Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($banners as $banner)
                <tr>
                    <td>{{ $banner->title_ar }}</td>
                    <td>{{ $banner->title_en }}</td>
                    <td><img src="{{ asset( $banner->image) }}" width="80"></td>
                    <td>{{ $banner->link }}</td>
                    <td>{{ $banner->status ? 'Active' : 'Inactive' }}</td>
                    <td>{{ $banner->order_of_appearance }}</td>
                    <td>
                    
                        <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editBannerModal{{ $banner->id }}"
                            data-id="{{ $banner->id }}" data-title="{{ $banner->title_ar }}" data-title="{{ $banner->title_en }}">Edit</button> 

                        {{-- Delete --}}
                        <form action="{{ route('dashboard.banners.destroy', $banner->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                @include('dashboard.Banners.modals.edit', ['banner' => $banner])
                @empty
                <tr>
                    <td colspan="6">No banners found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('dashboard.Banners.modals.create')
@stop

@section('css')
{{-- أي ستايلات إضافية هنا --}}
@stop

@section('js')
<script>
    console.log("Banners page loaded!");
</script>
@stop
