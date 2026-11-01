@extends('admin.layout')

@section('title', 'Opening ads')
@section('pretitle', 'Marketing')
@section('subtitle', 'Popups shown when the mobile app opens. Customers only see active ads inside their dates, for their device, highest priority first.')

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
                            <img src="{{ asset($ad->image) }}" alt="{{ $ad->title_en ?? 'Opening ad' }}" loading="lazy" style="object-fit: cover">
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($ad->title_en)<div class="fw-medium">{{ $ad->title_en }}</div>@endif
                        <div class="small text-secondary">Priority {{ $ad->priority }} · {{ str_replace('_', ' ', $ad->display_frequency) }} · {{ $ad->device === 'all' ? 'all devices' : $ad->device }}</div>
                        <div class="small text-secondary">{{ $ad->starts_at?->format('M j, Y H:i') ?? 'Any time' }} → {{ $ad->ends_at?->format('M j, Y H:i') ?? 'no end' }}</div>
                    </div>
                    <div class="card-footer d-flex align-items-center justify-content-between">
                        <x-admin.status :status="$ad->state()" />
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
    @foreach ([null, ...$OpeningAds] as $ad)
        @php $editing = $ad !== null; @endphp
        <x-admin.modal :id="$editing ? 'editOpeningAd'.$ad->id : 'createOpeningAd'" :title="$editing ? 'Edit opening ad' : 'New opening ad'"
                       :action="$editing ? route('dashboard.OpeningAd.update', $ad->id) : route('dashboard.OpeningAd.store')" :method="$editing ? 'PUT' : 'POST'" files size="lg"
                       :submit="$editing ? 'Save' : 'Create'">
            @if ($editing && $ad->image)
                <div class="mb-3"><x-admin.thumb :src="asset($ad->image)" size="lg" alt="Current image" /></div>
            @endif
            <x-admin.input type="file" name="image" :label="$editing ? 'Replace image' : 'Image'" accept="image/jpeg,image/png,image/webp" :required="!$editing" hint="JPEG, PNG or WebP, up to 2 MB and 4000 px." />
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="title_en" label="Title (English)" :value="$ad?->title_en" maxlength="255" />
                <x-admin.input col="col-md-6 mb-3" name="title_ar" label="Title (Arabic)" :value="$ad?->title_ar" maxlength="255" dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="content_en" label="Text (English)" :value="$ad?->content_en" rows="2" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="content_ar" label="Text (Arabic)" :value="$ad?->content_ar" rows="2" dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" type="url" name="link" label="Opens (URL)" :value="$ad?->link" maxlength="255" />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Show</label>
                    <select name="display_frequency" class="form-select">
                        @foreach (['every_open' => 'Every time the app opens', 'once_per_session' => 'Once per session', 'once_per_day' => 'Once a day', 'once' => 'Only once'] as $value => $label)
                            <option value="{{ $value }}" @selected(($ad?->display_frequency ?? 'every_open') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-admin.input col="col-md-4 mb-3" type="number" name="priority" label="Priority" :value="$ad?->priority ?? 0" min="-1000" max="1000" />
                <div class="col-md-4 mb-3">
                    <label class="form-label">Device</label>
                    <select name="device" class="form-select">
                        @foreach (\App\Models\Banner::DEVICES as $device)
                            <option value="{{ $device }}" @selected(($ad?->device ?? 'all') === $device)>{{ $device === 'all' ? 'All devices' : ucfirst($device) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select">
                        <option value="1" @selected($ad?->is_active)>Active</option>
                        <option value="0" @selected(!$ad?->is_active)>Inactive</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="starts_at" label="Show from" :value="$ad?->starts_at?->format('Y-m-d\TH:i')" hint="Empty = now." />
                <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="ends_at" label="Show until" :value="$ad?->ends_at?->format('Y-m-d\TH:i')" hint="Empty = no end." />
            </div>
        </x-admin.modal>
    @endforeach
@endpush
