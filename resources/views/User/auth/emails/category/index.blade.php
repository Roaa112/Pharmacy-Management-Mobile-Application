@extends('dashboard.layouts.app')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Categories</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">Add Category</button>
    </div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr><th>Name</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td>{{ $category->name }}</td>
                        <td>
                            <!-- Edit Modal -->
                            <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editModal{{ $category->id }}">Edit</button>

                            <!-- Add Subcategory Modal -->
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addSubModal{{ $category->id }}">Add Subcategory</button>

                            <form action="{{ route('dashboard.categories.destroy', $category) }}" method="POST" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>

                    <!-- Edit Modal -->
                    @include('dashboard.categories.modals.edit', ['category' => $category])

                    <!-- Add Subcategory Modal -->
                    @include('dashboard.categories.modals.sub', ['parent' => $category])
                @empty
                    <tr><td colspan="2">No categories found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Add Category Modal -->
@include('dashboard.categories.modals.create')

@endsection
