@extends('admin.layout')

@section('title', 'Health issues')
@section('pretitle', 'Catalog')
@section('subtitle', 'Conditions customers can browse by in the app (e.g. diabetes).')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createhealthIssue"><i class="ti ti-plus me-1"></i>New health issue</button>
@endsection

@section('content')
<div class="card">
    @if ($healthIssues->isEmpty())
        <x-admin.empty icon="stethoscope" title="No health issues yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Health issue</th><th>Arabic name</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($healthIssues as $healthIssue)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <x-admin.thumb :src="$healthIssue->image ? asset($healthIssue->image) : null" :alt="$healthIssue->name_en" />
                                <span class="fw-medium">{{ $healthIssue->name_en }}</span>
                            </div>
                        </td>
                        <td dir="rtl" class="text-end text-md-start">{{ $healthIssue->name_ar }}</td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#edithealthIssue{{ $healthIssue->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.health_issues.destroy', $healthIssue->id)" />
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
    <x-admin.modal id="createhealthIssue" title="New health issue" :action="route('dashboard.health_issues.store')" files submit="Create">
        <x-admin.input name="name_en" label="Name (English)" required />
        <x-admin.input name="name_ar" label="Name (Arabic)" required dir="rtl" />
        <x-admin.input type="file" name="image" label="Image" accept="image/*" />
    </x-admin.modal>

    @foreach ($healthIssues as $healthIssue)
        <x-admin.modal :id="'edithealthIssue'.$healthIssue->id" title="Edit health issue" :action="route('dashboard.health_issues.update', $healthIssue->id)" method="PUT" files>
            <x-admin.input name="name_en" label="Name (English)" :value="$healthIssue->name_en" required />
            <x-admin.input name="name_ar" label="Name (Arabic)" :value="$healthIssue->name_ar" required dir="rtl" />
            <x-admin.input type="file" name="image" label="Replace image" accept="image/*" hint="Leave empty to keep the current image." />
        </x-admin.modal>
    @endforeach
@endpush
