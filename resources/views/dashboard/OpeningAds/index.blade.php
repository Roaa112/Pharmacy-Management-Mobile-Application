@extends('admin.layout')

@section('title', 'Opening ads')
@section('pretitle', 'Marketing')
@section('subtitle', 'Full-screen images shown when the mobile app opens.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createOpeningAd"><i class="ti ti-plus me-1"></i>New opening ad</button>
@endsection

@section('content')
@if ($OpeningAds->isEmpty())
    <div class="card"><x-admin.empty icon="device-mobile-off" title="No opening ads yet" /></div>
@else
    <div class="row row-cards">
        @foreach ($OpeningAds as $ad)
            <div class="col-sm-6 col-lg-4 col-xl-3">
                <div class="card h-100">
                    <div class="ratio ratio-4x3 bg-light rounded-top overflow-hidden">
                        @if ($ad->image)
                            <img src="{{ asset($ad->image) }}" alt="Opening ad" loading="lazy" style="object-fit: cover">
                        @endif
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <x-admin.status :status="$ad->is_active ? 'active' : 'inactive'" />
                        <div class="btn-list flex-nowrap">
                            <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editOpeningAd{{ $ad->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                            <x-admin.delete :action="route('dashboard.OpeningAd.destroy', $ad->id)" />
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection

@push('modals')
    <x-admin.modal id="createOpeningAd" title="New opening ad" :action="route('dashboard.OpeningAd.store')" files submit="Create">
        <x-admin.input type="file" name="image" label="Image" accept="image/*" required />
        <label class="form-label">Status</label>
        <select name="is_active" class="form-select">
            <option value="1">Active</option>
            <option value="0" selected>Inactive</option>
        </select>
    </x-admin.modal>

    @foreach ($OpeningAds as $ad)
        <x-admin.modal :id="'editOpeningAd'.$ad->id" title="Edit opening ad" :action="route('dashboard.OpeningAd.update', $ad->id)" method="PUT" files>
            @if ($ad->image)
                <div class="mb-3"><x-admin.thumb :src="asset($ad->image)" size="lg" alt="Current image" /></div>
            @endif
            <x-admin.input type="file" name="image" label="Replace image" accept="image/*" hint="Leave empty to keep the current image." />
            <label class="form-label">Status</label>
            <select name="is_active" class="form-select">
                <option value="1" @selected($ad->is_active)>Active</option>
                <option value="0" @selected(!$ad->is_active)>Inactive</option>
            </select>
        </x-admin.modal>
    @endforeach
@endpush
