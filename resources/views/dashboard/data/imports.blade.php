@extends('admin.layout')

@section('title', 'Import history')
@section('pretitle', 'Import & export')

@section('content')
@include('dashboard.data._nav')

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-4"><select name="entity" class="form-select" aria-label="Entity"><option value="">All data</option>
                @foreach ($entities as $key => $entity)<option value="{{ $key }}" @selected(request('entity') === $key)>{{ $entity->label() }}</option>@endforeach</select></div>
            <div class="col-md-4"><select name="status" class="form-select" aria-label="Status"><option value="">Any status</option>
                @foreach (['pending', 'processing', 'completed', 'completed_with_errors', 'failed', 'cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
            <div class="col-md-2"><button class="btn btn-primary">Filter</button></div>
        </form>
    </div>
    @if ($runs->isEmpty())
        <x-admin.empty icon="file-import" title="No imports" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Import</th><th>Data</th><th>File</th><th>By</th><th>Status</th><th class="text-end">Rows</th><th class="text-end">OK / failed</th><th class="text-end">Warnings</th><th class="text-end">Created / updated</th><th>Started</th><th>Finished</th></tr></thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td><a href="{{ route('dashboard.data.imports.show', $run) }}" class="fw-medium">#{{ $run->id }}</a></td>
                        <td>{{ $entities[$run->entity]?->label() ?? $run->entity }}<div class="small text-secondary">{{ $run->mode }}</div></td>
                        <td class="small text-break">{{ $run->original_name }}</td>
                        <td>{{ $run->admin?->name ?? '—' }}</td>
                        <td>@if ($run->awaitingConfirmation())<span class="badge bg-blue-lt">To confirm</span>@else<x-admin.status :status="$run->status" />@endif</td>
                        <td class="text-end">{{ number_format($run->total_rows) }}</td>
                        <td class="text-end">{{ number_format($run->valid_rows) }} / <span class="{{ $run->invalid_rows ? 'text-red' : '' }}">{{ number_format($run->invalid_rows) }}</span></td>
                        <td class="text-end">{{ number_format($run->warning_count) }}</td>
                        <td class="text-end">{{ number_format($run->created_count) }} / {{ number_format($run->updated_count) }}</td>
                        <td class="small">{{ ($run->started_at ?? $run->created_at)->format('M j, H:i') }}</td>
                        <td class="small">{{ $run->completed_at?->format('M j, H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$runs" />
    @endif
</div>
@endsection
