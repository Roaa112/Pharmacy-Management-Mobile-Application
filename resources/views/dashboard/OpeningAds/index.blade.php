@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>OpeningAds</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createOpeningAdModal">Add OpeningAd</button>
    </div>

    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Is Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($OpeningAds as $OpeningAd)
                <tr>
                    <td><img src="{{ asset($OpeningAd->image) }}" width="80"></td>
                    <td>{{ $OpeningAd->is_active ? 'Yes' : 'No' }}</td>
                    <td>
                        <button class="btn btn-sm btn-info edit-openingad-btn"
                            data-toggle="modal"
                            data-target="#editOpeningAdModal{{ $OpeningAd->id }}"
                            data-id="{{ $OpeningAd->id }}"
                            data-is_active="{{ $OpeningAd->is_active }}"
                            data-image="{{ $OpeningAd->image_url }}">
                            Edit
                        </button>

                        <form action="{{ route('dashboard.OpeningAd.destroy', $OpeningAd->id) }}" method="POST" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                @include('dashboard.OpeningAds.modals.edit', ['OpeningAd' => $OpeningAd])
                @empty
                <tr>
                    <td colspan="3">No OpeningAds found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('dashboard.OpeningAds.modals.create')
@stop

@section('css')
@stop

@section('js')
<script>
    console.log("Hi, I'm using the Laravel-AdminLTE package!");
</script>
@stop
