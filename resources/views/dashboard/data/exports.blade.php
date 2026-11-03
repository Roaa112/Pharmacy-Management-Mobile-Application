@extends('admin.layout')

@section('title', 'Export history')
@section('pretitle', 'Import & export')

@section('content')
@include('dashboard.data._nav')

@if (session('download'))
    <div class="alert alert-info"><a href="{{ route('dashboard.data.exports.download', session('download')) }}" class="fw-medium"><i class="ti ti-download me-1"></i>Download the export</a></div>
@endif

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-4"><select name="entity" class="form-select" aria-label="Entity"><option value="">All data</option>
                @foreach ($entities as $key => $entity)<option value="{{ $key }}" @selected(request('entity') === $key)>{{ $entity->label() }}</option>@endforeach</select></div>
            <div class="col-md-2"><button class="btn btn-primary">Filter</button></div>
        </form>
    </div>
    @if ($runs->isEmpty())
        <x-admin.empty icon="file-export" title="No exports" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Export</th><th>Data</th><th>Format</th><th>Filters</th><th>By</th><th>Status</th><th class="text-end">Rows</th><th>Date</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td class="fw-medium">#{{ $run->id }}</td>
                        <td>{{ $entities[$run->entity]?->label() ?? $run->entity }}</td>
                        <td>{{ strtoupper($run->format) }}</td>
                        <td class="small text-secondary">@forelse ($run->filters ?? [] as $k => $v){{ $k }}={{ $v }}@if (!$loop->last), @endif @empty — @endforelse</td>
                        <td>{{ $run->admin?->name ?? '—' }}</td>
                        <td><x-admin.status :status="$run->status" />@if ($run->failure_message)<div class="small text-red">{{ \Illuminate\Support\Str::limit($run->failure_message, 80) }}</div>@endif</td>
                        <td class="text-end">{{ number_format($run->row_count) }}</td>
                        <td class="small">{{ $run->created_at->format('M j, Y H:i') }}</td>
                        <td>@if ($run->status === 'completed')<a href="{{ route('dashboard.data.exports.download', $run) }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-download"></i></a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$runs" />
    @endif
</div>
@endsection
