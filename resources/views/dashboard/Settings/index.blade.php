@extends('admin.layout')

@php $s = $setting; @endphp

@section('title', 'Settings')
@section('pretitle', 'Operations')
@section('subtitle', 'Store details, legal texts and loyalty rules used by the mobile app.')

@section('actions')
    <button type="submit" form="settings-form" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save settings</button>
@endsection

@section('content')
<form id="settings-form" method="POST" action="{{ $s ? route('dashboard.settings.update', $s->id) : route('dashboard.settings.store') }}">
    @csrf
    @if ($s) @method('PUT') @endif

    <div class="row row-cards">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-building-store me-2 text-secondary"></i>Contact &amp; payments</h3></div>
                <div class="card-body">
                    <x-admin.input name="phone_number" label="Phone number" :value="old('phone_number', $s?->phone_number)" maxlength="20" />
                    <x-admin.input type="url" name="map_location_url" label="Pharmacy map location (URL)" :value="old('map_location_url', $s?->map_location_url)" hint="Also shown in the app as the pickup address." />
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" name="zaincash" label="Zain Cash number" :value="old('zaincash', $s?->zaincash)" />
                        <x-admin.input col="col-md-6 mb-3" name="keycard" label="Qi Card number" :value="old('keycard', $s?->keycard)" />
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-star me-2 text-secondary"></i>Loyalty points</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">Customers earn <strong>points per</strong> every <strong>amount spent</strong> on products (before delivery and coupon).</p>
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="number" name="spend_x" :label="'Amount spent ('.config('pharmacy.currency').')'" :value="old('spend_x', $s?->spend_x)" step="0.01" min="0" />
                        <x-admin.input col="col-md-6 mb-3" type="number" name="get_y" label="Points per amount" :value="old('get_y', $s?->get_y)" min="0" />
                    </div>
                    <x-admin.input type="number" name="points_validity_days" label="Points valid for (days)" :value="old('points_validity_days', $s?->points_validity_days)" min="0"
                                   hint="Note: 0 currently means points expire the same day (see docs/business-rules.md, D10)." />
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-brand-instagram me-2 text-secondary"></i>Social links</h3></div>
                <div class="card-body">
                    @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $field => $label)
                        <x-admin.input type="url" :name="$field" :label="$label" :value="old($field, $s?->$field)" placeholder="https://" />
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-file-text me-2 text-secondary"></i>Legal texts</h3></div>
                <div class="card-body">
                    <x-admin.input type="textarea" name="privacy_policy_en" label="Privacy policy (English)" :value="old('privacy_policy_en', $s?->privacy_policy_en)" rows="4" />
                    <x-admin.input type="textarea" name="privacy_policy_ar" label="Privacy policy (Arabic)" :value="old('privacy_policy_ar', $s?->privacy_policy_ar)" rows="4" dir="rtl" />
                    <x-admin.input type="textarea" name="terms_conditions_en" label="Terms & conditions (English)" :value="old('terms_conditions_en', $s?->terms_conditions_en)" rows="4" />
                    <x-admin.input type="textarea" name="terms_conditions_ar" label="Terms & conditions (Arabic)" :value="old('terms_conditions_ar', $s?->terms_conditions_ar)" rows="4" dir="rtl" />
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
