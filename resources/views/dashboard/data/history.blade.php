@extends('admin.layout')

@section('title', $entity->label().' · import & export history')
@section('pretitle')
    <a href="{{ $pageUrl }}" class="text-reset">{{ $entity->label() }}</a>
@endsection

@section('actions')
    <a href="{{ $pageUrl }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Back to {{ strtolower($entity->label()) }}</a>
    <x-admin.data-transfer :entities="[$entity->key()]" />
@endsection

@section('content')
@if ($imports)
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-file-import me-2 text-secondary"></i>Imports</h3>
            <div class="card-actions">
                <form method="GET" class="d-flex gap-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Status">
                        <option value="">Any status</option>
                        @foreach (['pending', 'processing', 'completed', 'completed_with_errors', 'failed', 'cancelled'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>
        @if ($imports->isEmpty())
            <x-admin.empty icon="file-import" title="No imports yet" text="Use Import on the {{ strtolower($entity->label()) }} page." />
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Import</th><th>File</th><th>By</th><th>Status</th><th class="text-end">Rows</th><th class="text-end">OK / failed</th><th class="text-end">Created / updated</th><th>Date</th><th class="w-1"></th></tr></thead>
                    <tbody>
                    @foreach ($imports as $run)
                        <tr>
                            <td><a href="{{ route('dashboard.data.imports.show', $run) }}" class="fw-medium">#{{ $run->id }}</a><div class="small text-secondary">{{ \App\Models\ImportRun::MODES[$run->mode] ?? $run->mode }}</div></td>
                            <td class="small text-break">{{ $run->original_name }}</td>
                            <td>{{ $run->admin?->name ?? '—' }}</td>
                            <td>@if ($run->awaitingConfirmation())<span class="badge bg-blue-lt">To confirm</span>@else<x-admin.status :status="$run->status" />@endif</td>
                            <td class="text-end">{{ number_format($run->total_rows) }}</td>
                            <td class="text-end">{{ number_format($run->valid_rows) }} / <span class="{{ $run->invalid_rows ? 'text-red' : '' }}">{{ number_format($run->invalid_rows) }}</span></td>
                            <td class="text-end">{{ number_format($run->created_count) }} / {{ number_format($run->updated_count) }}</td>
                            <td class="small text-nowrap">{{ $run->created_at->format('M j, Y H:i') }}</td>
                            <td><a href="{{ route('dashboard.data.imports.show', $run) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pagination :paginator="$imports" />
        @endif
    </div>
@endif

@if ($exports)
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-file-export me-2 text-secondary"></i>Exports</h3></div>
        @if ($exports->isEmpty())
            <x-admin.empty icon="file-export" title="No exports yet" text="Use Export on the {{ strtolower($entity->label()) }} page." />
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Export</th><th>Format</th><th>Filters</th><th>By</th><th>Status</th><th class="text-end">Rows</th><th>Date</th><th class="w-1"></th></tr></thead>
                    <tbody>
                    @foreach ($exports as $run)
                        <tr>
                            <td class="fw-medium">#{{ $run->id }}</td>
                            <td>{{ strtoupper($run->format) }}</td>
                            <td class="small text-secondary">@forelse ($run->filters ?? [] as $k => $v){{ str_replace('_', ' ', $k) }}: {{ $v }}@if (!$loop->last), @endif @empty All @endforelse</td>
                            <td>{{ $run->admin?->name ?? '—' }}</td>
                            <td><x-admin.status :status="$run->status" />@if ($run->failure_message)<div class="small text-red">{{ \Illuminate\Support\Str::limit($run->failure_message, 80) }}</div>@endif</td>
                            <td class="text-end">{{ number_format($run->row_count) }}</td>
                            <td class="small text-nowrap">{{ $run->created_at->format('M j, Y H:i') }}</td>
                            <td>@if ($run->status === 'completed')<a href="{{ route('dashboard.data.exports.download', $run) }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-download me-1"></i>Download</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pagination :paginator="$exports" />
        @endif
    </div>
@endif
@endsection
