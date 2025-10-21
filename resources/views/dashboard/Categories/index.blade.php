@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Categories</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createCategoryModal">Add Category</button>

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
                @forelse($categories as $category)
                <tr>
                    <td><img src="{{ asset( $category->image) }}" width="80"></td>
             
                    <td><strong>{{ $category->name_ar }}</strong></td>
                    <td><strong>{{ $category->name_en }}</strong></td>
                    <td>
                        <button class="btn btn-sm btn-success" data-toggle="modal"
                            data-target="#addSubcategoryModal-{{ $category->id }}">
                            Add Subcategory
                        </button>

                        <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editCategoryModal{{ $category->id }}"
                            data-id="{{ $category->id }}" data-image="{{ $category->image }}" data-name="{{ $category->name_ar }}"data-name="{{ $category->name_en }}">Edit</button>

                        <form action="{{ route('dashboard.categories.destroy', $category) }}" method="POST"
                            class="d-inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                <!-- Loop Through Subcategories -->
                @foreach($category->subcategories as $sub)
                <tr>
                    <td>-- <img src="{{ asset($sub->image) }}" width="80"></td>
                    <td>-- {{ $sub->name_ar }}</td>
                    <td>-- {{ $sub->name_en }}</td>
                    <td>
                        <!-- Edit & Delete buttons for subcategory -->
                        <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editCategoryModal{{$sub->id }}"
                            data-id="{{ $sub->id }}"data-image="{{ $sub->image }}" data-name="{{ $sub->name_ar }}" data-name="{{ $sub->name_en }}">Edit</button>

                        <form action="{{ route('dashboard.categories.destroy', $sub) }}" method="POST" class="d-inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Modal for Subcategory -->
                @include('dashboard.Categories.modals.edit', ['category' => $sub])
                @endforeach

                <!-- Edit Modal for Parent -->
                @include('dashboard.Categories.modals.edit', ['category' => $category])
                @include('dashboard.Categories.modals.sub', ['parent' => $category])

                @empty
                <tr>
                    <td colspan="2">No categories found.</td>
                </tr>
                @endforelse

            </tbody>
        </table>
    </div>
</div>
@include('dashboard.Categories.modals.create')
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