@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>Settings</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createSettingModal">Add Setting</button>
    </div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Privacy Policy (AR)</th>
                    <th>Privacy Policy (EN)</th>
                    <th>Terms (AR)</th>
                    <th>Terms (EN)</th>
                    <th>Facebook</th>
                    <th>Instagram</th>
                    <th>TikTok</th>
                    <th>YouTube</th>
                    <th>Phone</th>
                    <th>Zain Cash Number</th>
                    <th>Map Location</th>

                          <th>Buy By value</th>
                                <th>Get points </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($settings as $setting)
                    <tr>
                        <td>{{ Str::limit($setting->privacy_policy_ar, 50) }}</td>
                        <td>{{ Str::limit($setting->privacy_policy_en, 50) }}</td>
                        <td>{{ Str::limit($setting->terms_conditions_ar, 50) }}</td>
                        <td>{{ Str::limit($setting->terms_conditions_en, 50) }}</td>
                        <td>{{ $setting->facebook }}</td>
                        <td>{{ $setting->instagram }}</td>
                        <td>{{ $setting->tiktok }}</td>
                        <td>{{ $setting->youtube }}</td>
                        <td>{{ $setting->phone_number }}</td>
                              <td>{{ $setting->zaincash }}</td>
                        <td>
                            @if($setting->map_location_url)
                                <a href="{{ $setting->map_location_url }}" target="_blank">View Map</a>
                            @endif
                        </td>

                          <td>{{ $setting->spend_x }}</td>
                            <td>{{ $setting->get_y }}</td>
                        <td>
                            <button class="btn btn-sm btn-info edit-Setting-btn"
                                data-toggle="modal"
                                data-target="#editSettingModal"
                                data-id="{{ $setting->id }}"
                                data-privacy_policy_ar="{{ $setting->privacy_policy_ar }}"
                                data-privacy_policy_en="{{ $setting->privacy_policy_en }}"
                                data-terms_conditions_ar="{{ $setting->terms_conditions_ar }}"
                                data-terms_conditions_en="{{ $setting->terms_conditions_en }}"
                                data-facebook="{{ $setting->facebook }}"
                                data-instagram="{{ $setting->instagram }}"
                                data-tiktok="{{ $setting->tiktok }}"
                                data-youtube="{{ $setting->youtube }}"
                                data-phone_number="{{ $setting->phone_number }}"
                                data-map_location_url="{{ $setting->map_location_url }}"
                                 data-spend_x="{{ $setting->spend_x }}"
                                      data-get_y="{{ $setting->get_y }}"
                                           data-zaincash="{{ $setting->zaincash }}"
                            >
                                Edit
                            </button>

                            <form action="{{ route('dashboard.settings.destroy', $setting->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('{{ __('Are you sure?') }}')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>

                    @include('dashboard.Settings.modals.edit', ['setting' => $setting])
                @empty
                    <tr>
                        <td colspan="11">No Settings found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('dashboard.Settings.modals.create')
@stop

@section('css')
{{-- Custom styles if needed --}}
@stop

@section('js')
<script>
    console.log("Settings dashboard loaded.");
</script>
@stop
