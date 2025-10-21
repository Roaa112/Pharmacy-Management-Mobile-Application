@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Brands</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createBrandModal">Add Brand</button>

    </div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Name Ar</th>
                    <th>Name En</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($Brands as $brand)
                <tr>
                    <td><img src="{{ asset( $brand->image ) }}" width="80"></td>
                
                    <td><strong>{{ $brand->name_ar }}</strong></td>
                    <td><strong>{{ $brand->name_en }}</strong></td>
                    <td>
                        
                    <button class="btn btn-sm btn-info edit-brand-btn"
                        data-toggle="modal"
                        data-target="#editBrandModal{{$brand->id }}"
                        data-id="{{$brand->id }}"
                        data-name_ar="{{ $brand->name_ar }}"
                        data-name_en="{{ $brand->name_en }}"
                        data-image="{{ $brand->image }}">
                        Edit
                    </button>

                        <form action="{{ route('dashboard.brands.destroy', $brand) }}" method="POST"
                            class="d-inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

              
              

                <!-- Edit Modal for Parent -->
                @include('dashboard.Brands.modals.edit', ['brand' => $brand])
        

                @empty
                <tr>
                    <td colspan="2">No brands found.</td>
                </tr>
                @endforelse

            </tbody>
        </table>
    </div>
</div>
@include('dashboard.Brands.modals.create')
@stop



@section('css')
{{-- Add here extra stylesheets --}}
{{-- <link rel="stylesheet" href="/css/admin_custom.css"> --}}
@stop

@section('js')
<script>
console.log("Hi, I'm using the Laravel-AdminLTE package!");
</script>
@stop