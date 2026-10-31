@extends('admin.layout')

@section('title', 'Brands')
@section('pretitle', 'Catalog')
@section('subtitle', 'Brands shown on product pages and used for brand discounts.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createbrand"><i class="ti ti-plus me-1"></i>New brand</button>
@endsection

@section('content')
<div class="card">
    @if ($Brands->isEmpty())
        <x-admin.empty icon="badge-tm" title="No brands yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Brand</th><th>Arabic name</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($Brands as $brand)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <x-admin.thumb :src="$brand->image ? asset($brand->image) : null" :alt="$brand->name_en" />
                                <span class="fw-medium">{{ $brand->name_en }}</span>
                            </div>
                        </td>
                        <td dir="rtl" class="text-end text-md-start">{{ $brand->name_ar }}</td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editbrand{{ $brand->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.brands.destroy', $brand->id)" />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="createbrand" title="New brand" :action="route('dashboard.brands.store')" files submit="Create">
        <x-admin.input name="name_en" label="Name (English)" required />
        <x-admin.input name="name_ar" label="Name (Arabic)" required dir="rtl" />
        <x-admin.input type="file" name="image" label="Image" accept="image/*" />
    </x-admin.modal>

    @foreach ($Brands as $brand)
        <x-admin.modal :id="'editbrand'.$brand->id" title="Edit brand" :action="route('dashboard.brands.update', $brand->id)" method="PUT" files>
            <x-admin.input name="name_en" label="Name (English)" :value="$brand->name_en" required />
            <x-admin.input name="name_ar" label="Name (Arabic)" :value="$brand->name_ar" required dir="rtl" />
            <x-admin.input type="file" name="image" label="Replace image" accept="image/*" hint="Leave empty to keep the current image." />
        </x-admin.modal>
    @endforeach
@endpush
