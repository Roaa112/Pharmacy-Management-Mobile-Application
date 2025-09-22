@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Products</h3>
        <button type="button" class="btn btn-primary mb-3" data-toggle="modal" data-target="#addProductModal">
        Add Product
    </button>    </div>
    <div class="card-body">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="thead-light">
                <tr>
                    <th>Main Image</th>
                    <th>Name Ar</th>
                    <th>Name En</th>
                    <th>Description Ar</th>
                    <th>Description En</th>
                    <th>Price</th>
                    <th>quantity</th>
                    <th>Rate</th>
                    <th>Brand</th>
                    <th>Category</th>
                    <th>Health Issues</th>
                    <th>Sale Type</th>

                    <th>Extra images</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                <tr>
                    <td>
                        @if($product->image)
                        <img src="{{ asset( $product->image) }}" width="50" height="50" class="rounded"
                            style="object-fit: cover;">
                        @else
                        <span class="text-muted">N/A</span>
                        @endif
                    </td>
                    <td><strong>{{ $product->name_ar }}</strong></td>
                    <td><strong>{{ $product->name_en }}</strong></td>
                    <td style="max-width: 200px;">
                        <div class="text-truncate" style="max-width: 200px;" title="{{ $product->description_ar }}">
                            {{ $product->description_ar }}
                        </div>
                    </td>
                    <td style="max-width: 200px;">
                        <div class="text-truncate" style="max-width: 200px;" title="{{ $product->description_en }}">
                            {{ $product->description_en }}
                        </div>
                    </td>
                  <td>
    @forelse($product->sizes as $size)
        <div>{{ $size->size }}: {{ number_format($size->price, 2) }} EGP</div>
    @empty
        <span class="text-muted">No Sizes</span>
    @endforelse
</td>
<td>
    @forelse($product->sizes as $size)
        <div>{{ $size->size }}: {{ $size->stock }} pcs</div>
    @empty
        <span class="text-muted">N/A</span>
    @endforelse
</td>

                    <td>{{ $product->rate }}</td>
                    <td>{{ $product->brand->name ?? 'N/A' }}</td>
                    <td>{{ $product->category->name ?? 'N/A' }}</td>
                    <td>
                        @foreach($product->healthIssues as $issue)
                        <span class="badge badge-info">{{ $issue->name }}</span>
                        @endforeach
                    </td>
                    <td>
                        @if($product->saleable)

                        @if($product->saleable_type === \App\Models\FlashSale::class)
                        <span class="badge badge-warning">Flash Sale</span>
                        <br>
                        <small>{{ $product->saleable->discount_value }} EGP - {{ $product->saleable->date }}
                            {{ $product->saleable->time }}</small>
                        @endif
                        @else
                        <span class="text-muted">None</span>
                        @endif
                    </td>

                    <td>
                        @if($product->productImages && $product->productImages->isNotEmpty())
                        @foreach($product->productImages as $image)
                        <img src="{{ asset( $image->image_path) }}" width="50" height="50"
                            class="rounded me-1 mb-1" style="object-fit: cover;">
                        @endforeach
                        @else
                        <span class="text-muted">None</span>
                        @endif
                    </td>

                    <td>
                        <button class="btn btn-sm btn-info mb-1" data-toggle="modal"
                            data-target="#editProductModal-{{ $product->id }}">Edit</button>


                        <form action="{{ route('dashboard.products.destroy', $product) }}" method="POST"
                            class="d-inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        @include('dashboard.Products.modals.edit', ['product' => $product])
                    </td>
                </tr>


                @empty
                <tr>
                    <td colspan="14">No products found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>

@include('dashboard.Products.modals.create')
@stop

@section('css')
{{-- Add custom styles if needed --}}
@stop

@section('js')
<script>
console.log("Hi, I'm using the Laravel-AdminLTE package!");
</script>
@stop

