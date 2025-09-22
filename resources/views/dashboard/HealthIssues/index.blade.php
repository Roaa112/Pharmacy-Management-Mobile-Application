@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3>HealthIssues</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createHealthIssueModal">Add HealthIssue</button>
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
                @forelse($healthIssues as $healthIssue)
                    <tr>
                        <td><img src="{{ asset( $healthIssue->image) }}" width="80"></td>
                        <td><strong>{{ $healthIssue->name_ar }}</strong></td>
                        <td><strong>{{ $healthIssue->name_en }}</strong></td>
                        <td>
                            <button class="btn btn-sm btn-info edit-HealthIssue-btn"
    data-toggle="modal"
    data-target="#editHealthIssueModal{{ $healthIssue->id }}"
                                data-id="{{$healthIssue->id }}"

                                data-name_ar="{{ $healthIssue->name_ar }}"
                                data-image="{{ $healthIssue->image }}"
                                data-name_en="{{ $healthIssue->name_en }}">
                                Edit
                            </button>

                            <form action="{{ route('dashboard.health_issues.destroy', $healthIssue->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('{{ __('Are you sure?') }}')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>

                    <!-- Edit Modal for Parent -->
                    @include('dashboard.HealthIssues.modals.edit', ['healthIssue' => $healthIssue])
                @empty
                    <tr>
                        <td colspan="3">No HealthIssues found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('dashboard.HealthIssues.modals.create')
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
