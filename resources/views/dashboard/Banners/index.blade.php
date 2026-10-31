@extends('admin.layout')

@php
    // The mobile app opens these API lists when a banner is tapped.
    $links = [
        url('/api/v1/user/top-discount-products') => 'Top discounted products',
        url('/api/v1/user/products/on/sale') => 'Products on sale',
        url('/api/v1/user/health_issues') => 'Health issues',
        url('/api/v1/user/categories') => 'Categories',
        url('/api/v1/user/brands') => 'Brands',
    ];
@endphp

@section('title', 'Banners')
@section('pretitle', 'Marketing')
@section('subtitle', 'Home screen banners in the mobile app, in order of appearance.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBanner"><i class="ti ti-plus me-1"></i>New banner</button>
@endsection

@section('content')
<div class="card">
    @if ($banners->isEmpty())
        <x-admin.empty icon="photo-off" title="No banners yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Banner</th><th>Opens</th><th>Status</th><th>Order</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($banners->sortBy('order_of_appearance') as $banner)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <x-admin.thumb :src="$banner->image ? asset($banner->image) : null" size="lg" style="width:7rem" :alt="$banner->title_en" />
                                <div class="min-w-0">
                                    <div class="fw-medium">{{ $banner->title_en }}</div>
                                    <div class="small text-secondary" dir="rtl">{{ $banner->title_ar }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="small">{{ $links[$banner->link] ?? $banner->link }}</td>
                        <td><x-admin.status :status="$banner->status ? 'active' : 'inactive'" /></td>
                        <td>{{ $banner->order_of_appearance }}</td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editBanner{{ $banner->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.banners.destroy', $banner->id)" />
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
    @foreach ([null, ...$banners] as $banner)
        @php $editing = $banner !== null; @endphp
        <x-admin.modal :id="$editing ? 'editBanner'.$banner->id : 'createBanner'" :title="$editing ? 'Edit banner' : 'New banner'"
                       :action="$editing ? route('dashboard.banners.update', $banner->id) : route('dashboard.banners.store')"
                       :method="$editing ? 'PUT' : 'POST'" files :submit="$editing ? 'Save' : 'Create'">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="title_en" label="Title (English)" :value="$banner?->title_en" required />
                <x-admin.input col="col-md-6 mb-3" name="title_ar" label="Title (Arabic)" :value="$banner?->title_ar" required dir="rtl" />
            </div>
            @if ($editing && $banner->image)
                <div class="mb-2"><x-admin.thumb :src="asset($banner->image)" size="lg" style="width:9rem" alt="Current banner" /></div>
            @endif
            <x-admin.input type="file" name="image" :label="$editing ? 'Replace image' : 'Image'" accept="image/*" />
            <div class="mb-3">
                <label class="form-label">Opens in the app</label>
                <select name="link" class="form-select">
                    @foreach ($links as $value => $label)
                        <option value="{{ $value }}" @selected(($banner?->link ?? old('link')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="1" @selected((string) ($banner?->status ?? old('status', '1')) === '1')>Active</option>
                        <option value="0" @selected((string) ($banner?->status ?? old('status', '1')) === '0')>Inactive</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="number" name="order_of_appearance" label="Order" :value="$banner?->order_of_appearance ?? old('order_of_appearance', 0)" min="0" />
            </div>
        </x-admin.modal>
    @endforeach
@endpush
